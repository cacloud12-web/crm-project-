<?php

namespace App\Services\Mapping;

use App\Models\MasterImportBatch;
use App\Models\SalesImportRow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use SplFileObject;
use Throwable;

class SalesEmployeeListImportService
{
    public const SOURCE_TYPE = 'employee_sales_list';

    public function __construct(
        private readonly DataNormalizationService $normalizer,
        private readonly SalesImportMatchingService $matcher,
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
        // "CA CloudDesk Leads - ANKIT" / "… - Rahul"
        if (preg_match('/-\s*([A-Za-z][A-Za-z0-9 .\'-]{0,60})$/', $stem, $matches) === 1) {
            $token = $this->normalizeEmployeeToken($matches[1]);
            if ($token !== null) {
                return $token;
            }
        }

        // Simple "SIMRAN.csv" or "RAHUL SALES LIST.csv" → RAHUL
        $token = $this->normalizeEmployeeToken($stem);
        if ($token !== null && mb_strlen($token) <= 40 && ! preg_match('/\s/', $token)) {
            return $token;
        }

        return null;
    }

    private function normalizeEmployeeToken(string $raw): ?string
    {
        $raw = trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);
        if ($raw === '') {
            return null;
        }
        // Strip trailing noise like "SALES LIST" when the first word is a clear name.
        if (preg_match('/^([A-Za-z][A-Za-z0-9\'-]*)(?:\s+SALES(?:\s+LIST)?)?$/i', $raw, $m) === 1) {
            return mb_strtoupper($m[1]);
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9\'. -]{0,40}$/', $raw) !== 1) {
            return null;
        }
        // Reject overly generic stems.
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
     * One DB transaction per file. Never creates/updates/deletes CA Master or CA Reference.
     *
     * @return array{
     *   status: string,
     *   file: string,
     *   employee: string|null,
     *   import_batch_id: int|null,
     *   total_rows: int,
     *   imported: int,
     *   already_existing: int,
     *   matched: int,
     *   needs_review: int,
     *   unmatched: int,
     *   failed: int,
     *   skipped_blank: int,
     *   reason: string|null,
     *   error: string|null
     * }
     */
    public function importFile(string $filePath, ?string $employeeName = null, bool $forceReimport = false): array
    {
        $fileName = basename($filePath);
        $empty = [
            'status' => 'failed',
            'file' => $fileName,
            'employee' => null,
            'import_batch_id' => null,
            'total_rows' => 0,
            'imported' => 0,
            'already_existing' => 0,
            'matched' => 0,
            'needs_review' => 0,
            'unmatched' => 0,
            'failed' => 0,
            'skipped_blank' => 0,
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
                    'reason' => 'Same file hash already imported (batch #'.$prior->id.')',
                ]);
            }
        }

        $batch = null;
        if (Schema::hasTable('master_import_batches')) {
            $batch = MasterImportBatch::query()->create([
                'source_type' => self::SOURCE_TYPE,
                'source_ref' => $fileName,
                'file_name' => $fileName,
                'file_hash' => $fileHash,
                'status' => MasterImportBatch::STATUS_PROCESSING,
                'progress_stage' => 'importing',
                'progress_pct' => 0,
                'remarks' => json_encode(['employee_name' => $employee], JSON_UNESCAPED_UNICODE),
            ]);
        }

        try {
            $result = DB::transaction(function () use ($filePath, $fileName, $fileHash, $employee, $batch) {
                return $this->importCsvInsideTransaction($filePath, $fileName, $fileHash, $employee, $batch?->id);
            });
        } catch (Throwable $e) {
            if ($batch) {
                $batch->fill([
                    'status' => MasterImportBatch::STATUS_FAILED,
                    'progress_stage' => 'failed',
                    'progress_pct' => 100,
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
            ]);
        }

        if ($batch) {
            $batch->fill([
                'status' => MasterImportBatch::STATUS_COMPLETED,
                'total_records' => $result['total_rows'],
                'created_count' => $result['imported'],
                'duplicate_count' => $result['already_existing'],
                'review_count' => $result['needs_review'],
                'conflict_count' => $result['unmatched'],
                'failed_count' => $result['failed'],
                'progress_stage' => 'completed',
                'progress_pct' => 100,
                'remarks' => json_encode([
                    'employee_name' => $employee,
                    'matched_count' => $result['matched'],
                    'needs_review_count' => $result['needs_review'],
                    'unmatched_count' => $result['unmatched'],
                    'ignored_count' => 0,
                    'skipped_blank' => $result['skipped_blank'],
                    'force_reimport' => $forceReimport,
                ], JSON_UNESCAPED_UNICODE),
            ])->save();
        }

        return array_merge($empty, $result, [
            'status' => 'completed',
            'import_batch_id' => $batch?->id,
            'employee' => $employee,
        ]);
    }

    /**
     * @return array{
     *   total_rows: int,
     *   imported: int,
     *   already_existing: int,
     *   matched: int,
     *   needs_review: int,
     *   unmatched: int,
     *   failed: int,
     *   skipped_blank: int
     * }
     */
    private function importCsvInsideTransaction(
        string $filePath,
        string $fileName,
        ?string $fileHash,
        string $employeeName,
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
        ];

        $hasFingerprint = Schema::hasColumn('sales_import_rows', 'row_fingerprint');
        $hasFileHashCol = Schema::hasColumn('sales_import_rows', 'source_file_hash');
        $hasReferenceCol = Schema::hasColumn('sales_import_rows', 'matched_reference_firm_id');
        $hasCandidatesCol = Schema::hasColumn('sales_import_rows', 'match_candidates');

        $existingFingerprints = [];
        $existingRowNumbers = SalesImportRow::query()
            ->where('source_file_name', $fileName)
            ->pluck('source_row_number', 'id')
            ->filter()
            ->flip()
            ->all();

        if ($hasFingerprint) {
            $existingFingerprints = SalesImportRow::query()
                ->where('source_file_name', $fileName)
                ->whereNotNull('row_fingerprint')
                ->pluck('row_fingerprint')
                ->flip()
                ->all();
        }

        $insertBuffer = [];
        $sourceRowNumber = 1;
        $now = now()->format('Y-m-d H:i:s');

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
                $mobile = $this->cleanPhone($this->value($row, $columns, 'mobile_no'));
                $callDate = $this->parseDate($this->value($row, $columns, 'date'));
                $mapping = $this->matcher->match($rawFirmName, $rawCity);
                $normalizedCa = $this->normalizer->caName($rawCaName);

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

                $counts['imported']++;
                $counts[$mapping['status']] = ($counts[$mapping['status']] ?? 0) + 1;

                $rawPayload = [];
                foreach ($headers as $index => $header) {
                    $name = trim((string) $header);
                    if ($name === '') {
                        $name = 'column_'.$index;
                    }
                    $rawPayload[$name] = isset($row[$index]) ? trim((string) $row[$index]) : null;
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
                    'alternate_mobile_no' => $this->cleanPhone($this->value($row, $columns, 'alternate_mobile_no')),
                    'city_name' => $rawCity,
                    'remarks_1' => $this->value($row, $columns, 'remarks_1'),
                    'remarks_2' => $this->value($row, $columns, 'remarks_2'),
                    'normalized_ca_name' => $normalizedCa,
                    'normalized_firm_name' => $mapping['normalized_firm_name'],
                    'normalized_city' => $mapping['normalized_city'],
                    'matched_ca_id' => $mapping['ca_id'],
                    'mapping_status' => $mapping['status'],
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
                    $insert['matched_reference_firm_id'] = $mapping['matched_reference_firm_id'];
                }
                if ($hasCandidatesCol) {
                    $insert['match_candidates'] = json_encode(
                        $mapping['candidates'] ?? [],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    );
                }

                $insertBuffer[] = $insert;
                if (count($insertBuffer) >= 500) {
                    DB::table('sales_import_rows')->insert($insertBuffer);
                    $insertBuffer = [];
                }
            } catch (Throwable $rowError) {
                $counts['failed']++;
                $counts['total_rows']++;
                // One bad row must not abort the file unless structure is unusable (already thrown above).
                report($rowError);
            }
        }

        if ($insertBuffer !== []) {
            DB::table('sales_import_rows')->insert($insertBuffer);
        }

        // Attach legacy rows (null batch) to this batch without changing mapping fields.
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
                'ca_name', 'caname', 'name' => 'ca_name',
                'firm_name', 'firmname' => 'firm_name',
                'number', 'mobile', 'mobile_no', 'phone', 'phone_number' => 'mobile_no',
                'alternate_mobile_no', 'alternate_number', 'alt_mobile', 'alternate_mobile' => 'alternate_mobile_no',
                'city', 'city_name' => 'city',
                'remarks_1', 'remark_1', 'remarks1', 'remark1', 'remarks' => 'remarks_1',
                'remarks_2', 'remark_2', 'remarks2', 'remark2' => 'remarks_2',
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

    private function cleanPhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $digits !== '' ? $digits : null;
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
