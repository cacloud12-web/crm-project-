<?php

namespace App\Services\Bulk;

use App\Models\CaAddress;
use App\Models\CaFirm;
use App\Models\CaPartner;
use App\Models\CaReferenceImportBatch;
use App\Models\CaReferenceImportRow;
use App\Services\Mapping\DataNormalizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Direct Excel/CSV → ca_reference importer (never routes through OCR).
 * Chunked, transactional, idempotent firm/CA/city upserts with batch audit.
 */
class BulkCaReferenceImportService
{
    public const DEFAULT_CHUNK_SIZE = 1000;

    /** @var array<string, string> */
    private const COLUMN_ALIASES = [
        'firm_name' => 'firm_name',
        'firm name' => 'firm_name',
        'firmname' => 'firm_name',
        'name of firm' => 'firm_name',
        'ca_name' => 'ca_name',
        'ca name' => 'ca_name',
        'caname' => 'ca_name',
        'partner_name' => 'ca_name',
        'partner name' => 'ca_name',
        'member name' => 'ca_name',
        'chartered accountant' => 'ca_name',
        'city' => 'city',
        'city name' => 'city',
        'town' => 'city',
    ];

    public function __construct(
        private readonly BulkImportFileParser $fileParser,
        private readonly DataNormalizationService $normalizer,
    ) {}

    /**
     * @return array{
     *   batch_id: int|null,
     *   dry_run: bool,
     *   source_file: string,
     *   reconciliation: array<string, int|bool|string|null>,
     *   rows: list<array<string, mixed>>
     * }
     */
    public function import(string $path, bool $dryRun = false, int $chunkSize = self::DEFAULT_CHUNK_SIZE, bool $persistRowLogs = true): array
    {
        $chunkSize = max(100, min(5000, $chunkSize));
        $absolute = realpath($path) ?: $path;
        if (! is_file($absolute)) {
            throw new RuntimeException('Import file not found: '.$path);
        }

        $this->assertReferenceSchema();

        $parsed = $this->fileParser->parsePath($absolute);
        $mappedRows = $this->mapSourceRows($parsed['rows'] ?? []);
        $sourceFile = basename($absolute);
        $fileHash = hash_file('sha256', $absolute) ?: null;

        $batch = null;
        if (! $dryRun || $persistRowLogs) {
            $batch = CaReferenceImportBatch::query()->create([
                'source_file' => $sourceFile,
                'source_file_hash' => $fileHash,
                'status' => 'processing',
                'dry_run' => $dryRun,
                'chunk_size' => $chunkSize,
                'source_rows' => count($mappedRows),
                'started_at' => now(),
            ]);
        }

        $stats = [
            'source_rows' => count($mappedRows),
            'imported_firms' => 0,
            'imported_partners' => 0,
            'imported_cities' => 0,
            'duplicates' => 0,
            'skipped' => 0,
            'failed' => 0,
            'reused_firms' => 0,
            'success_rows' => 0,
        ];

        /** @var array<string, int> $firmCache normalized_firm => firm_id */
        $firmCache = [];
        /** @var array<string, true> $partnerCache firmId|normPartner => true */
        $partnerCache = [];
        /** @var array<string, true> $cityCache firmId|normCity => true */
        $cityCache = [];

        $rowLogs = [];
        $detailRows = [];

        try {
            foreach (array_chunk($mappedRows, $chunkSize, true) as $chunk) {
                $chunkResult = $this->processChunk(
                    $chunk,
                    $dryRun,
                    $sourceFile,
                    $batch?->id,
                    $firmCache,
                    $partnerCache,
                    $cityCache,
                    $persistRowLogs && $batch !== null,
                );

                $stats['imported_firms'] += $chunkResult['imported_firms'];
                $stats['imported_partners'] += $chunkResult['imported_partners'];
                $stats['imported_cities'] += $chunkResult['imported_cities'];
                $stats['duplicates'] += $chunkResult['duplicates'];
                $stats['skipped'] += $chunkResult['skipped'];
                $stats['failed'] += $chunkResult['failed'];
                $stats['reused_firms'] += $chunkResult['reused_firms'];
                $stats['success_rows'] += $chunkResult['success_rows'];
                $rowLogs = array_merge($rowLogs, $chunkResult['row_logs']);
                $detailRows = array_merge($detailRows, $chunkResult['details']);
            }

            $reconciliation = $this->buildReconciliation($stats, $dryRun, $sourceFile, $batch?->id);

            if ($batch) {
                $batch->update([
                    'status' => 'completed',
                    'imported_firms' => $stats['imported_firms'],
                    'imported_partners' => $stats['imported_partners'],
                    'imported_cities' => $stats['imported_cities'],
                    'duplicate_count' => $stats['duplicates'],
                    'skipped_count' => $stats['skipped'],
                    'failed_count' => $stats['failed'],
                    'reused_firms' => $stats['reused_firms'],
                    'reconciliation' => $reconciliation,
                    'finished_at' => now(),
                ]);
            }

            return [
                'batch_id' => $batch?->id,
                'dry_run' => $dryRun,
                'source_file' => $sourceFile,
                'reconciliation' => $reconciliation,
                'rows' => $detailRows,
            ];
        } catch (Throwable $e) {
            if ($batch) {
                $batch->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'finished_at' => now(),
                    'reconciliation' => $this->buildReconciliation($stats, $dryRun, $sourceFile, $batch->id),
                ]);
            }
            throw $e;
        }
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<array{row_number: int, firm_name: string, ca_name: string, city: string}>
     */
    private function mapSourceRows(array $rows): array
    {
        $mapped = [];
        $rowNumber = 1;
        foreach ($rows as $row) {
            $rowNumber++;
            $fields = $this->extractFields($row);
            $mapped[] = [
                'row_number' => $rowNumber,
                'firm_name' => $fields['firm_name'],
                'ca_name' => $fields['ca_name'],
                'city' => $fields['city'],
            ];
        }

        return $mapped;
    }

    /**
     * @param  array<string, string>  $row
     * @return array{firm_name: string, ca_name: string, city: string}
     */
    private function extractFields(array $row): array
    {
        $out = ['firm_name' => '', 'ca_name' => '', 'city' => ''];
        foreach ($row as $header => $value) {
            $key = self::COLUMN_ALIASES[$this->normalizeHeader((string) $header)] ?? null;
            if ($key === null) {
                continue;
            }
            $out[$key] = trim((string) $value);
        }

        return $out;
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        $header = strtolower(trim($header));
        $header = preg_replace('/[_\-]+/', ' ', $header) ?? $header;
        $header = preg_replace('/\s+/', ' ', $header) ?? $header;

        return $header;
    }

    /**
     * @param  array<int, array{row_number: int, firm_name: string, ca_name: string, city: string}>  $chunk
     * @param  array<string, int>  $firmCache
     * @param  array<string, true>  $partnerCache
     * @param  array<string, true>  $cityCache
     * @return array{
     *   imported_firms: int,
     *   imported_partners: int,
     *   imported_cities: int,
     *   duplicates: int,
     *   skipped: int,
     *   failed: int,
     *   reused_firms: int,
     *   success_rows: int,
     *   row_logs: list<array<string, mixed>>,
     *   details: list<array<string, mixed>>
     * }
     */
    private function processChunk(
        array $chunk,
        bool $dryRun,
        string $sourceFile,
        ?int $batchId,
        array &$firmCache,
        array &$partnerCache,
        array &$cityCache,
        bool $persistRowLogs,
    ): array {
        $result = [
            'imported_firms' => 0,
            'imported_partners' => 0,
            'imported_cities' => 0,
            'duplicates' => 0,
            'skipped' => 0,
            'failed' => 0,
            'reused_firms' => 0,
            'success_rows' => 0,
            'row_logs' => [],
            'details' => [],
        ];

        $this->warmCachesForChunk($chunk, $firmCache, $partnerCache, $cityCache);

        $runner = function () use (
            $chunk, $dryRun, $sourceFile, $batchId, &$firmCache, &$partnerCache, &$cityCache, $persistRowLogs, &$result
        ): void {
            foreach ($chunk as $row) {
                $detail = $this->processRow($row, $dryRun, $sourceFile, $batchId, $firmCache, $partnerCache, $cityCache);
                $result['details'][] = $detail;

                match ($detail['status']) {
                    CaReferenceImportRow::STATUS_SUCCESS => $result['success_rows']++,
                    CaReferenceImportRow::STATUS_DUPLICATE => $result['duplicates']++,
                    CaReferenceImportRow::STATUS_SKIPPED => $result['skipped']++,
                    default => $result['failed']++,
                };

                if (! empty($detail['firm_created'])) {
                    $result['imported_firms']++;
                } elseif (! empty($detail['firm_reused'])) {
                    $result['reused_firms']++;
                }
                if (! empty($detail['partner_created'])) {
                    $result['imported_partners']++;
                }
                if (! empty($detail['city_created'])) {
                    $result['imported_cities']++;
                }
                if ($detail['status'] === CaReferenceImportRow::STATUS_SUCCESS
                    && ((! empty($detail['partner_duplicate'])) || (! empty($detail['city_duplicate'])))) {
                    $result['duplicates']++;
                }

                if ($persistRowLogs && $batchId) {
                    $result['row_logs'][] = [
                        'batch_id' => $batchId,
                        'row_number' => $detail['row_number'],
                        'source_file' => $sourceFile,
                        'raw_firm_name' => $detail['raw_firm_name'],
                        'raw_ca_name' => $detail['raw_ca_name'],
                        'raw_city' => $detail['raw_city'],
                        'normalized_firm_name' => $detail['normalized_firm_name'],
                        'normalized_ca_name' => $detail['normalized_ca_name'],
                        'normalized_city' => $detail['normalized_city'],
                        'firm_id' => $detail['firm_id'],
                        'partner_id' => $detail['partner_id'],
                        'address_id' => $detail['address_id'],
                        'status' => $detail['status'],
                        'is_duplicate' => (bool) ($detail['is_duplicate'] ?? false),
                        'failure_reason' => $detail['failure_reason'],
                        'details' => json_encode([
                            'firm_created' => (bool) ($detail['firm_created'] ?? false),
                            'firm_reused' => (bool) ($detail['firm_reused'] ?? false),
                            'partner_created' => (bool) ($detail['partner_created'] ?? false),
                            'partner_duplicate' => (bool) ($detail['partner_duplicate'] ?? false),
                            'city_created' => (bool) ($detail['city_created'] ?? false),
                            'city_duplicate' => (bool) ($detail['city_duplicate'] ?? false),
                            'city_missing' => (bool) ($detail['city_missing'] ?? false),
                            'dry_run' => $dryRun,
                        ], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            if ($persistRowLogs && $result['row_logs'] !== []) {
                foreach (array_chunk($result['row_logs'], 500) as $logChunk) {
                    CaReferenceImportRow::query()->insert($logChunk);
                }
                $result['row_logs'] = [];
            }
        };

        if ($dryRun) {
            $runner();
        } else {
            DB::connection('ca_reference')->transaction($runner);
        }

        return $result;
    }

    /**
     * @param  array{row_number: int, firm_name: string, ca_name: string, city: string}  $row
     * @param  array<string, int>  $firmCache
     * @param  array<string, true>  $partnerCache
     * @param  array<string, true>  $cityCache
     * @return array<string, mixed>
     */
    private function processRow(
        array $row,
        bool $dryRun,
        string $sourceFile,
        ?int $batchId,
        array &$firmCache,
        array &$partnerCache,
        array &$cityCache,
    ): array {
        $rawFirm = trim((string) $row['firm_name']);
        $rawCa = trim((string) $row['ca_name']);
        $rawCity = trim((string) $row['city']);
        $normFirm = $this->normalizer->firmName($rawFirm !== '' ? $rawFirm : null);
        $normCa = $this->normalizer->caName($rawCa !== '' ? $rawCa : null);
        $normCity = $this->normalizer->city($rawCity !== '' ? $rawCity : null);

        $base = [
            'row_number' => $row['row_number'],
            'source_file' => $sourceFile,
            'batch_id' => $batchId,
            'raw_firm_name' => $rawFirm !== '' ? $rawFirm : null,
            'raw_ca_name' => $rawCa !== '' ? $rawCa : null,
            'raw_city' => $rawCity !== '' ? $rawCity : null,
            'normalized_firm_name' => $normFirm,
            'normalized_ca_name' => $normCa,
            'normalized_city' => $normCity,
            'firm_id' => null,
            'partner_id' => null,
            'address_id' => null,
            'firm_created' => false,
            'firm_reused' => false,
            'partner_created' => false,
            'partner_duplicate' => false,
            'city_created' => false,
            'city_duplicate' => false,
            'city_missing' => $normCity === null || $normCity === '',
            'full_duplicate' => false,
            'is_duplicate' => false,
            'failure_reason' => null,
            'status' => CaReferenceImportRow::STATUS_FAILED,
        ];

        if ($normFirm === null || $normFirm === '' || mb_strlen($normFirm) < 2) {
            $base['failure_reason'] = 'missing_or_invalid_firm_name';
            $base['status'] = CaReferenceImportRow::STATUS_SKIPPED;

            return $base;
        }
        if ($normCa === null || $normCa === '' || mb_strlen($normCa) < 2) {
            $base['failure_reason'] = 'missing_or_invalid_ca_name';
            $base['status'] = CaReferenceImportRow::STATUS_SKIPPED;

            return $base;
        }

        $firmId = $firmCache[$normFirm] ?? null;
        $firmCreated = false;
        $firmReused = false;

        if ($firmId === null) {
            if ($dryRun) {
                $firmId = -1 * (crc32($normFirm) ?: 1);
                $firmCreated = true;
                $firmCache[$normFirm] = $firmId;
            } else {
                $firm = CaFirm::query()->create([
                    'firm_name' => $rawFirm,
                    'normalized_firm_name' => $normFirm,
                    'city' => $rawCity !== '' ? $rawCity : null,
                    'status' => 'active',
                    'partner_count' => 0,
                ]);
                $firmId = (int) $firm->id;
                $firmCache[$normFirm] = $firmId;
                $firmCreated = true;
            }
        } else {
            $firmReused = true;
        }

        $partnerKey = $firmId.'|'.$normCa;
        $partnerCreated = false;
        $partnerDuplicate = false;
        $partnerId = null;

        if (isset($partnerCache[$partnerKey])) {
            $partnerDuplicate = true;
        } elseif ($dryRun) {
            $partnerCache[$partnerKey] = true;
            $partnerCreated = true;
            $partnerId = null;
        } else {
            $partner = CaPartner::query()->create([
                'firm_id' => $firmId,
                'partner_name' => $rawCa,
                'normalized_partner_name' => $normCa,
                'status' => 'active',
            ]);
            $partnerId = (int) $partner->id;
            $partnerCache[$partnerKey] = true;
            $partnerCreated = true;
            CaFirm::query()->whereKey($firmId)->increment('partner_count');
        }

        $cityCreated = false;
        $cityDuplicate = false;
        $addressId = null;
        $cityMissing = $normCity === null || $normCity === '';

        if (! $cityMissing) {
            $cityKey = $firmId.'|'.$normCity;
            if (isset($cityCache[$cityKey])) {
                $cityDuplicate = true;
            } elseif ($dryRun) {
                $cityCache[$cityKey] = true;
                $cityCreated = true;
            } else {
                $address = CaAddress::query()->create([
                    'firm_id' => $firmId,
                    'address_line_1' => $rawCity,
                    'city' => $rawCity,
                    'normalized_city' => $normCity,
                    'country' => 'India',
                ]);
                $addressId = (int) $address->id;
                $cityCache[$cityKey] = true;
                $cityCreated = true;
                if (! $firmCreated) {
                    CaFirm::query()->whereKey($firmId)->whereNull('city')->update(['city' => $rawCity]);
                }
            }
        }

        $fullDuplicate = $firmReused && $partnerDuplicate && ($cityMissing || $cityDuplicate);
        $isDuplicate = $fullDuplicate || $partnerDuplicate || $cityDuplicate;

        $base['firm_id'] = $firmId > 0 ? $firmId : null;
        $base['partner_id'] = $partnerId;
        $base['address_id'] = $addressId;
        $base['firm_created'] = $firmCreated;
        $base['firm_reused'] = $firmReused;
        $base['partner_created'] = $partnerCreated;
        $base['partner_duplicate'] = $partnerDuplicate;
        $base['city_created'] = $cityCreated;
        $base['city_duplicate'] = $cityDuplicate;
        $base['city_missing'] = $cityMissing;
        $base['full_duplicate'] = $fullDuplicate;
        $base['is_duplicate'] = $isDuplicate;

        if ($fullDuplicate) {
            $base['status'] = CaReferenceImportRow::STATUS_DUPLICATE;
            $base['failure_reason'] = 'duplicate_firm_ca_city';
        } elseif ($partnerDuplicate && $cityMissing) {
            $base['status'] = CaReferenceImportRow::STATUS_DUPLICATE;
            $base['failure_reason'] = 'duplicate_ca_under_firm';
        } elseif ($partnerDuplicate && $cityDuplicate) {
            $base['status'] = CaReferenceImportRow::STATUS_DUPLICATE;
            $base['failure_reason'] = 'duplicate_firm_ca_city';
        } elseif ($partnerDuplicate && $cityCreated) {
            $base['status'] = CaReferenceImportRow::STATUS_SUCCESS;
            $base['failure_reason'] = 'partner_duplicate_city_added';
        } else {
            $base['status'] = CaReferenceImportRow::STATUS_SUCCESS;
            if ($cityMissing) {
                $base['failure_reason'] = 'imported_without_city';
            }
        }

        return $base;
    }

    /**
     * @param  array<int, array{row_number: int, firm_name: string, ca_name: string, city: string}>  $chunk
     * @param  array<string, int>  $firmCache
     * @param  array<string, true>  $partnerCache
     * @param  array<string, true>  $cityCache
     */
    private function warmCachesForChunk(array $chunk, array &$firmCache, array &$partnerCache, array &$cityCache): void
    {
        $neededFirms = [];
        foreach ($chunk as $row) {
            $normFirm = $this->normalizer->firmName(trim((string) $row['firm_name']) !== '' ? trim((string) $row['firm_name']) : null);
            if ($normFirm !== null && $normFirm !== '' && ! isset($firmCache[$normFirm])) {
                $neededFirms[$normFirm] = true;
            }
        }
        if ($neededFirms === []) {
            return;
        }

        $hasNormFirm = Schema::connection('ca_reference')->hasColumn('ca_firms', 'normalized_firm_name');
        $names = array_keys($neededFirms);
        foreach (array_chunk($names, 500) as $nameChunk) {
            $query = CaFirm::query()->select(['id', 'firm_name', 'normalized_firm_name']);
            if ($hasNormFirm) {
                $query->whereIn('normalized_firm_name', $nameChunk);
            } else {
                $placeholders = implode(',', array_fill(0, count($nameChunk), '?'));
                $query->whereRaw('UPPER(TRIM(firm_name)) IN ('.$placeholders.')', array_map('mb_strtoupper', $nameChunk));
            }
            foreach ($query->get() as $firm) {
                $key = $hasNormFirm
                    ? (string) ($firm->normalized_firm_name ?: $this->normalizer->firmName($firm->firm_name))
                    : (string) $this->normalizer->firmName($firm->firm_name);
                if ($key === '') {
                    continue;
                }
                $firmCache[$key] = (int) $firm->id;
            }
        }

        $firmIds = array_values(array_unique(array_intersect_key($firmCache, $neededFirms)));
        if ($firmIds === []) {
            return;
        }

        $hasNormPartner = Schema::connection('ca_reference')->hasColumn('ca_partners', 'normalized_partner_name');
        $hasNormCity = Schema::connection('ca_reference')->hasColumn('ca_addresses', 'normalized_city');

        foreach (array_chunk($firmIds, 500) as $idChunk) {
            $partners = CaPartner::query()->whereIn('firm_id', $idChunk)->get(['id', 'firm_id', 'partner_name', 'normalized_partner_name']);
            foreach ($partners as $partner) {
                $norm = $hasNormPartner
                    ? (string) ($partner->normalized_partner_name ?: $this->normalizer->caName($partner->partner_name))
                    : (string) $this->normalizer->caName($partner->partner_name);
                if ($norm !== '') {
                    $partnerCache[(int) $partner->firm_id.'|'.$norm] = true;
                }
            }

            $addresses = CaAddress::query()->whereIn('firm_id', $idChunk)->get(['id', 'firm_id', 'city', 'normalized_city']);
            foreach ($addresses as $address) {
                $norm = $hasNormCity
                    ? (string) ($address->normalized_city ?: $this->normalizer->city($address->city))
                    : (string) $this->normalizer->city($address->city);
                if ($norm !== '') {
                    $cityCache[(int) $address->firm_id.'|'.$norm] = true;
                }
            }
        }
    }

    /**
     * @param  array<string, int>  $stats
     * @return array<string, int|bool|string|null>
     */
    private function buildReconciliation(array $stats, bool $dryRun, string $sourceFile, ?int $batchId): array
    {
        return [
            'batch_id' => $batchId,
            'source_file' => $sourceFile,
            'dry_run' => $dryRun,
            'source_rows' => $stats['source_rows'],
            'imported_firms' => $stats['imported_firms'],
            'imported_ca_names' => $stats['imported_partners'],
            'imported_cities' => $stats['imported_cities'],
            'duplicates' => $stats['duplicates'],
            'skipped' => $stats['skipped'],
            'failed' => $stats['failed'],
            'reused_firms' => $stats['reused_firms'],
            'success_rows' => $stats['success_rows'],
        ];
    }

    private function assertReferenceSchema(): void
    {
        $schema = Schema::connection('ca_reference');
        foreach (['ca_firms', 'ca_partners', 'ca_addresses'] as $table) {
            if (! $schema->hasTable($table)) {
                throw new RuntimeException("Required ca_reference table missing: {$table}. Run: php artisan migrate --database=ca_reference --path=database/migrations/ca_reference --force");
            }
        }
        if (! $schema->hasTable('ca_reference_import_batches') || ! $schema->hasColumn('ca_firms', 'normalized_firm_name')) {
            throw new RuntimeException('CA reference import columns/tables missing. Run: php artisan migrate --database=ca_reference --path=database/migrations/ca_reference --force');
        }
    }
}
