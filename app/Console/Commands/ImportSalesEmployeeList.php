<?php

namespace App\Console\Commands;

use App\Models\SalesImportRow;
use App\Services\Mapping\DataNormalizationService;
use App\Services\Mapping\SalesImportMatchingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use SplFileObject;
use Throwable;

class ImportSalesEmployeeList extends Command
{
    protected $signature = 'sales-list:import
                            {file : CSV file path}
                            {--employee= : Employee name, for example ANKIT}
                            {--replace : Delete previous rows imported from the same file}';

    protected $description = 'Import an employee calling list and Auto Match against CA Reference (firm + city)';

    public function __construct(
        private readonly DataNormalizationService $normalizer,
        private readonly SalesImportMatchingService $matcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $filePath = $this->resolveFilePath((string) $this->argument('file'));

        if (! is_file($filePath)) {
            $this->error("CSV file not found: {$filePath}");

            return self::FAILURE;
        }

        $fileName = basename($filePath);
        $employeeName = trim((string) $this->option('employee'));

        if ($employeeName === '') {
            $employeeName = $this->employeeNameFromFile($fileName);
        }

        $existingCount = SalesImportRow::query()
            ->where('source_file_name', $fileName)
            ->count();

        if ($existingCount > 0 && ! $this->option('replace')) {
            $this->error(
                "{$existingCount} rows from this file already exist. ".
                'Use --replace only when you intentionally want to import it again.'
            );

            return self::FAILURE;
        }

        if ($existingCount > 0 && $this->option('replace')) {
            SalesImportRow::query()
                ->where('source_file_name', $fileName)
                ->delete();
        }

        $this->info('Auto Match rule: exact normalized firm + city against CA Reference (exactly one hit).');
        $this->info('Reading CSV: '.$fileName);
        $this->info('Employee: '.$employeeName);

        try {
            $result = $this->importCsv($filePath, $fileName, $employeeName);
        } catch (Throwable $e) {
            $this->error('Import failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Employee list imported successfully.');

        $this->table(
            ['Result', 'Rows'],
            [
                ['Total imported', $result['total']],
                ['Matched', $result['matched']],
                ['Needs review', $result['needs_review']],
                ['Unmatched', $result['unmatched']],
                ['Skipped blank rows', $result['skipped']],
            ]
        );

        $this->warn('No CA master/reference record was created, updated, or deleted.');

        return self::SUCCESS;
    }

    /**
     * @return array{total: int, matched: int, needs_review: int, unmatched: int, skipped: int}
     */
    private function importCsv(string $filePath, string $fileName, string $employeeName): array
    {
        $csv = new SplFileObject($filePath, 'r');
        $csv->setFlags(
            SplFileObject::READ_CSV
            | SplFileObject::SKIP_EMPTY
            | SplFileObject::DROP_NEW_LINE
        );

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
            'total' => 0,
            'matched' => 0,
            'needs_review' => 0,
            'unmatched' => 0,
            'skipped' => 0,
        ];

        $insertBuffer = [];
        $sourceRowNumber = 1;
        $now = now()->format('Y-m-d H:i:s');
        $hasReferenceCol = Schema::hasColumn('sales_import_rows', 'matched_reference_firm_id');
        $hasCandidatesCol = Schema::hasColumn('sales_import_rows', 'match_candidates');

        while (! $csv->eof()) {
            $row = $csv->fgetcsv();
            $sourceRowNumber++;

            if (! is_array($row) || $this->isBlankRow($row)) {
                $counts['skipped']++;

                continue;
            }

            $rawCaName = $this->value($row, $columns, 'ca_name');
            $rawFirmName = $this->value($row, $columns, 'firm_name');
            $rawCity = $this->value($row, $columns, 'city');

            $mapping = $this->matcher->match($rawFirmName, $rawCity);

            $counts['total']++;
            $counts[$mapping['status']]++;

            $rawPayload = [];
            foreach ($headers as $index => $header) {
                $name = trim((string) $header);
                if ($name === '') {
                    $name = 'column_'.$index;
                }

                $rawPayload[$name] = isset($row[$index]) ? trim((string) $row[$index]) : null;
            }

            $insert = [
                'import_batch_id' => null,
                'source_file_name' => $fileName,
                'source_sheet_name' => $employeeName,
                'source_row_number' => $sourceRowNumber,
                'employee_name' => $employeeName,
                'call_date' => $this->parseDate($this->value($row, $columns, 'date')),
                'ca_name' => $rawCaName,
                'firm_name' => $rawFirmName,
                'mobile_no' => $this->cleanPhone($this->value($row, $columns, 'mobile_no')),
                'alternate_mobile_no' => $this->cleanPhone($this->value($row, $columns, 'alternate_mobile_no')),
                'city_name' => $rawCity,
                'remarks_1' => $this->value($row, $columns, 'remarks_1'),
                'remarks_2' => $this->value($row, $columns, 'remarks_2'),
                'normalized_ca_name' => $this->normalizer->caName($rawCaName),
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
        }

        if ($insertBuffer !== []) {
            DB::table('sales_import_rows')->insert($insertBuffer);
        }

        return $counts;
    }

    /**
     * @param  list<mixed>  $headers
     * @return array<string, int>
     */
    private function buildColumnMap(array $headers): array
    {
        $map = [];

        foreach ($headers as $index => $header) {
            $normalized = $this->normalizeHeader((string) $header);

            $field = match ($normalized) {
                'date', 'call_date' => 'date',
                'ca_name', 'caname' => 'ca_name',
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
                // Try the next supported format.
            }
        }

        return null;
    }

    private function resolveFilePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        $projectPath = base_path($path);

        if (is_file($projectPath)) {
            return $projectPath;
        }

        return storage_path('app/sales-imports/'.$path);
    }

    private function employeeNameFromFile(string $fileName): string
    {
        $name = pathinfo($fileName, PATHINFO_FILENAME);

        if (preg_match('/-\s*([^-]+)$/', $name, $matches) === 1) {
            return mb_strtoupper(trim($matches[1]));
        }

        return mb_strtoupper(trim($name));
    }
}
