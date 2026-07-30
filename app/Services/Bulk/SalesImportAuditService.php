<?php

namespace App\Services\Bulk;

use App\Models\CaMaster;
use App\Models\City;
use App\Services\Leads\PhoneNormalizationService;
use App\Services\Master\LookupResolverService;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Read-only pre-import audit for sales CSV files.
 * Never writes to the database.
 */
class SalesImportAuditService
{
    public function __construct(
        private readonly PhoneNormalizationService $phones,
        private readonly LookupResolverService $lookups,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function audit(string $absolutePath, ?string $outputDir = null): array
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException('CSV not found: '.$absolutePath);
        }

        $outputDir ??= storage_path('app/audits');
        if (! is_dir($outputDir) && ! mkdir($outputDir, 0775, true) && ! is_dir($outputDir)) {
            throw new RuntimeException('Unable to create audit output directory: '.$outputDir);
        }

        $started = microtime(true);
        [$headers, $rawRows, $blankRows] = $this->readCsv($absolutePath);
        $totalRows = count($rawRows) + $blankRows;

        $remarkColumns = $this->detectRemarkColumns($headers);

        $parsed = [];
        foreach ($rawRows as $i => $row) {
            $line = $i + 2; // header = line 1
            $mapped = $this->mapRow($row, $line, $remarkColumns);
            // Completely empty rows were already counted in readCsv(); skip any residual blanks.
            if ($mapped['is_blank']) {
                $blankRows++;
                $totalRows++;
                continue;
            }
            $parsed[] = $mapped;
        }

        // Prefetch CRM indexes (read-only SELECTs only).
        $crm = $this->prefetchCrmIndexes($parsed);
        $cityIndex = $this->prefetchCityIndex();

        // Intra-file frequency maps.
        $caNameCounts = $this->frequencyMap(array_column($parsed, 'ca_name_key'));
        $firmNameCounts = $this->frequencyMap(array_column($parsed, 'firm_name_key'));
        $mobileCounts = $this->frequencyMap(array_filter(array_column($parsed, 'mobile_norm')));
        $altCounts = $this->frequencyMap(array_filter(array_column($parsed, 'alt_norm')));
        $emailCounts = $this->frequencyMap(array_filter(array_column($parsed, 'email_norm')));

        $unknownCities = [];
        $employeeCounts = [];

        $stats = $this->emptyStats();
        $stats['overall']['total_rows'] = $totalRows;
        $stats['overall']['blank_rows'] = $blankRows;
        $stats['remarks']['remark_columns'] = count($remarkColumns);
        $stats['remarks']['remark_column_names'] = $remarkColumns;

        $matchedByMobile = 0;
        $matchedByEmail = 0;
        $matchedByFirm = 0;
        $matchedByCa = 0;
        $completelyNew = 0;

        foreach ($parsed as $idx => $row) {
            // CA
            if ($row['ca_name'] !== '') {
                $stats['ca']['total']++;
                if (($caNameCounts[$row['ca_name_key']] ?? 0) > 1) {
                    $stats['ca']['duplicate']++;
                }
            } else {
                $stats['ca']['missing']++;
            }

            // Firm
            if ($row['firm_name'] !== '') {
                $stats['firm']['total']++;
                if (($firmNameCounts[$row['firm_name_key']] ?? 0) > 1) {
                    $stats['firm']['duplicate']++;
                }
            } else {
                $stats['firm']['missing']++;
            }

            // Mobile
            if ($row['mobile_raw'] !== '') {
                $stats['mobile']['total']++;
                if ($row['mobile_norm'] === null) {
                    $stats['mobile']['invalid']++;
                } else {
                    if (($mobileCounts[$row['mobile_norm']] ?? 0) > 1) {
                        $stats['mobile']['duplicate']++;
                    }
                    if (isset($crm['phones'][$row['mobile_norm']])) {
                        $stats['mobile']['existing']++;
                    } else {
                        $stats['mobile']['new']++;
                    }
                }
            } else {
                $stats['mobile']['missing']++;
            }

            // Alternate
            if ($row['alt_raw'] !== '') {
                $stats['alternate']['present']++;
                if ($row['alt_norm'] !== null && ($altCounts[$row['alt_norm']] ?? 0) > 1) {
                    $stats['alternate']['duplicate']++;
                }
            } else {
                $stats['alternate']['missing']++;
            }

            // Email
            if ($row['email_raw'] !== '') {
                $stats['email']['total']++;
                if (! $row['email_valid']) {
                    $stats['email']['invalid']++;
                } else {
                    if (($emailCounts[$row['email_norm']] ?? 0) > 1) {
                        $stats['email']['duplicate']++;
                    }
                    if (isset($crm['emails'][$row['email_norm']])) {
                        $stats['email']['existing']++;
                    } else {
                        $stats['email']['new']++;
                    }
                }
            } else {
                $stats['email']['missing']++;
            }

            // City
            if ($row['city'] !== '') {
                $stats['city']['total']++;
                $cityKey = mb_strtolower($row['city']);
                if (! isset($cityIndex[$cityKey]) && $this->lookups->resolveCityId($row['city']) === null) {
                    $stats['city']['not_found']++;
                    $unknownCities[$row['city']] = ($unknownCities[$row['city']] ?? 0) + 1;
                } else {
                    $stats['city']['found']++;
                }
            } else {
                $stats['city']['missing']++;
            }

            // Remarks
            $remarkCount = count($row['remarks']);
            $stats['remarks']['total_remark_values'] += $remarkCount;
            if ($remarkCount > 0) {
                $stats['remarks']['rows_with_remarks']++;
            } else {
                $stats['remarks']['rows_without_remarks']++;
            }

            // Employee
            $emp = $row['employee'] !== '' ? $row['employee'] : '(unassigned)';
            $employeeCounts[$emp] = ($employeeCounts[$emp] ?? 0) + 1;

            // Existing CRM match (priority: mobile → email → firm → ca)
            $matchType = null;
            $matchCaId = null;
            if ($row['mobile_norm'] && isset($crm['phones'][$row['mobile_norm']])) {
                $matchType = 'mobile';
                $matchCaId = $crm['phones'][$row['mobile_norm']];
                $matchedByMobile++;
            } elseif ($row['alt_norm'] && isset($crm['phones'][$row['alt_norm']])) {
                $matchType = 'alternate_mobile';
                $matchCaId = $crm['phones'][$row['alt_norm']];
                $matchedByMobile++;
            } elseif ($row['email_norm'] && isset($crm['emails'][$row['email_norm']])) {
                $matchType = 'email';
                $matchCaId = $crm['emails'][$row['email_norm']];
                $matchedByEmail++;
            } elseif ($row['firm_name_key'] !== '' && isset($crm['firms'][$row['firm_name_key']])) {
                $matchType = 'firm_name';
                $matchCaId = $crm['firms'][$row['firm_name_key']];
                $matchedByFirm++;
            } elseif ($row['ca_name_key'] !== '' && isset($crm['ca_names'][$row['ca_name_key']])) {
                $matchType = 'ca_name';
                $matchCaId = $crm['ca_names'][$row['ca_name_key']];
                $matchedByCa++;
            } else {
                $completelyNew++;
            }

            $row['crm_match_type'] = $matchType;
            $row['crm_match_ca_id'] = $matchCaId;
            $row['classification'] = $this->classifyRow($row, $mobileCounts);

            $stats['overall']['valid_rows'] += $row['is_invalid'] ? 0 : 1;
            $stats['overall']['invalid_rows'] += $row['is_invalid'] ? 1 : 0;

            $parsed[$idx] = $row;
        }

        $uniqueCities = [];
        foreach ($parsed as $row) {
            if ($row['city'] !== '') {
                $uniqueCities[mb_strtolower($row['city'])] = $row['city'];
            }
        }
        $stats['city']['unique'] = count($uniqueCities);

        unset($caNameCounts[''], $firmNameCounts['']);
        $stats['ca']['unique'] = count($caNameCounts);
        $stats['firm']['unique'] = count($firmNameCounts);

        // Deduplicate-style: first occurrence of a duplicate mobile is kept; later are duplicate_rows.
        $seenMobiles = [];
        $duplicateRows = 0;
        $newLeads = 0;
        $existingLeads = 0;
        $ready = 0;
        $manualReview = 0;
        $skipped = 0;

        foreach ($parsed as $idx => $row) {
            $isDup = false;
            if ($row['mobile_norm']) {
                if (isset($seenMobiles[$row['mobile_norm']])) {
                    $isDup = true;
                    $duplicateRows++;
                } else {
                    $seenMobiles[$row['mobile_norm']] = true;
                }
            }

            if ($row['is_invalid'] || $row['classification'] === 'skipped') {
                $skipped++;
                $row['import_bucket'] = 'skipped';
            } elseif ($isDup) {
                $skipped++;
                $row['import_bucket'] = 'duplicate_row';
                $row['classification'] = 'duplicate_row';
            } elseif ($row['classification'] === 'manual_review') {
                $manualReview++;
                $row['import_bucket'] = 'manual_review';
            } elseif ($row['crm_match_type'] !== null) {
                $existingLeads++;
                $ready++;
                $row['import_bucket'] = 'existing_lead';
            } else {
                $newLeads++;
                $ready++;
                $row['import_bucket'] = 'new_lead';
            }

            $parsed[$idx] = $row;
        }

        arsort($employeeCounts);
        arsort($unknownCities);

        $stats['employee']['total_employees'] = count(array_filter(
            array_keys($employeeCounts),
            fn ($e) => $e !== '(unassigned)',
        ));
        $stats['employee']['leads_per_employee'] = $employeeCounts;

        $stats['crm_match'] = [
            'matched_by_mobile' => $matchedByMobile,
            'matched_by_email' => $matchedByEmail,
            'matched_by_firm_name' => $matchedByFirm,
            'matched_by_ca_name' => $matchedByCa,
            'completely_new_leads' => $completelyNew,
        ];

        $stats['import_summary'] = [
            'new_leads' => $newLeads,
            'existing_leads' => $existingLeads,
            'duplicate_rows' => $duplicateRows,
            'rows_ready_to_import' => $ready,
            'rows_requiring_manual_review' => $manualReview,
            'rows_that_will_be_skipped' => $skipped,
        ];

        $stats['city']['unknown_cities'] = $unknownCities;
        $stats['city']['unknown_city_count'] = count($unknownCities);

        $stamp = now()->format('Ymd_His');
        $base = 'sales-import-audit-'.$stamp;
        $jsonPath = $outputDir.DIRECTORY_SEPARATOR.$base.'.json';
        $csvPath = $outputDir.DIRECTORY_SEPARATOR.$base.'.csv';
        $summaryCsvPath = $outputDir.DIRECTORY_SEPARATOR.$base.'-summary.csv';

        $report = [
            'generated_at' => now()->toIso8601String(),
            'file' => basename($absolutePath),
            'file_path' => $absolutePath,
            'database' => config('database.default'),
            'read_only' => true,
            'headers' => $headers,
            'stats' => $stats,
            'time_seconds' => round(microtime(true) - $started, 2),
            'outputs' => [
                'json' => $jsonPath,
                'rows_csv' => $csvPath,
                'summary_csv' => $summaryCsvPath,
            ],
        ];

        // JSON: summary + unknown cities + employee breakdown (omit full row dump to keep size sane).
        // Full row-level detail goes to CSV.
        file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->writeRowsCsv($csvPath, $parsed, $remarkColumns);
        $this->writeSummaryCsv($summaryCsvPath, $stats);

        $report['row_sample'] = array_slice($parsed, 0, 25);

        return $report;
    }

    /**
     * @return array{overall: array<string, int>, ca: array<string, int>, firm: array<string, int>, mobile: array<string, int>, alternate: array<string, int>, email: array<string, int>, city: array<string, mixed>, remarks: array<string, mixed>, employee: array<string, mixed>, crm_match: array<string, int>, import_summary: array<string, int>}
     */
    private function emptyStats(): array
    {
        return [
            'overall' => [
                'total_rows' => 0,
                'blank_rows' => 0,
                'valid_rows' => 0,
                'invalid_rows' => 0,
            ],
            'ca' => ['total' => 0, 'missing' => 0, 'duplicate' => 0, 'unique' => 0],
            'firm' => ['total' => 0, 'missing' => 0, 'duplicate' => 0, 'unique' => 0],
            'mobile' => [
                'total' => 0,
                'missing' => 0,
                'invalid' => 0,
                'duplicate' => 0,
                'existing' => 0,
                'new' => 0,
            ],
            'alternate' => ['present' => 0, 'missing' => 0, 'duplicate' => 0],
            'email' => [
                'total' => 0,
                'missing' => 0,
                'invalid' => 0,
                'duplicate' => 0,
                'existing' => 0,
                'new' => 0,
            ],
            'city' => [
                'total' => 0,
                'missing' => 0,
                'unique' => 0,
                'found' => 0,
                'not_found' => 0,
                'unknown_city_count' => 0,
                'unknown_cities' => [],
            ],
            'remarks' => [
                'remark_columns' => 0,
                'remark_column_names' => [],
                'rows_with_remarks' => 0,
                'rows_without_remarks' => 0,
                'total_remark_values' => 0,
            ],
            'employee' => [
                'total_employees' => 0,
                'leads_per_employee' => [],
            ],
            'crm_match' => [],
            'import_summary' => [],
        ];
    }

    /**
     * @param  list<string>  $headers
     * @return list<string>
     */
    private function detectRemarkColumns(array $headers): array
    {
        $cols = [];
        foreach ($headers as $h) {
            $key = strtolower(trim($h));
            if ($key === '') {
                continue;
            }
            if (preg_match('/^remarks?\b/', $key) || str_contains($key, 'remark')) {
                $cols[] = $h;
            }
        }

        return $cols;
    }

    /**
     * @return array{0: list<string>, 1: list<array<string, string>>, 2: int}
     */
    private function readCsv(string $path): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Unable to open CSV: '.$path);
        }

        $headers = fgetcsv($fh);
        if (! is_array($headers) || $headers === []) {
            fclose($fh);
            throw new RuntimeException('CSV has no header row.');
        }
        $headers = array_map(fn ($h) => trim((string) $h), $headers);

        $rows = [];
        $blank = 0;
        while (($data = fgetcsv($fh)) !== false) {
            if ($this->rawRowIsEmpty($data)) {
                $blank++;
                continue;
            }
            $row = [];
            foreach ($headers as $i => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = isset($data[$i]) ? trim((string) $data[$i]) : '';
            }
            $rows[] = $row;
        }
        fclose($fh);

        return [$headers, $rows, $blank];
    }

    /**
     * @param  list<mixed>  $data
     */
    private function rawRowIsEmpty(array $data): bool
    {
        foreach ($data as $v) {
            if (trim((string) $v) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<string>  $remarkColumns
     * @return array<string, mixed>
     */
    private function mapRow(array $row, int $line, array $remarkColumns): array
    {
        $get = function (string ...$names) use ($row): string {
            foreach ($row as $k => $v) {
                $key = strtolower(trim((string) $k));
                foreach ($names as $n) {
                    if ($key === strtolower($n)) {
                        return trim((string) $v);
                    }
                }
            }

            return '';
        };

        $ca = $get('CA NAME', 'CA Name', 'ca_name', 'CA');
        $firm = $get('Firm Name', 'firm_name', 'Firm');
        $emailRaw = strtolower($get('email id', 'Email', 'email_id', 'Email ID'));
        $mobileRaw = $get('Mobile No', 'Mobile', 'mobile_no', 'Mobile Number');
        $altRaw = $get('Alternate Mobile No', 'Alt Mobile', 'alternate_mobile_no');
        $city = $get('City', 'city');
        $employee = $get('Employee', 'employee', 'Assigned To', 'assigned_to');

        $remarks = [];
        foreach ($remarkColumns as $col) {
            $val = trim((string) ($row[$col] ?? ''));
            if ($val !== '') {
                $remarks[] = $val;
            }
        }

        $mobileNorm = $this->phones->normalize($mobileRaw);
        $altNorm = $this->phones->normalize($altRaw);
        if ($altNorm !== null && $mobileNorm !== null && $altNorm === $mobileNorm) {
            $altNorm = null;
        }

        $emailValid = $emailRaw !== '' && (bool) filter_var($emailRaw, FILTER_VALIDATE_EMAIL);
        $emailNorm = $emailValid ? $emailRaw : null;

        $isBlank = $ca === '' && $firm === '' && $mobileRaw === '' && $emailRaw === '' && $city === '';
        $isInvalid = false;
        $invalidReasons = [];

        if ($isBlank) {
            $isInvalid = true;
            $invalidReasons[] = 'blank_identity';
        } elseif ($firm === '' && $ca === '' && $mobileNorm === null && ! $emailValid) {
            $isInvalid = true;
            $invalidReasons[] = 'no_usable_identity';
        } elseif ($mobileRaw !== '' && $mobileNorm === null) {
            $isInvalid = true;
            $invalidReasons[] = 'invalid_mobile';
        }

        return [
            'line' => $line,
            'ca_name' => $ca,
            'ca_name_key' => $ca !== '' ? mb_strtolower(preg_replace('/\s+/', ' ', $ca) ?? $ca) : '',
            'firm_name' => $firm,
            'firm_name_key' => $firm !== '' ? mb_strtolower(preg_replace('/\s+/', ' ', $firm) ?? $firm) : '',
            'email_raw' => $emailRaw,
            'email_norm' => $emailNorm,
            'email_valid' => $emailValid,
            'mobile_raw' => $mobileRaw,
            'mobile_norm' => $mobileNorm,
            'alt_raw' => $altRaw,
            'alt_norm' => $altNorm,
            'city' => $city,
            'employee' => $employee,
            'remarks' => $remarks,
            'is_blank' => $isBlank,
            'is_invalid' => $isInvalid,
            'invalid_reasons' => $invalidReasons,
            'crm_match_type' => null,
            'crm_match_ca_id' => null,
            'classification' => 'pending',
            'import_bucket' => 'pending',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $mobileCounts
     */
    private function classifyRow(array $row, array $mobileCounts): string
    {
        if ($row['is_invalid']) {
            return 'skipped';
        }
        // Ambiguous identity: no phone and no email — firm/CA-only may need review
        if ($row['mobile_norm'] === null && ($row['email_norm'] === null || $row['email_norm'] === '')) {
            if ($row['firm_name'] === '' || $row['ca_name'] === '') {
                return 'manual_review';
            }
        }
        if ($row['mobile_raw'] !== '' && $row['mobile_norm'] === null) {
            return 'manual_review';
        }
        if ($row['email_raw'] !== '' && ! $row['email_valid']) {
            return 'manual_review';
        }

        return 'ready';
    }

    /**
     * @param  list<array<string, mixed>>  $parsed
     * @return array{phones: array<string, int>, emails: array<string, int>, firms: array<string, int>, ca_names: array<string, int>}
     */
    private function prefetchCrmIndexes(array $parsed): array
    {
        $phones = [];
        $emails = [];
        $firms = [];
        $caNames = [];

        $query = CaMaster::query()->whereNull('deleted_at');
        $cols = ['ca_id', 'firm_name', 'ca_name', 'mobile_no', 'email_id'];
        if (Schema::hasColumn('ca_masters', 'normalized_mobile')) {
            $cols[] = 'normalized_mobile';
        }
        if (Schema::hasColumn('ca_masters', 'normalized_alternate_mobile')) {
            $cols[] = 'normalized_alternate_mobile';
        }
        if (Schema::hasColumn('ca_masters', 'alternate_mobile_no')) {
            $cols[] = 'alternate_mobile_no';
        }
        if (Schema::hasColumn('ca_masters', 'normalized_email')) {
            $cols[] = 'normalized_email';
        }

        $query->select($cols)->orderBy('ca_id')->chunkById(1000, function ($rows) use (&$phones, &$emails, &$firms, &$caNames) {
            foreach ($rows as $lead) {
                $id = (int) $lead->ca_id;
                foreach ([
                    $lead->normalized_mobile ?? null,
                    $lead->normalized_alternate_mobile ?? null,
                    $this->phones->normalize($lead->mobile_no ?? null),
                    $this->phones->normalize($lead->alternate_mobile_no ?? null),
                ] as $p) {
                    if ($p) {
                        $phones[$p] = $phones[$p] ?? $id;
                    }
                }

                $email = $lead->normalized_email ?? null;
                if (! $email && filled($lead->email_id)) {
                    $email = strtolower(trim((string) $lead->email_id));
                }
                if ($email) {
                    $emails[strtolower((string) $email)] = $emails[strtolower((string) $email)] ?? $id;
                }

                if (filled($lead->firm_name)) {
                    $fk = mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $lead->firm_name)) ?? '');
                    if ($fk !== '') {
                        $firms[$fk] = $firms[$fk] ?? $id;
                    }
                }
                if (filled($lead->ca_name)) {
                    $ck = mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $lead->ca_name)) ?? '');
                    if ($ck !== '') {
                        $caNames[$ck] = $caNames[$ck] ?? $id;
                    }
                }
            }
        }, 'ca_id');

        return [
            'phones' => $phones,
            'emails' => $emails,
            'firms' => $firms,
            'ca_names' => $caNames,
        ];
    }

    /**
     * @return array<string, int> lowercase city_name => city_id
     */
    private function prefetchCityIndex(): array
    {
        $index = [];
        City::query()->select(['city_id', 'city_name'])->orderBy('city_id')->chunkById(500, function ($rows) use (&$index) {
            foreach ($rows as $city) {
                $name = trim((string) $city->city_name);
                if ($name !== '') {
                    $index[mb_strtolower($name)] = (int) $city->city_id;
                }
            }
        }, 'city_id');

        return $index;
    }

    /**
     * @param  list<string|null>  $values
     * @return array<string, int>
     */
    private function frequencyMap(array $values): array
    {
        $map = [];
        foreach ($values as $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $map[(string) $v] = ($map[(string) $v] ?? 0) + 1;
        }

        return $map;
    }

    /**
     * @param  list<array<string, mixed>>  $parsed
     * @param  list<string>  $remarkColumns
     */
    private function writeRowsCsv(string $path, array $parsed, array $remarkColumns): void
    {
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Unable to write rows CSV: '.$path);
        }

        $header = [
            'line', 'ca_name', 'firm_name', 'email', 'mobile_raw', 'mobile_normalized',
            'alternate_raw', 'alternate_normalized', 'city', 'employee',
            'remark_count', 'remarks', 'is_invalid', 'invalid_reasons',
            'crm_match_type', 'crm_match_ca_id', 'classification', 'import_bucket',
        ];
        fputcsv($fh, $header);

        foreach ($parsed as $row) {
            fputcsv($fh, [
                $row['line'],
                $row['ca_name'],
                $row['firm_name'],
                $row['email_raw'],
                $row['mobile_raw'],
                $row['mobile_norm'] ?? '',
                $row['alt_raw'],
                $row['alt_norm'] ?? '',
                $row['city'],
                $row['employee'],
                count($row['remarks']),
                implode(' | ', $row['remarks']),
                $row['is_invalid'] ? 'yes' : 'no',
                implode(',', $row['invalid_reasons']),
                $row['crm_match_type'] ?? '',
                $row['crm_match_ca_id'] ?? '',
                $row['classification'],
                $row['import_bucket'],
            ]);
        }
        fclose($fh);
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function writeSummaryCsv(string $path, array $stats): void
    {
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Unable to write summary CSV: '.$path);
        }

        fputcsv($fh, ['section', 'metric', 'value']);

        $flat = [
            ['Overall', 'Total rows', $stats['overall']['total_rows']],
            ['Overall', 'Blank rows', $stats['overall']['blank_rows']],
            ['Overall', 'Valid rows', $stats['overall']['valid_rows']],
            ['Overall', 'Invalid rows', $stats['overall']['invalid_rows']],
            ['CA Information', 'Total CA Names', $stats['ca']['total']],
            ['CA Information', 'Missing CA Names', $stats['ca']['missing']],
            ['CA Information', 'Duplicate CA Names', $stats['ca']['duplicate']],
            ['CA Information', 'Unique CA Names', $stats['ca']['unique']],
            ['Firm Information', 'Total Firm Names', $stats['firm']['total']],
            ['Firm Information', 'Missing Firm Names', $stats['firm']['missing']],
            ['Firm Information', 'Duplicate Firm Names', $stats['firm']['duplicate']],
            ['Firm Information', 'Unique Firm Names', $stats['firm']['unique']],
            ['Mobile Numbers', 'Total Mobile Numbers', $stats['mobile']['total']],
            ['Mobile Numbers', 'Missing Mobile Numbers', $stats['mobile']['missing']],
            ['Mobile Numbers', 'Invalid Mobile Numbers', $stats['mobile']['invalid']],
            ['Mobile Numbers', 'Duplicate Mobile Numbers', $stats['mobile']['duplicate']],
            ['Mobile Numbers', 'Existing Mobiles in ca_masters', $stats['mobile']['existing']],
            ['Mobile Numbers', 'New Mobiles not in CRM', $stats['mobile']['new']],
            ['Alternate Mobile', 'Present', $stats['alternate']['present']],
            ['Alternate Mobile', 'Missing', $stats['alternate']['missing']],
            ['Alternate Mobile', 'Duplicate', $stats['alternate']['duplicate']],
            ['Email', 'Total Email IDs', $stats['email']['total']],
            ['Email', 'Missing Emails', $stats['email']['missing']],
            ['Email', 'Invalid Email Format', $stats['email']['invalid']],
            ['Email', 'Duplicate Emails', $stats['email']['duplicate']],
            ['Email', 'Existing Emails in ca_masters', $stats['email']['existing']],
            ['Email', 'New Emails', $stats['email']['new']],
            ['City', 'Total Cities', $stats['city']['total']],
            ['City', 'Missing Cities', $stats['city']['missing']],
            ['City', 'Unique Cities', $stats['city']['unique']],
            ['City', 'Cities found in cities table', $stats['city']['found']],
            ['City', 'Cities not found in cities table', $stats['city']['not_found']],
            ['City', 'Unknown city names (distinct)', $stats['city']['unknown_city_count']],
            ['Remarks', 'Remark columns', $stats['remarks']['remark_columns']],
            ['Remarks', 'Rows with at least one remark', $stats['remarks']['rows_with_remarks']],
            ['Remarks', 'Rows with no remarks', $stats['remarks']['rows_without_remarks']],
            ['Remarks', 'Total remark values', $stats['remarks']['total_remark_values']],
            ['Employee', 'Total Employees', $stats['employee']['total_employees']],
            ['Existing CRM Match', 'Matched by Mobile', $stats['crm_match']['matched_by_mobile']],
            ['Existing CRM Match', 'Matched by Email', $stats['crm_match']['matched_by_email']],
            ['Existing CRM Match', 'Matched by Firm Name', $stats['crm_match']['matched_by_firm_name']],
            ['Existing CRM Match', 'Matched by CA Name', $stats['crm_match']['matched_by_ca_name']],
            ['Existing CRM Match', 'Completely New Leads', $stats['crm_match']['completely_new_leads']],
            ['Import Summary', 'New Leads', $stats['import_summary']['new_leads']],
            ['Import Summary', 'Existing Leads', $stats['import_summary']['existing_leads']],
            ['Import Summary', 'Duplicate Rows', $stats['import_summary']['duplicate_rows']],
            ['Import Summary', 'Rows Ready to Import', $stats['import_summary']['rows_ready_to_import']],
            ['Import Summary', 'Rows Requiring Manual Review', $stats['import_summary']['rows_requiring_manual_review']],
            ['Import Summary', 'Rows That Will Be Skipped', $stats['import_summary']['rows_that_will_be_skipped']],
        ];

        foreach ($flat as $row) {
            fputcsv($fh, $row);
        }

        fputcsv($fh, []);
        fputcsv($fh, ['Employee', 'Employee Name', 'Lead Count']);
        foreach ($stats['employee']['leads_per_employee'] as $name => $count) {
            fputcsv($fh, ['Employee', $name, $count]);
        }

        fputcsv($fh, []);
        fputcsv($fh, ['Unknown Cities', 'City Name', 'Row Count']);
        foreach ($stats['city']['unknown_cities'] as $name => $count) {
            fputcsv($fh, ['Unknown Cities', $name, $count]);
        }

        fclose($fh);
    }
}
