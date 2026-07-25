<?php

namespace App\Services\Mapping;

use App\Models\Employee;
use App\Models\MasterImportBatch;
use App\Models\SalesImportRow;
use App\Services\SalesMapping\SalesEnrichmentWriter;
use App\Services\SalesMapping\SalesMasterMatcher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use SplFileObject;
use Throwable;

class SalesEmployeeListImportService
{
    public const SOURCE_TYPE = 'employee_sales_list';

    /** @var list<string> */
    private const KNOWN_FIELDS = [
        'date',
        'ca_name',
        'firm_name',
        'mobile_no',
        'alternate_mobile_no',
        'email',
        'website',
        'city',
        'state_name',
        'address',
        'pincode',
        'remarks_1',
        'remarks_2',
        'call_status',
        'follow_up',
        'software',
        'sales_source',
    ];

    public function __construct(
        private readonly DataNormalizationService $normalizer,
        private readonly SalesMasterMatcher $matcher,
        private readonly SalesEnrichmentWriter $enrichment,
    ) {}

    public function directory(): string
    {
        return storage_path('app/'.trim((string) config('sales_imports.directory', 'sales-imports'), '/'));
    }

    /**
     * Discover importable CSV files (skips hidden, temp, backup, directories, unsupported).
     *
     * @return list<string> absolute paths sorted by basename
     */
    public function discoverFiles(?string $directory = null): array
    {
        $dir = $directory ?? $this->directory();
        if (! is_dir($dir)) {
            return [];
        }

        $paths = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if ($this->shouldSkipFilename($entry)) {
                continue;
            }
            $full = $dir.DIRECTORY_SEPARATOR.$entry;
            if (! is_file($full) || is_link($full)) {
                continue;
            }
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if ($ext === 'csv') {
                $paths[] = $full;
                continue;
            }
            if ($ext === 'txt' && $this->looksLikeCsv($full)) {
                $paths[] = $full;
            }
        }

        usort($paths, fn ($a, $b) => strcasecmp(basename($a), basename($b)));

        return $paths;
    }

    public function shouldSkipFilename(string $filename): bool
    {
        $base = basename($filename);
        if ($base === '' || str_starts_with($base, '.')) {
            return true;
        }
        $lower = strtolower($base);
        if (str_starts_with($lower, '~') || str_starts_with($lower, '.$')) {
            return true;
        }
        foreach (['.bak', '.tmp', '.temp', '.swp', '.part', '.numbers', '.xlsx', '.xls', '.zip'] as $suffix) {
            if (str_ends_with($lower, $suffix)) {
                return true;
            }
        }

        return (bool) preg_match('/(?:^|[\s._-])(backup|copy|old)(?:[\s._-]|$)/i', pathinfo($base, PATHINFO_FILENAME));
    }

    /**
     * Resolve employee name or return null when unsafe / unknown.
     */
    public function resolveEmployeeName(string $fileName, ?string $explicit = null): ?string
    {
        $explicit = trim((string) $explicit);
        if ($explicit !== '') {
            return mb_strtoupper($explicit);
        }

        $base = basename($fileName);
        $map = config('sales_imports.employee_map', []);
        if (is_array($map)) {
            foreach ($map as $pattern => $employee) {
                if (strcasecmp((string) $pattern, $base) === 0) {
                    $resolved = mb_strtoupper(trim((string) $employee));

                    return $resolved !== '' ? $resolved : null;
                }
            }
        }

        $stem = pathinfo($base, PATHINFO_FILENAME);
        if (preg_match('/-\s*([A-Za-z][A-Za-z0-9 .\'-]{0,60})$/', $stem, $matches) === 1) {
            $token = $this->normalizeEmployeeToken($matches[1]);
            if ($token !== null) {
                return $token;
            }
        }

        $token = $this->normalizeEmployeeToken($stem);
        if ($token !== null && mb_strlen($token) <= 40 && ! preg_match('/\s/', $token)) {
            return $token;
        }

        return null;
    }

    public function resolveEmployeeId(?string $employeeName): ?int
    {
        if ($employeeName === null || trim($employeeName) === '' || ! Schema::hasTable('employees')) {
            return null;
        }

        $needle = mb_strtoupper(trim($employeeName));
        $id = Employee::query()
            ->whereRaw('UPPER(TRIM(name)) = ?', [$needle])
            ->value('employee_id');

        return $id !== null ? (int) $id : null;
    }

    private function normalizeEmployeeToken(string $raw): ?string
    {
        $raw = trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^([A-Za-z][A-Za-z0-9\'-]*)(?:\s+SALES(?:\s+LIST)?)?$/i', $raw, $m) === 1) {
            return mb_strtoupper($m[1]);
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9\'. -]{0,40}$/', $raw) !== 1) {
            return null;
        }
        $upper = mb_strtoupper($raw);
        if (in_array($upper, ['LEADS', 'SALES', 'LIST', 'EXPORT', 'DATA', 'CSV'], true)) {
            return null;
        }

        return $upper;
    }

    public function rowFingerprint(
        string $sourceFileName,
        int $sourceRowNumber,
        ?string $normalizedCa,
        ?string $normalizedFirm,
        ?string $normalizedCity,
        ?string $mobile,
        string $employeeName,
        ?string $callDate,
    ): string {
        return hash('sha256', implode('|', [
            $sourceFileName,
            (string) $sourceRowNumber,
            (string) ($normalizedCa ?? ''),
            (string) ($normalizedFirm ?? ''),
            (string) ($normalizedCity ?? ''),
            (string) ($mobile ?? ''),
            $employeeName,
            (string) ($callDate ?? ''),
        ]));
    }

    /**
     * Import one CSV file into sales_import_rows with one master_import_batches row.
     * Never creates/updates/deletes CA Master or CA Reference.
     *
     * @return array<string, mixed>
     */
    public function importFile(string $filePath, ?string $employeeName = null, bool $forceReimport = false): array
    {
        $startedMs = (int) floor(microtime(true) * 1000);
        $fileName = basename($filePath);
        $empty = [
            'status' => 'failed',
            'file' => $fileName,
            'employee' => null,
            'import_batch_id' => null,
            'total_rows' => 0,
            'imported' => 0,
            'already_existing' => 0,
            'duplicate' => 0,
            'matched' => 0,
            'needs_review' => 0,
            'unmatched' => 0,
            'failed' => 0,
            'rejected' => 0,
            'skipped_blank' => 0,
            'skipped' => 0,
            'processing_ms' => 0,
            'reason' => null,
            'error' => null,
        ];

        if (! is_file($filePath)) {
            return array_merge($empty, ['status' => 'skipped', 'reason' => 'File not found', 'error' => 'File not found']);
        }
        if ($this->shouldSkipFilename($fileName)) {
            return array_merge($empty, ['status' => 'skipped', 'reason' => 'Unsupported or temporary file']);
        }

        $employee = $this->resolveEmployeeName($fileName, $employeeName);
        if ($employee === null || $employee === '') {
            return array_merge($empty, [
                'status' => 'skipped',
                'reason' => 'Employee name could not be determined safely',
            ]);
        }
        $empty['employee'] = $employee;
        $employeeId = $this->resolveEmployeeId($employee);

        $fileHash = hash_file('sha256', $filePath) ?: null;

        if (! $forceReimport && $fileHash !== null && Schema::hasTable('master_import_batches')) {
            $prior = MasterImportBatch::query()
                ->where('source_type', self::SOURCE_TYPE)
                ->where('file_hash', $fileHash)
                ->where('status', MasterImportBatch::STATUS_COMPLETED)
                ->orderByDesc('id')
                ->first();
            if ($prior) {
                return array_merge($empty, [
                    'status' => 'skipped',
                    'import_batch_id' => (int) $prior->id,
                    'already_existing' => (int) $prior->duplicate_count + (int) $prior->created_count,
                    'duplicate' => (int) $prior->duplicate_count + (int) $prior->created_count,
                    'reason' => 'Same file hash already imported (batch #'.$prior->id.')',
                ]);
            }
        }

        $batch = null;
        if (Schema::hasTable('master_import_batches')) {
            $batchAttrs = [
                'source_type' => self::SOURCE_TYPE,
                'source_ref' => $fileName,
                'file_name' => $fileName,
                'file_hash' => $fileHash,
                'status' => MasterImportBatch::STATUS_PROCESSING,
                'progress_stage' => 'importing',
                'progress_pct' => 0,
                'remarks' => json_encode(['employee_name' => $employee], JSON_UNESCAPED_UNICODE),
            ];
            $batch = MasterImportBatch::query()->create($batchAttrs);
            $batch->forceFill(array_filter([
                'employee_id' => $employeeId,
                'started_at' => now(),
            ], fn ($v) => $v !== null))->save();
        }

        try {
            $result = DB::transaction(function () use ($filePath, $fileName, $fileHash, $employee, $employeeId, $batch) {
                return $this->importCsvInsideTransaction(
                    $filePath,
                    $fileName,
                    $fileHash,
                    $employee,
                    $employeeId,
                    $batch?->id
                );
            });

            // Enrichment after commit of row inserts (chunked, never mutates ca_masters).
            if ($batch?->id) {
                $chunk = max(100, min(2000, (int) config('sales_imports.import.chunk_size', 500)));
                $this->enrichment->applyForBatch((int) $batch->id, $chunk);
            }
        } catch (Throwable $e) {
            if ($batch) {
                $batch->forceFill([
                    'status' => MasterImportBatch::STATUS_FAILED,
                    'progress_stage' => 'failed',
                    'progress_pct' => 100,
                    'finished_at' => now(),
                    'processing_ms' => max(0, (int) floor(microtime(true) * 1000) - $startedMs),
                    'remarks' => json_encode([
                        'employee_name' => $employee,
                        'error' => $e->getMessage(),
                    ], JSON_UNESCAPED_UNICODE),
                ])->save();
            }

            return array_merge($empty, [
                'status' => 'failed',
                'import_batch_id' => $batch?->id,
                'error' => $e->getMessage(),
                'reason' => $e->getMessage(),
                'processing_ms' => max(0, (int) floor(microtime(true) * 1000) - $startedMs),
            ]);
        }

        $processingMs = max(0, (int) floor(microtime(true) * 1000) - $startedMs);
        $duplicate = (int) $result['already_existing'];
        $skipped = (int) $result['skipped_blank'];
        $rejected = (int) $result['failed'];

        if ($batch) {
            $batch->forceFill([
                'status' => MasterImportBatch::STATUS_COMPLETED,
                'total_records' => $result['total_rows'],
                'created_count' => $result['imported'],
                'duplicate_count' => $duplicate,
                'review_count' => $result['needs_review'],
                'conflict_count' => $result['unmatched'],
                'failed_count' => $rejected,
                'matched_count' => $result['matched'],
                'unmatched_count' => $result['unmatched'],
                'rejected_count' => $rejected,
                'skipped_count' => $skipped,
                'processing_ms' => $processingMs,
                'column_map' => $result['column_map'] ?? null,
                'finished_at' => now(),
                'progress_stage' => 'completed',
                'progress_pct' => 100,
                'remarks' => json_encode([
                    'employee_name' => $employee,
                    'employee_id' => $employeeId,
                    'matched_count' => $result['matched'],
                    'needs_review_count' => $result['needs_review'],
                    'unmatched_count' => $result['unmatched'],
                    'duplicate_count' => $duplicate,
                    'skipped_count' => $skipped,
                    'rejected_count' => $rejected,
                    'ignored_count' => 0,
                    'force_reimport' => $forceReimport,
                    'processing_ms' => $processingMs,
                ], JSON_UNESCAPED_UNICODE),
            ])->save();
        }

        return array_merge($empty, $result, [
            'status' => 'completed',
            'import_batch_id' => $batch?->id,
            'employee' => $employee,
            'duplicate' => $duplicate,
            'skipped' => $skipped,
            'rejected' => $rejected,
            'processing_ms' => $processingMs,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function importCsvInsideTransaction(
        string $filePath,
        string $fileName,
        ?string $fileHash,
        string $employeeName,
        ?int $employeeId,
        ?int $batchId,
    ): array {
        $csv = new SplFileObject($filePath, 'r');
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        $headers = $csv->fgetcsv();
        if (! is_array($headers)) {
            throw new RuntimeException('The CSV header row could not be read.');
        }

        $columns = $this->buildColumnMap($headers);
        foreach (['date', 'firm_name', 'mobile_no', 'city'] as $required) {
            if (! array_key_exists($required, $columns)) {
                throw new RuntimeException("Required CSV column was not found: {$required}");
            }
        }

        $counts = [
            'total_rows' => 0,
            'imported' => 0,
            'already_existing' => 0,
            'matched' => 0,
            'needs_review' => 0,
            'unmatched' => 0,
            'failed' => 0,
            'skipped_blank' => 0,
            'column_map' => $columns,
        ];

        $hasFingerprint = Schema::hasColumn('sales_import_rows', 'row_fingerprint');
        $hasFileHashCol = Schema::hasColumn('sales_import_rows', 'source_file_hash');
        $hasCandidatesCol = Schema::hasColumn('sales_import_rows', 'match_candidates');
        $hasEmployeeIdCol = Schema::hasColumn('sales_import_rows', 'employee_id');
        $hasExtraCol = Schema::hasColumn('sales_import_rows', 'extra_columns');
        $hasConfidenceCol = Schema::hasColumn('sales_import_rows', 'confidence_tier');
        $hasEmailCol = Schema::hasColumn('sales_import_rows', 'email');
        $hasWebsiteCol = Schema::hasColumn('sales_import_rows', 'website');
        $hasStateCol = Schema::hasColumn('sales_import_rows', 'state_name');
        $hasAddressCol = Schema::hasColumn('sales_import_rows', 'address');
        $hasPincodeCol = Schema::hasColumn('sales_import_rows', 'pincode');
        $hasCallStatusCol = Schema::hasColumn('sales_import_rows', 'call_status');
        $hasFollowUpCol = Schema::hasColumn('sales_import_rows', 'follow_up');
        $hasSoftwareCol = Schema::hasColumn('sales_import_rows', 'software');
        $hasSalesSourceCol = Schema::hasColumn('sales_import_rows', 'sales_source');
        $hasNormMobileCol = Schema::hasColumn('sales_import_rows', 'normalized_mobile');
        $hasNormEmailCol = Schema::hasColumn('sales_import_rows', 'normalized_email');
        $hasDupOfCol = Schema::hasColumn('sales_import_rows', 'duplicate_of_row_id');
        $hasReferenceCol = Schema::hasColumn('sales_import_rows', 'matched_reference_firm_id');

        $existingFingerprints = [];
        $existingFingerprintToId = [];
        $existingRowNumbers = SalesImportRow::query()
            ->where('source_file_name', $fileName)
            ->pluck('id', 'source_row_number')
            ->all();

        if ($hasFingerprint) {
            $existingFingerprintToId = SalesImportRow::query()
                ->where('source_file_name', $fileName)
                ->whereNotNull('row_fingerprint')
                ->pluck('id', 'row_fingerprint')
                ->all();
            $existingFingerprints = array_fill_keys(array_keys($existingFingerprintToId), true);
        }

        $insertBuffer = [];
        $sourceRowNumber = 1;
        $now = now()->format('Y-m-d H:i:s');
        $chunkSize = max(100, min(2000, (int) config('sales_imports.import.chunk_size', 500)));

        while (! $csv->eof()) {
            $row = $csv->fgetcsv();
            $sourceRowNumber++;

            if (! is_array($row) || $this->isBlankRow($row)) {
                $counts['skipped_blank']++;
                continue;
            }

            try {
                $rawCaName = $this->value($row, $columns, 'ca_name');
                $rawFirmName = $this->value($row, $columns, 'firm_name');
                $rawCity = $this->value($row, $columns, 'city');
                $mobile = $this->normalizer->phone($this->value($row, $columns, 'mobile_no'));
                $email = $this->normalizer->email($this->value($row, $columns, 'email'));
                $callDate = $this->parseDate($this->value($row, $columns, 'date'));

                $mapping = $this->matcher->match([
                    'ca_name' => $rawCaName,
                    'firm_name' => $rawFirmName,
                    'city_name' => $rawCity,
                    'mobile_no' => $mobile,
                    'email' => $email,
                ]);
                $normalizedCa = $mapping['normalized_ca_name']
                    ?? ($this->normalizer->caName($rawCaName) !== null
                        ? mb_strtoupper((string) $this->normalizer->caName($rawCaName))
                        : null);

                $fingerprint = $this->rowFingerprint(
                    $fileName,
                    $sourceRowNumber,
                    $normalizedCa,
                    $mapping['normalized_firm_name'],
                    $mapping['normalized_city'],
                    $mobile,
                    $employeeName,
                    $callDate,
                );

                $dupOfId = $existingFingerprintToId[$fingerprint] ?? ($existingRowNumbers[$sourceRowNumber] ?? null);
                $already = isset($existingFingerprints[$fingerprint])
                    || isset($existingRowNumbers[$sourceRowNumber]);

                $counts['total_rows']++;
                if ($already) {
                    $counts['already_existing']++;
                    if ($hasFingerprint) {
                        $existingFingerprints[$fingerprint] = true;
                    }
                    continue;
                }

                $status = (string) ($mapping['status'] ?? 'unmatched');
                if (! in_array($status, ['matched', 'needs_review', 'unmatched'], true)) {
                    $status = 'unmatched';
                }

                $counts['imported']++;
                $counts[$status] = ($counts[$status] ?? 0) + 1;

                $rawPayload = [];
                $extraColumns = [];
                $mappedIndexes = array_flip(array_values($columns));
                foreach ($headers as $index => $header) {
                    $name = trim((string) $header);
                    if ($name === '') {
                        $name = 'column_'.$index;
                    }
                    $cell = isset($row[$index]) ? trim((string) $row[$index]) : null;
                    $rawPayload[$name] = $cell !== '' ? $cell : null;
                    if (! isset($mappedIndexes[(int) $index])) {
                        $extraColumns[$name] = $cell !== '' ? $cell : null;
                    }
                }

                $insert = [
                    'import_batch_id' => $batchId,
                    'source_file_name' => $fileName,
                    'source_sheet_name' => $employeeName,
                    'source_row_number' => $sourceRowNumber,
                    'employee_name' => $employeeName,
                    'call_date' => $callDate,
                    'ca_name' => $rawCaName,
                    'firm_name' => $rawFirmName,
                    'mobile_no' => $mobile,
                    'alternate_mobile_no' => $this->normalizer->phone($this->value($row, $columns, 'alternate_mobile_no')),
                    'city_name' => $rawCity,
                    'remarks_1' => $this->value($row, $columns, 'remarks_1'),
                    'remarks_2' => $this->value($row, $columns, 'remarks_2'),
                    'normalized_ca_name' => $normalizedCa,
                    'normalized_firm_name' => $mapping['normalized_firm_name'],
                    'normalized_city' => $mapping['normalized_city'],
                    'matched_ca_id' => $mapping['ca_id'],
                    'mapping_status' => $status,
                    'matched_on' => $mapping['matched_on'],
                    'match_score' => $mapping['score'],
                    'review_reason' => $mapping['reason'],
                    'mapped_at' => $now,
                    'raw_payload' => json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($hasFileHashCol) {
                    $insert['source_file_hash'] = $fileHash;
                }
                if ($hasFingerprint) {
                    $insert['row_fingerprint'] = $fingerprint;
                    $existingFingerprints[$fingerprint] = true;
                }
                $existingRowNumbers[$sourceRowNumber] = true;

                if ($hasReferenceCol) {
                    $insert['matched_reference_firm_id'] = $mapping['matched_reference_firm_id'] ?? null;
                }
                if ($hasCandidatesCol) {
                    $insert['match_candidates'] = json_encode(
                        $mapping['candidates'] ?? [],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    );
                }
                if ($hasEmployeeIdCol) {
                    $insert['employee_id'] = $employeeId;
                }
                if ($hasEmailCol) {
                    $insert['email'] = $email;
                }
                if ($hasWebsiteCol) {
                    $insert['website'] = $this->value($row, $columns, 'website');
                }
                if ($hasStateCol) {
                    $insert['state_name'] = $this->value($row, $columns, 'state_name');
                }
                if ($hasAddressCol) {
                    $insert['address'] = $this->value($row, $columns, 'address');
                }
                if ($hasPincodeCol) {
                    $insert['pincode'] = $this->value($row, $columns, 'pincode');
                }
                if ($hasCallStatusCol) {
                    $insert['call_status'] = $this->value($row, $columns, 'call_status');
                }
                if ($hasFollowUpCol) {
                    $insert['follow_up'] = $this->value($row, $columns, 'follow_up');
                }
                if ($hasSoftwareCol) {
                    $insert['software'] = $this->value($row, $columns, 'software');
                }
                if ($hasSalesSourceCol) {
                    $insert['sales_source'] = $this->value($row, $columns, 'sales_source');
                }
                if ($hasNormMobileCol) {
                    $insert['normalized_mobile'] = $mapping['normalized_mobile'] ?? $mobile;
                }
                if ($hasNormEmailCol) {
                    $insert['normalized_email'] = $mapping['normalized_email'] ?? $email;
                }
                if ($hasExtraCol) {
                    $insert['extra_columns'] = json_encode($extraColumns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                if ($hasConfidenceCol) {
                    $insert['confidence_tier'] = $mapping['confidence_tier'] ?? $mapping['match_tier'] ?? null;
                }
                if ($hasDupOfCol && $dupOfId) {
                    $insert['duplicate_of_row_id'] = $dupOfId;
                }

                $insertBuffer[] = $insert;
                if (count($insertBuffer) >= $chunkSize) {
                    DB::table('sales_import_rows')->insert($insertBuffer);
                    $insertBuffer = [];
                }
            } catch (Throwable $rowError) {
                $counts['failed']++;
                $counts['total_rows']++;
                report($rowError);
            }
        }

        if ($insertBuffer !== []) {
            DB::table('sales_import_rows')->insert($insertBuffer);
        }

        if ($batchId !== null) {
            $attach = ['import_batch_id' => $batchId, 'updated_at' => $now];
            if ($hasFileHashCol && $fileHash) {
                $attach['source_file_hash'] = $fileHash;
            }
            SalesImportRow::query()
                ->where('source_file_name', $fileName)
                ->whereNull('import_batch_id')
                ->update($attach);
        }

        return $counts;
    }

    /**
     * @param  list<mixed>  $headers
     * @return array<string, int>
     */
    public function buildColumnMap(array $headers): array
    {
        $map = [];

        foreach ($headers as $index => $header) {
            $normalized = $this->normalizeHeader((string) $header);

            $field = match ($normalized) {
                'date', 'call_date' => 'date',
                'ca_name', 'caname', 'name', 'ca' => 'ca_name',
                'firm_name', 'firmname', 'firm' => 'firm_name',
                'number', 'mobile', 'mobile_no', 'phone', 'phone_number', 'contact' => 'mobile_no',
                'alternate_mobile_no', 'alternate_number', 'alt_mobile', 'alternate_mobile', 'alt_number' => 'alternate_mobile_no',
                'email', 'email_id', 'email_address', 'mail' => 'email',
                'website', 'web', 'url' => 'website',
                'city', 'city_name' => 'city',
                'state', 'state_name' => 'state_name',
                'address', 'full_address', 'addr' => 'address',
                'pincode', 'pin', 'pin_code', 'postal_code', 'zip' => 'pincode',
                'remarks_1', 'remark_1', 'remarks1', 'remark1', 'remarks', 'remark', 'notes', 'note' => 'remarks_1',
                'remarks_2', 'remark_2', 'remarks2', 'remark2' => 'remarks_2',
                'call_status', 'status', 'outcome' => 'call_status',
                'follow_up', 'followup', 'follow_up_date', 'next_follow_up' => 'follow_up',
                'software', 'existing_software', 'product' => 'software',
                'sales_source', 'source', 'lead_source' => 'sales_source',
                default => null,
            };

            if ($field !== null && ! isset($map[$field])) {
                $map[$field] = (int) $index;
            }
        }

        return $map;
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        $header = mb_strtolower(trim($header));
        $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? $header;

        return trim($header, '_');
    }

    /**
     * @param  list<mixed>  $row
     * @param  array<string, int>  $columns
     */
    private function value(array $row, array $columns, string $field): ?string
    {
        if (! isset($columns[$field])) {
            return null;
        }
        $value = trim((string) ($row[$columns[$field]] ?? ''));

        return $value !== '' ? $value : null;
    }

    /** @param  list<mixed>  $row */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function parseDate(?string $date): ?string
    {
        if ($date === null) {
            return null;
        }
        foreach (['d-m-Y', 'd/m/Y', 'Y-m-d', 'd.m.Y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, trim($date));
                if ($parsed !== false) {
                    return $parsed->format('Y-m-d');
                }
            } catch (Throwable) {
            }
        }

        return null;
    }

    private function looksLikeCsv(string $path): bool
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return false;
        }
        $line = fgets($handle);
        fclose($handle);
        if ($line === false || $line === '') {
            return false;
        }

        return substr_count($line, ',') >= 3 || substr_count($line, "\t") >= 3;
    }

    public function resolveFilePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }
        $projectPath = base_path($path);
        if (is_file($projectPath)) {
            return $projectPath;
        }

        return $this->directory().DIRECTORY_SEPARATOR.$path;
    }
}
