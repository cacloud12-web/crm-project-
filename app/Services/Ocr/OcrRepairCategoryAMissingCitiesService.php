<?php

namespace App\Services\Ocr;

use App\Models\ActivityLog;
use App\Models\CaMaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Apply ONLY classification Category A missing-city repairs.
 * Never touches B/C/D/E. Never overwrites a non-empty city_id.
 */
class OcrRepairCategoryAMissingCitiesService
{
    public function __construct(
        private readonly ?OcrMissingCityAuditService $audit = null,
    ) {}

    private function audit(): OcrMissingCityAuditService
    {
        return $this->audit ?? new OcrMissingCityAuditService;
    }

    /**
     * @param  array{
     *   apply?: bool,
     *   dry_run?: bool,
     *   classification?: string,
     *   cities_csv?: string|null,
     *   chunk?: int,
     *   export?: string|null,
     *   baseline_missing?: int|null,
     *   limit?: int
     * }  $options
     * @return array<string, mixed>
     */
    public function run(array $options = []): array
    {
        $apply = (bool) ($options['apply'] ?? false);
        $dryRun = ! $apply;
        $chunk = max(50, (int) ($options['chunk'] ?? 200));
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $classification = (string) ($options['classification']
            ?? storage_path('app/audits/ocr-linked-missing-cities-categories-prod-detail.csv'));
        $citiesCsv = $options['cities_csv'] ?? null;
        $stamp = date('Ymd_His');
        $export = $options['export'] ?? storage_path(
            'app/audits/repair-category-a-missing-cities-'.($dryRun ? 'dryrun' : 'apply').'-'.$stamp.'.csv'
        );
        $auditJson = preg_replace('/\.csv$/i', '.audit.json', (string) $export) ?: ((string) $export.'.audit.json');

        if (! is_file($classification)) {
            throw new RuntimeException('Classification CSV not found: '.$classification);
        }

        $cityIndex = $this->buildCityIndex(is_string($citiesCsv) && $citiesCsv !== '' ? $citiesCsv : null);
        $aliases = $this->audit()->localityAliases();

        $categoryA = $this->loadCategoryARows($classification, $limit);
        if ($categoryA === []) {
            throw new RuntimeException('No Category A rows found in classification CSV.');
        }

        $plans = [];
        $ambiguous = [];
        $unresolvable = [];
        $nonRecoverableDecision = [];

        foreach ($categoryA as $row) {
            $caId = (int) ($row['CA ID'] ?? 0);
            $decision = (string) ($row['Decision'] ?? '');
            $resolvedName = trim((string) ($row['Resolved City'] ?? ''));
            if ($resolvedName === '') {
                $resolvedName = trim((string) ($row['OCR City'] ?? ''));
            }

            if (! $this->audit()->isRecoverableDecision($decision)) {
                $nonRecoverableDecision[] = ['ca_id' => $caId, 'decision' => $decision];
                continue;
            }

            $hit = $this->audit()->tryResolveToCityId($resolvedName, $cityIndex, $aliases);
            if ($hit['status'] === 'ambiguous') {
                $ambiguous[] = [
                    'ca_id' => $caId,
                    'city' => $resolvedName,
                    'detail' => $hit['detail'] ?? 'ambiguous',
                ];
                continue;
            }
            if ($hit['status'] !== 'unique' || empty($hit['city_id'])) {
                $unresolvable[] = [
                    'ca_id' => $caId,
                    'city' => $resolvedName,
                    'status' => $hit['status'] ?? 'none',
                ];
                continue;
            }

            $plans[] = [
                'ca_id' => $caId,
                'firm_name' => (string) ($row['Firm Name'] ?? ''),
                'ocr_city' => (string) ($row['OCR City'] ?? ''),
                'raw_ocr_city' => (string) ($row['Raw OCR City'] ?? ''),
                'resolved_city' => (string) ($hit['display'] ?? $resolvedName),
                'city_id' => (int) $hit['city_id'],
                'decision' => $decision,
                'parser_stage' => (string) ($row['Parser Stage'] ?? ''),
                'evidence' => (string) ($row['Evidence Sources'] ?? ''),
                'category' => 'A',
            ];
        }

        if ($ambiguous !== []) {
            throw new RuntimeException(
                'ABORT: '.count($ambiguous).' Category A row(s) have ambiguous city mapping. No rows updated. First: '
                .json_encode($ambiguous[0])
            );
        }
        if ($nonRecoverableDecision !== []) {
            throw new RuntimeException(
                'ABORT: '.count($nonRecoverableDecision).' Category A CSV row(s) lack a recoverable decision. No rows updated. First: '
                .json_encode($nonRecoverableDecision[0])
            );
        }
        if ($unresolvable !== []) {
            throw new RuntimeException(
                'ABORT: '.count($unresolvable).' Category A row(s) do not uniquely resolve to cities.city_id. No rows updated. First: '
                .json_encode($unresolvable[0])
            );
        }

        $beforeMissing = $this->countMissingCity();
        $baselineMissing = array_key_exists('baseline_missing', $options) && $options['baseline_missing'] !== null
            ? (int) $options['baseline_missing']
            : $beforeMissing;

        $dir = dirname((string) $export);
        if ($dir !== '' && $dir !== '.' && ! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $fh = fopen((string) $export, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Unable to open export CSV: '.$export);
        }
        fputcsv($fh, [
            'CA ID',
            'Firm Name',
            'Before city_id',
            'After city_id',
            'Resolved City',
            'OCR City',
            'Raw OCR City',
            'Decision',
            'Parser Stage',
            'Evidence Sources',
            'Category',
            'Status',
            'Applied',
        ]);

        $counts = [
            'category_a_csv_rows' => count($categoryA),
            'planned' => count($plans),
            'would_update' => 0,
            'updated' => 0,
            'skipped_has_city' => 0,
            'skipped_not_found' => 0,
            'errors' => 0,
        ];
        $auditRows = [];
        $pending = [];

        foreach ($plans as $plan) {
            $master = DB::table('ca_masters')
                ->where('ca_id', $plan['ca_id'])
                ->first(['ca_id', 'firm_name', 'city_id', 'deleted_at']);

            if (! $master || (Schema::hasColumn('ca_masters', 'deleted_at') && $master->deleted_at !== null)) {
                if ($dryRun) {
                    // Offline / cross-DB dry-run: classification row is still a planned Category A update.
                    $counts['would_update']++;
                    $this->putCsv($fh, $plan, null, $plan['city_id'], 'would_update', false);
                    $auditRows[] = $this->auditEntry($plan, null, $plan['city_id'], 'would_update', false);
                } else {
                    $counts['skipped_not_found']++;
                    $this->putCsv($fh, $plan, null, null, 'master_not_in_database', false);
                    $auditRows[] = $this->auditEntry($plan, null, null, 'master_not_in_database', false);
                }
                continue;
            }

            $beforeCityId = $master->city_id !== null && (int) $master->city_id > 0
                ? (int) $master->city_id
                : null;

            if ($beforeCityId !== null) {
                $counts['skipped_has_city']++;
                $this->putCsv($fh, $plan, $beforeCityId, $beforeCityId, 'skipped_already_has_city', false);
                $auditRows[] = $this->auditEntry($plan, $beforeCityId, $beforeCityId, 'skipped_already_has_city', false);
                continue;
            }

            $counts['would_update']++;

            if ($dryRun) {
                $this->putCsv($fh, $plan, null, $plan['city_id'], 'would_update', false);
                $auditRows[] = $this->auditEntry($plan, null, $plan['city_id'], 'would_update', false);
                continue;
            }

            $pending[] = $plan;
            if (count($pending) >= $chunk) {
                $counts['updated'] += $this->flushApplyChunk($pending, $fh, $auditRows);
                $pending = [];
            }
        }

        if (! $dryRun && $pending !== []) {
            $counts['updated'] += $this->flushApplyChunk($pending, $fh, $auditRows);
        }

        fclose($fh);

        $afterMissing = $this->countMissingCity();
        $estimatedAfter = max(0, $baselineMissing - $counts['planned']);

        $result = [
            'dry_run' => $dryRun,
            'apply' => ! $dryRun,
            'before_missing' => $beforeMissing,
            'baseline_missing' => $baselineMissing,
            'current_missing_city' => $baselineMissing,
            'recoverable_now' => $counts['planned'],
            'remaining_after_category_a' => $estimatedAfter,
            'would_update' => $counts['would_update'],
            'updated' => $counts['updated'],
            'after_missing' => $afterMissing,
            'skipped_has_city' => $counts['skipped_has_city'],
            'skipped_not_found' => $counts['skipped_not_found'],
            'errors' => $counts['errors'],
            'export_path' => $export,
            'audit_json_path' => $auditJson,
            'classification' => $classification,
            'cities_source' => is_string($citiesCsv) && $citiesCsv !== '' ? $citiesCsv : 'database:cities',
            'counts' => $counts,
        ];

        file_put_contents($auditJson, json_encode([
            'ran_at' => date('c'),
            'mode' => $dryRun ? 'dry-run' : 'apply',
            'result' => $result,
            'rows' => $auditRows,
        ], JSON_PRETTY_PRINT));

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $pending
     * @param  resource  $fh
     * @param  list<array<string, mixed>>  $auditRows
     */
    private function flushApplyChunk(array $pending, $fh, array &$auditRows): int
    {
        $updated = 0;
        DB::transaction(function () use ($pending, $fh, &$auditRows, &$updated) {
            foreach ($pending as $plan) {
                /** @var CaMaster|null $master */
                $master = CaMaster::query()->lockForUpdate()->find($plan['ca_id']);
                if (! $master) {
                    $this->putCsv($fh, $plan, null, null, 'master_not_in_database', false);
                    $auditRows[] = $this->auditEntry($plan, null, null, 'master_not_in_database', false);
                    continue;
                }
                $before = $master->city_id !== null && (int) $master->city_id > 0
                    ? (int) $master->city_id
                    : null;
                if ($before !== null) {
                    $this->putCsv($fh, $plan, $before, $before, 'skipped_already_has_city', false);
                    $auditRows[] = $this->auditEntry($plan, $before, $before, 'skipped_already_has_city', false);
                    continue;
                }

                $master->city_id = (int) $plan['city_id'];
                foreach (\App\Support\Ocr\CaMasterCityQuality::attributesAfterRealCityLinked($master) as $key => $value) {
                    $master->{$key} = $value;
                }
                $master->saveQuietly();
                $updated++;

                $this->writeActivityLog(
                    (int) $plan['ca_id'],
                    $before,
                    (int) $plan['city_id'],
                    (string) $plan['resolved_city'],
                    (string) $plan['decision']
                );

                $this->putCsv($fh, $plan, null, (int) $plan['city_id'], 'updated', true);
                $auditRows[] = $this->auditEntry($plan, null, (int) $plan['city_id'], 'updated', true);
            }
        });

        return $updated;
    }

    /**
     * @param  resource  $fh
     * @param  array<string, mixed>  $plan
     */
    private function putCsv($fh, array $plan, mixed $before, mixed $after, string $status, bool $applied): void
    {
        fputcsv($fh, [
            $plan['ca_id'],
            $plan['firm_name'],
            $before ?? '',
            $after ?? '',
            $plan['resolved_city'],
            $plan['ocr_city'],
            $plan['raw_ocr_city'],
            $plan['decision'],
            $plan['parser_stage'],
            $plan['evidence'],
            'A',
            $status,
            $applied ? 'yes' : 'no',
        ]);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function auditEntry(array $plan, mixed $before, mixed $after, string $status, bool $applied): array
    {
        return [
            'ca_id' => $plan['ca_id'],
            'firm_name' => $plan['firm_name'],
            'before_city_id' => $before,
            'after_city_id' => $after,
            'resolved_city' => $plan['resolved_city'],
            'decision' => $plan['decision'],
            'parser_stage' => $plan['parser_stage'],
            'category' => 'A',
            'status' => $status,
            'applied' => $applied,
        ];
    }

    private function writeActivityLog(int $caId, mixed $before, int $afterCityId, string $cityName, string $decision): void
    {
        if (! Schema::hasTable('activity_logs')) {
            return;
        }
        try {
            ActivityLog::query()->create([
                'performed_by' => null,
                'module_name' => 'CA_MASTER',
                'record_id' => $caId,
                'action' => 'Update Lead',
                'description' => 'OCR Category A missing-city repair: set city_id from '.$decision,
                'before_value' => json_encode(['city_id' => $before]),
                'after_value' => json_encode(['city_id' => $afterCityId, 'city' => $cityName, 'category' => 'A']),
                'ip_address' => 'cli',
            ]);
        } catch (Throwable) {
            // Audit must not roll back a successful city_id update.
        }
    }

    private function countMissingCity(): int
    {
        $q = DB::table('ca_masters')->where(function ($w) {
            $w->whereNull('city_id')->orWhere('city_id', 0);
        });
        if (Schema::hasColumn('ca_masters', 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        return (int) $q->count();
    }

    /**
     * @return list<array<string, string>>
     */
    private function loadCategoryARows(string $path, int $limit = 0): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Unable to open classification CSV: '.$path);
        }
        $header = fgetcsv($fh);
        if ($header === false) {
            fclose($fh);
            throw new RuntimeException('Classification CSV is empty.');
        }
        $rows = [];
        while (($row = fgetcsv($fh)) !== false) {
            $map = array_combine($header, $row);
            if ($map === false) {
                continue;
            }
            if (strtoupper(trim((string) ($map['Category'] ?? ''))) !== 'A') {
                continue;
            }
            $rows[] = $map;
            if ($limit > 0 && count($rows) >= $limit) {
                break;
            }
        }
        fclose($fh);

        return $rows;
    }

    /**
     * @return array<string, int|null>
     */
    private function buildCityIndex(?string $citiesCsv): array
    {
        if ($citiesCsv !== null && $citiesCsv !== '') {
            if (! is_file($citiesCsv)) {
                throw new RuntimeException('Cities CSV not found: '.$citiesCsv);
            }
            $index = [];
            $fh = fopen($citiesCsv, 'rb');
            if ($fh === false) {
                throw new RuntimeException('Unable to open cities CSV: '.$citiesCsv);
            }
            $header = fgetcsv($fh);
            while (($row = fgetcsv($fh)) !== false) {
                $map = array_combine($header ?: [], $row);
                if ($map === false) {
                    continue;
                }
                $name = trim((string) ($map['city_name'] ?? ''));
                $id = (int) ($map['city_id'] ?? 0);
                if ($name === '' || $id <= 0) {
                    continue;
                }
                $key = $this->audit()->normKey($name);
                if ($key === '') {
                    continue;
                }
                if (! array_key_exists($key, $index)) {
                    $index[$key] = $id;
                } elseif ($index[$key] !== $id) {
                    $index[$key] = null;
                }
            }
            fclose($fh);

            return $index;
        }

        return $this->audit()->buildCityNameIndex();
    }
}
