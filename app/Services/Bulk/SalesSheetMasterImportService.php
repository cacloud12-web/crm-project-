<?php

namespace App\Services\Bulk;

use App\Models\CaMaster;
use App\Models\MasterMappingDecision;
use App\Services\Cache\CrmCacheService;
use App\Services\Leads\PhoneNormalizationService;
use App\Services\Mapping\MasterDataMappingService;
use App\Services\Master\LookupResolverService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Fast, safe sales-sheet → ca_masters import.
 * - Intra-file phone dedupe (keep best row)
 * - Match existing masters by mobile/email/GST/FRN/membership
 * - Empty-only merge (never overwrite filled values)
 * - 250-row mapping-engine batches + deferred cache bust
 */
class SalesSheetMasterImportService
{
    public function __construct(
        private readonly MasterDataMappingService $mapping,
        private readonly PhoneNormalizationService $phones,
        private readonly LookupResolverService $lookups,
        private readonly CrmCacheService $cache,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function import(string $absolutePath, bool $dryRun = false, int $engineBatch = 250, ?callable $progress = null): array
    {
        $started = microtime(true);
        $peakStart = memory_get_peak_usage(true);

        if (! is_file($absolutePath)) {
            throw new RuntimeException('CSV not found: '.$absolutePath);
        }

        $rawRows = $this->readCsv($absolutePath);
        $totalCsv = count($rawRows);

        $normalized = [];
        $invalid = 0;
        $cityUnresolved = [];

        foreach ($rawRows as $i => $row) {
            $mapped = $this->mapSheetRow($row, $i + 2); // header is line 1
            if ($mapped === null) {
                $invalid++;
                continue;
            }
            $normalized[] = $mapped;
        }

        [$uniqueRows, $removedIntra] = $this->dedupeByPhoneKeepBest($normalized);

        $report = [
            'file' => basename($absolutePath),
            'dry_run' => $dryRun,
            'total_csv_rows' => $totalCsv,
            'skipped_invalid' => $invalid,
            'duplicate_rows_inside_csv_removed' => $removedIntra,
            'rows_after_intra_dedupe' => count($uniqueRows),
            'existing_masters_merged' => 0,
            'new_masters_created' => 0,
            'skipped_duplicate_engine' => 0,
            'skipped_conflict_or_review' => 0,
            'city_unresolved' => 0,
            'city_resolved' => 0,
            'city_unresolved_samples' => [],
            'engine_batch_rows' => $engineBatch,
            'time_seconds' => 0,
            'rows_per_sec' => 0,
            'peak_memory_mb' => 0,
            'verification' => [],
        ];

        // Prefetch lookups for all cities in the unique set.
        $cities = [];
        foreach ($uniqueRows as $row) {
            if ($row['city'] !== '') {
                $cities[$row['city']] = true;
            }
        }
        foreach (array_keys($cities) as $cityName) {
            $id = $this->lookups->ensureCityId($cityName, null);
            if ($id) {
                $report['city_resolved']++;
            } else {
                $report['city_unresolved']++;
                if (count($report['city_unresolved_samples']) < 25) {
                    $report['city_unresolved_samples'][] = [
                        'city' => $cityName,
                        'reason' => 'ensureCityId returned null',
                    ];
                }
                Log::warning('sales_sheet_import.city_unresolved', [
                    'city' => $cityName,
                    'reason' => 'ensureCityId returned null',
                ]);
            }
        }

        if ($dryRun) {
            $report['time_seconds'] = round(microtime(true) - $started, 2);
            $report['peak_memory_mb'] = round(max(memory_get_peak_usage(true), $peakStart) / 1048576, 1);
            $report['note'] = 'Dry run only — no database writes.';

            return $report;
        }

        $batchId = null;
        $engineBatch = max(25, $engineBatch);
        $chunks = array_chunk($uniqueRows, $engineBatch);
        $chunkCount = count($chunks);

        foreach ($chunks as $ci => $chunk) {
            $payloads = [];
            foreach ($chunk as $row) {
                $payloads[] = $this->toEnginePayload($row);
            }

            $stats = $this->mapping->processBatch(
                'csv',
                'sales-sheet:'.basename($absolutePath),
                $payloads,
                null,
                [
                    'source_name' => 'Sales CSV Import',
                    'file_name' => basename($absolutePath),
                    'import_batch_id' => $batchId,
                    'finalize' => ($ci === $chunkCount - 1),
                    'defer_cache_bust' => true,
                    'expected_total' => count($uniqueRows),
                    'matching_profile' => 'identifier_first',
                ],
            );

            if (! empty($stats['import_batch_id'])) {
                $batchId = (int) $stats['import_batch_id'];
            }

            $report['new_masters_created'] += (int) ($stats['auto_created'] ?? 0);
            $report['existing_masters_merged'] += (int) ($stats['auto_updated'] ?? 0);
            $report['skipped_conflict_or_review'] += (int) ($stats['needs_review'] ?? 0) + (int) ($stats['conflicts'] ?? 0);

            $decisions = $stats['decisions'] ?? [];
            foreach ($chunk as $idx => $row) {
                $decision = $decisions[$idx] ?? null;
                $caId = is_array($decision) ? (int) ($decision['ca_id'] ?? 0) : 0;
                $type = is_array($decision) ? (string) ($decision['decision'] ?? '') : '';

                if ($caId > 0 && in_array($type, [
                    MasterMappingDecision::DECISION_AUTO_CREATE,
                    MasterMappingDecision::DECISION_AUTO_UPDATE,
                ], true)) {
                    $this->applySalesRemarksAndEmail($caId, $row, $type === MasterMappingDecision::DECISION_AUTO_CREATE);
                } elseif ($type === MasterMappingDecision::DECISION_CONFLICT) {
                    $report['skipped_duplicate_engine']++;
                }
            }

            if ($progress) {
                $progress($ci + 1, $chunkCount, $report);
            }
        }

        // One cache invalidation after the full import.
        $this->cache->forgetMasterListings();
        $this->cache->forgetDashboardMetrics();
        $this->cache->forgetLeadSegmentCounts();

        $report['verification'] = $this->verifyImport($uniqueRows);
        $report['time_seconds'] = round(microtime(true) - $started, 2);
        $report['rows_per_sec'] = $report['time_seconds'] > 0
            ? round(count($uniqueRows) / $report['time_seconds'], 1)
            : 0;
        $report['peak_memory_mb'] = round(max(memory_get_peak_usage(true), $peakStart) / 1048576, 1);

        return $report;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<array<string, mixed>>
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

        $out = [];
        while (($data = fgetcsv($fh)) !== false) {
            if ($this->rowIsEmpty($data)) {
                continue;
            }
            $row = [];
            foreach ($headers as $i => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = isset($data[$i]) ? trim((string) $data[$i]) : '';
            }
            $out[] = $row;
        }
        fclose($fh);

        return $out;
    }

    /**
     * @param  list<mixed>  $data
     */
    private function rowIsEmpty(array $data): bool
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
     * @return array<string, mixed>|null
     */
    private function mapSheetRow(array $row, int $line): ?array
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

        $firm = $get('Firm Name', 'firm_name', 'Firm');
        $ca = $get('CA NAME', 'CA Name', 'ca_name', 'CA');
        $email = strtolower($get('email id', 'Email', 'email_id', 'Email ID'));
        $mobileRaw = $get('Mobile No', 'Mobile', 'mobile_no');
        $altRaw = $get('Alternate Mobile No', 'Alt Mobile', 'alternate_mobile_no');
        $city = $get('City', 'city');
        $gst = $get('GST', 'GST No', 'gst_no', 'GSTIN');
        $frn = $get('FRN', 'frn');
        $membership = $get('Membership No', 'Membership', 'membership_no');
        $website = $get('Website', 'website');

        $remarks = [];
        foreach ($row as $k => $v) {
            $key = strtolower(trim((string) $k));
            if ($v !== '' && (str_contains($key, 'remark') || str_contains($key, 'remarks'))) {
                $remarks[] = trim((string) $v);
            }
        }
        $salesRemarks = trim(implode("\n", array_unique($remarks)));

        $mobile = $this->phones->normalize($mobileRaw);
        $alt = $this->phones->normalize($altRaw);
        if ($alt !== null && $mobile !== null && $alt === $mobile) {
            $alt = null;
        }

        if ($firm === '' && $ca === '' && $mobile === null && $email === '') {
            return null;
        }
        if ($firm === '' && $mobile === null && $email === '') {
            return null; // cannot create/match safely
        }

        return [
            'line' => $line,
            'firm_name' => $firm !== '' ? $firm : ($ca !== '' ? $ca : 'Unknown Firm'),
            'ca_name' => $ca !== '' ? $ca : ($firm !== '' ? $firm : 'Unknown'),
            'email_id' => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            'mobile_no' => $mobile,
            'alternate_mobile_no' => $alt,
            'city' => $city,
            'gst_no' => $gst !== '' ? $gst : null,
            'frn' => $frn !== '' ? $frn : null,
            'membership_no' => $membership !== '' ? $membership : null,
            'website' => $website !== '' ? $website : null,
            'sales_remarks' => $salesRemarks !== '' ? $salesRemarks : null,
            'raw_mobile' => $mobileRaw,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function dedupeByPhoneKeepBest(array $rows): array
    {
        // Claim every normalized phone (primary or alternate). Keep the strongest row per phone,
        // then emit each surviving row once.
        $bestByPhone = [];
        $noPhone = [];

        foreach ($rows as $idx => $row) {
            $phones = array_values(array_unique(array_filter([
                $row['mobile_no'] ?? null,
                $row['alternate_mobile_no'] ?? null,
            ])));
            if ($phones === []) {
                $noPhone[] = $row;
                continue;
            }
            foreach ($phones as $phone) {
                if (! isset($bestByPhone[$phone]) || $this->rowScore($row) > $this->rowScore($bestByPhone[$phone]['row'])) {
                    $bestByPhone[$phone] = ['row' => $row, 'idx' => $idx];
                }
            }
        }

        $kept = [];
        foreach ($bestByPhone as $entry) {
            $kept[$entry['idx']] = $entry['row'];
        }
        $unique = array_values($kept);
        foreach ($noPhone as $row) {
            $unique[] = $row;
        }

        $removed = count($rows) - count($unique);

        return [$unique, max(0, $removed)];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowScore(array $row): int
    {
        $score = 0;
        if (! empty($row['gst_no'])) {
            $score += 1_000_000;
        }
        if (! empty($row['email_id'])) {
            $score += 100_000;
        }
        if (! empty($row['website'])) {
            $score += 10_000;
        }
        if (! empty($row['city'])) {
            $score += 1_000;
        }
        // state not in this sheet
        if (! empty($row['firm_name']) && $row['firm_name'] !== 'Unknown Firm') {
            $score += 10;
        }
        $score += strlen((string) ($row['sales_remarks'] ?? ''));

        return $score;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function toEnginePayload(array $row): array
    {
        return [
            'firm_name' => $row['firm_name'],
            'ca_name' => $row['ca_name'],
            'phone' => $row['mobile_no'],
            'alternate_mobile_no' => $row['alternate_mobile_no'],
            'email' => $row['email_id'],
            'city' => $row['city'] !== '' ? $row['city'] : null,
            'gst_no' => $row['gst_no'],
            'frn' => $row['frn'],
            'membership_no' => $row['membership_no'],
            'website' => $row['website'],
            'source_name' => 'Sales CSV Import',
            'overall_confidence' => 1.0,
            'field_meta' => [
                'phone' => ['confidence' => 1.0],
                'firm_name' => ['confidence' => 0.95],
                'ca_name' => ['confidence' => 0.95],
                'city' => ['confidence' => 0.95],
                'email' => ['confidence' => 1.0],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function applySalesRemarksAndEmail(int $caId, array $row, bool $isInsert): void
    {
        $lead = CaMaster::query()->where('ca_id', $caId)->first();
        if (! $lead) {
            return;
        }

        $dirty = false;
        $email = $row['email_id'] ?? null;
        if ($email && ($lead->email_id === null || trim((string) $lead->email_id) === '')) {
            $lead->email_id = $email;
            $dirty = true;
        }

        $remarks = $row['sales_remarks'] ?? null;
        if ($remarks && Schema::hasColumn('ca_masters', 'sales_remarks')) {
            $existing = $lead->sales_remarks;
            if ($isInsert || $existing === null || trim((string) $existing) === '') {
                $lead->sales_remarks = $remarks;
                $dirty = true;
            } else {
                $existingStr = trim((string) $existing);
                if (! str_contains($existingStr, $remarks)) {
                    $lead->sales_remarks = $existingStr."\n".$remarks;
                    $dirty = true;
                }
            }
        }

        if ($dirty) {
            $lead->save();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $uniqueRows
     * @return array<string, mixed>
     */
    private function verifyImport(array $uniqueRows): array
    {
        $failures = [];

        // Duplicate normalized_mobile check (among non-null).
        $dupPhones = 0;
        if (Schema::hasColumn('ca_masters', 'normalized_mobile')) {
            $dupPhones = (int) DB::table('ca_masters')
                ->select('normalized_mobile')
                ->whereNull('deleted_at')
                ->whereNotNull('normalized_mobile')
                ->where('normalized_mobile', '!=', '')
                ->groupBy('normalized_mobile')
                ->havingRaw('COUNT(*) > 1')
                ->get()
                ->count();
        } else {
            $dupPhones = -1;
        }

        if ($dupPhones > 0) {
            $failures[] = "duplicate_normalized_mobile_groups={$dupPhones}";
        }

        $sample = array_slice($uniqueRows, 0, 50);
        $emailMissing = 0;
        $cityMissing = 0;
        $remarksMissing = 0;
        $checkedEmail = 0;
        $checkedCity = 0;
        $checkedRemarks = 0;

        foreach ($sample as $row) {
            if (! empty($row['mobile_no'])) {
                $lead = CaMaster::query()
                    ->whereNull('deleted_at')
                    ->where(function ($q) use ($row) {
                        $q->where('mobile_no', $row['mobile_no'])
                            ->orWhere('normalized_mobile', $row['mobile_no']);
                    })
                    ->first();
                if (! $lead) {
                    continue;
                }
                if (! empty($row['email_id'])) {
                    $checkedEmail++;
                    if (strtolower((string) $lead->email_id) !== strtolower((string) $row['email_id'])
                        && trim((string) $lead->email_id) === '') {
                        $emailMissing++;
                    }
                }
                if (! empty($row['city'])) {
                    $checkedCity++;
                    $cityName = strtolower((string) ($lead->city?->city_name ?? $lead->ocr_city_text ?? ''));
                    if ($cityName === '' || ! str_contains($cityName, strtolower(substr((string) $row['city'], 0, 4)))) {
                        if ($lead->city_id === null) {
                            $cityMissing++;
                        }
                    }
                }
                if (! empty($row['sales_remarks']) && Schema::hasColumn('ca_masters', 'sales_remarks')) {
                    $checkedRemarks++;
                    if (trim((string) ($lead->sales_remarks ?? '')) === '') {
                        $remarksMissing++;
                    }
                }
            }
        }

        if ($emailMissing > 0) {
            $failures[] = "sample_email_not_on_master={$emailMissing}/{$checkedEmail}";
        }
        if ($cityMissing > 0) {
            $failures[] = "sample_city_missing_city_id={$cityMissing}/{$checkedCity}";
        }
        if ($remarksMissing > 0) {
            $failures[] = "sample_remarks_empty={$remarksMissing}/{$checkedRemarks}";
        }

        return [
            'ok' => $failures === [],
            'duplicate_normalized_mobile_groups' => $dupPhones,
            'sample_checked_email' => $checkedEmail,
            'sample_checked_city' => $checkedCity,
            'sample_checked_remarks' => $checkedRemarks,
            'failures' => $failures,
        ];
    }
}
