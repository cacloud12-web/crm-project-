<?php

namespace App\Services\Ocr;

use App\Models\ActivityLog;
use App\Models\CaMaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Safe Master city_id repair only.
 * Never touches firm/CA/business fields. Never overwrites a non-empty city_id.
 * Updates only Category A (recoverable unique OCR→cities match).
 */
class OcrRepairMissingCitiesService
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
     *   dry_run?: bool,
     *   apply?: bool,
     *   limit?: int,
     *   chunk?: int,
     *   export?: string|null,
     *   include_deleted?: bool,
     *   ocr_linked_only?: bool
     * }  $options
     * @return array<string, mixed>
     */
    public function run(array $options = []): array
    {
        $apply = (bool) ($options['apply'] ?? false);
        $dryRun = ! $apply;
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $chunk = max(50, (int) ($options['chunk'] ?? 200));
        $includeDeleted = (bool) ($options['include_deleted'] ?? false);
        $ocrLinkedOnly = (bool) ($options['ocr_linked_only'] ?? true);
        $stamp = date('Ymd_His');
        $export = $options['export'] ?? storage_path(
            'app/audits/repair-missing-cities-'.($dryRun ? 'dryrun' : 'apply').'-'.$stamp.'.csv'
        );
        $auditJson = preg_replace('/\.csv$/i', '.audit.json', (string) $export)
            ?: ((string) $export.'.audit.json');
        $rollbackPath = preg_replace('/\.csv$/i', '.rollback.json', (string) $export)
            ?: ((string) $export.'.rollback.json');

        $cityIndex = $this->audit()->buildCityNameIndex();
        $aliases = $this->audit()->localityAliases();

        $totalMissing = $this->countMissingCity($includeDeleted);
        $ocrLinkedMissing = $this->countMissingCity($includeDeleted, true);

        $counts = [
            'total_missing_city' => $totalMissing,
            'ocr_linked_missing' => $ocrLinkedMissing,
            'scanned' => 0,
            'eligible_category_a' => 0,
            'would_update' => 0,
            'updated' => 0,
            'skipped' => 0,
            'skipped_has_city' => 0,
            'skipped_ambiguous' => 0,
            'skipped_no_info' => 0,
            'skipped_locality' => 0,
            'skipped_uncertain' => 0,
            'skipped_not_category_a' => 0,
            'by_class' => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0],
            'errors' => 0,
            'ocr_linked_only' => $ocrLinkedOnly,
        ];

        $dir = dirname((string) $export);
        if ($dir !== '' && $dir !== '.' && ! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $fh = fopen((string) $export, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Unable to open export: '.$export);
        }
        fputcsv($fh, [
            'ca_id',
            'firm_name',
            'old_city_id',
            'new_city_id',
            'ocr_city',
            'source_ocr_row_id',
            'source_ocr_document_id',
            'ae_class',
            'decision',
            'reason',
            'status',
            'applied',
            'timestamp',
        ]);

        $pending = [];
        $auditRows = [];
        $rollbackRows = [];
        $ranAt = date('c');

        $query = DB::table('ca_masters')
            ->where(function ($q) {
                $q->whereNull('city_id')->orWhere('city_id', 0);
            })
            ->orderBy('ca_id');
        if (! $includeDeleted && Schema::hasColumn('ca_masters', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        if ($ocrLinkedOnly && Schema::hasColumn('ca_masters', 'source_ocr_row_id')) {
            $query->whereNotNull('source_ocr_row_id');
        }

        $select = ['ca_id', 'firm_name', 'city_id'];
        if (Schema::hasColumn('ca_masters', 'ocr_city_text')) {
            $select[] = 'ocr_city_text';
        }
        if (Schema::hasColumn('ca_masters', 'source_ocr_row_id')) {
            $select[] = 'source_ocr_row_id';
        }
        if (Schema::hasColumn('ca_masters', 'source_ocr_document_id')) {
            $select[] = 'source_ocr_document_id';
        }

        $query->select($select)
            ->chunkById($chunk, function ($masters) use (
                &$counts,
                &$pending,
                &$auditRows,
                &$rollbackRows,
                $limit,
                $chunk,
                $dryRun,
                $apply,
                $cityIndex,
                $aliases,
                $fh,
                $ranAt
            ) {
                foreach ($masters as $master) {
                    if ($limit > 0 && $counts['scanned'] >= $limit) {
                        return false;
                    }
                    $counts['scanned']++;

                    $plan = $this->audit()->classifyMaster($master, $cityIndex, $aliases);
                    $ae = (string) ($plan['ae_class'] ?? 'E');
                    $counts['by_class'][$ae] = ($counts['by_class'][$ae] ?? 0) + 1;

                    $rowId = isset($master->source_ocr_row_id) && $master->source_ocr_row_id !== null
                        ? (int) $master->source_ocr_row_id
                        : null;
                    $docId = isset($master->source_ocr_document_id) && $master->source_ocr_document_id !== null
                        ? (int) $master->source_ocr_document_id
                        : null;
                    $ocrCity = (string) ($plan['raw_ocr_city'] ?? $plan['ocr_city'] ?? '');
                    $decision = (string) ($plan['decision'] ?? '');
                    $reason = (string) ($plan['failure_reason'] ?? $plan['reason'] ?? '');
                    $oldCityId = $plan['current_city_id'] ?? null;
                    $newCityId = $plan['proposed_city_id'] ?? $plan['resolved_city_id'] ?? null;

                    $isCategoryA = $ae === OcrMissingCityAuditService::CLASS_A
                        && $this->audit()->isRecoverableDecision($decision)
                        && ! empty($newCityId);

                    if (! $isCategoryA) {
                        $counts['skipped']++;
                        if ($ae !== OcrMissingCityAuditService::CLASS_A
                            && $this->audit()->isRecoverableDecision($decision)
                            && ! empty($newCityId)) {
                            $counts['skipped_not_category_a']++;
                        } else {
                            match ($decision) {
                                OcrMissingCityAuditService::DECISION_SKIP_HAS_CITY => $counts['skipped_has_city']++,
                                OcrMissingCityAuditService::DECISION_SKIP_AMBIGUOUS => $counts['skipped_ambiguous']++,
                                OcrMissingCityAuditService::DECISION_SKIP_NO_OCR => $counts['skipped_no_info']++,
                                OcrMissingCityAuditService::DECISION_SKIP_LOCALITY,
                                OcrMissingCityAuditService::DECISION_SKIP_CITY_TABLE_GAP => $counts['skipped_locality']++,
                                default => $counts['skipped_uncertain']++,
                            };
                        }

                        $entry = $this->auditEntry(
                            (int) $plan['ca_id'],
                            (string) ($plan['firm_name'] ?? ''),
                            $oldCityId,
                            null,
                            $ocrCity,
                            $rowId,
                            $docId,
                            $ae,
                            $decision,
                            $reason !== '' ? $reason : $decision,
                            'skipped',
                            false,
                            $ranAt
                        );
                        $this->putCsv($fh, $entry);
                        $auditRows[] = $entry;

                        continue;
                    }

                    $counts['eligible_category_a']++;
                    $counts['would_update']++;

                    $entryBase = [
                        'ca_id' => (int) $plan['ca_id'],
                        'firm_name' => (string) ($plan['firm_name'] ?? ''),
                        'old_city_id' => null,
                        'new_city_id' => (int) $newCityId,
                        'ocr_city' => $ocrCity,
                        'resolved_city' => (string) ($plan['proposed_city'] ?? $plan['resolved_city'] ?? ''),
                        'source_ocr_row_id' => $rowId,
                        'source_ocr_document_id' => $docId,
                        'ae_class' => $ae,
                        'decision' => $decision,
                        'reason' => $reason !== '' ? $reason : $decision,
                    ];

                    if ($dryRun) {
                        $entry = $this->auditEntry(
                            $entryBase['ca_id'],
                            $entryBase['firm_name'],
                            null,
                            $entryBase['new_city_id'],
                            $ocrCity,
                            $rowId,
                            $docId,
                            $ae,
                            $decision,
                            $entryBase['reason'],
                            'would_update',
                            false,
                            $ranAt
                        );
                        $this->putCsv($fh, $entry);
                        $auditRows[] = $entry;

                        continue;
                    }

                    $pending[] = $entryBase;
                    if (count($pending) >= $chunk) {
                        $counts['updated'] += $this->flushApplyChunk($pending, $fh, $auditRows, $rollbackRows, $ranAt);
                        $pending = [];
                    }
                }

                return $limit <= 0 || $counts['scanned'] < $limit;
            }, 'ca_id');

        if ($apply && ! $dryRun && $pending !== []) {
            $counts['updated'] += $this->flushApplyChunk($pending, $fh, $auditRows, $rollbackRows, $ranAt);
        }

        fclose($fh);

        $result = [
            'dry_run' => $dryRun,
            'apply' => $apply && ! $dryRun,
            'total_missing_city' => $counts['total_missing_city'],
            'ocr_linked_missing' => $counts['ocr_linked_missing'],
            'scanned' => $counts['scanned'],
            'eligible_category_a' => $counts['eligible_category_a'],
            'would_update' => $counts['would_update'],
            'updated' => $counts['updated'],
            'skipped' => $counts['skipped'],
            'skipped_has_city' => $counts['skipped_has_city'],
            'skipped_ambiguous' => $counts['skipped_ambiguous'],
            'skipped_no_info' => $counts['skipped_no_info'],
            'skipped_locality' => $counts['skipped_locality'],
            'skipped_uncertain' => $counts['skipped_uncertain'],
            'skipped_not_category_a' => $counts['skipped_not_category_a'],
            'by_class' => $counts['by_class'],
            'errors' => $counts['errors'],
            'success_pct' => $counts['scanned'] > 0
                ? round(100 * $counts['would_update'] / $counts['scanned'], 2)
                : 0.0,
            'ocr_linked_only' => $ocrLinkedOnly,
            'export_path' => $export,
            'audit_json_path' => $auditJson,
            'rollback_path' => $rollbackPath,
        ];

        file_put_contents($auditJson, json_encode([
            'ran_at' => $ranAt,
            'mode' => $dryRun ? 'dry-run' : 'apply',
            'result' => $result,
            'rows' => $auditRows,
        ], JSON_PRETTY_PRINT));

        file_put_contents($rollbackPath, json_encode([
            'ran_at' => $ranAt,
            'mode' => $dryRun ? 'dry-run' : 'apply',
            'field' => 'city_id',
            'note' => 'Pass this file to ocr:repair-missing-cities --rollback= to restore previous city_id only when still equal to applied value.',
            'rows' => $rollbackRows,
        ], JSON_PRETTY_PRINT));

        return $result;
    }

    /**
     * Restore city_id from a prior apply rollback JSON.
     * Only restores when current city_id still equals the applied value.
     *
     * @return array<string, mixed>
     */
    public function rollback(string $rollbackPath, bool $apply, int $chunk = 200): array
    {
        if (! is_file($rollbackPath)) {
            throw new RuntimeException('Rollback file not found: '.$rollbackPath);
        }
        $payload = json_decode((string) file_get_contents($rollbackPath), true);
        if (! is_array($payload) || ! isset($payload['rows']) || ! is_array($payload['rows'])) {
            throw new RuntimeException('Invalid rollback JSON.');
        }

        $rows = array_values(array_filter($payload['rows'], static fn ($r) => ! empty($r['applied'])));
        $export = storage_path('app/audits/repair-missing-cities-rollback-'.date('Ymd_His').'.csv');
        $fh = fopen($export, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Unable to open rollback export CSV.');
        }
        fputcsv($fh, [
            'ca_id',
            'before_city_id',
            'restored_city_id',
            'status',
            'applied',
        ]);

        $would = 0;
        $undone = 0;
        $skipped = 0;
        $pending = [];

        foreach ($rows as $r) {
            $caId = (int) ($r['ca_id'] ?? 0);
            $appliedCity = isset($r['after_city_id']) ? (int) $r['after_city_id'] : null;
            $restoreCity = array_key_exists('before_city_id', $r) ? $r['before_city_id'] : null;
            if ($restoreCity !== null && $restoreCity !== '') {
                $restoreCity = (int) $restoreCity;
                if ($restoreCity <= 0) {
                    $restoreCity = null;
                }
            } else {
                $restoreCity = null;
            }

            $master = CaMaster::query()->find($caId);
            if (! $master) {
                $skipped++;
                fputcsv($fh, [$caId, '', '', 'master_not_found', 'no']);
                continue;
            }
            $current = $master->city_id !== null && (int) $master->city_id > 0
                ? (int) $master->city_id
                : null;

            if ($current !== $appliedCity) {
                $skipped++;
                fputcsv($fh, [$caId, $current, $restoreCity, 'skipped_city_changed', 'no']);
                continue;
            }

            $would++;
            if (! $apply) {
                fputcsv($fh, [$caId, $current, $restoreCity, 'would_rollback', 'no']);
                continue;
            }

            $pending[] = [
                'ca_id' => $caId,
                'restore_city_id' => $restoreCity,
                'applied_city_id' => $appliedCity,
            ];
            if (count($pending) >= $chunk) {
                $undone += $this->flushRollbackChunk($pending, $fh);
                $pending = [];
            }
        }

        if ($apply && $pending !== []) {
            $undone += $this->flushRollbackChunk($pending, $fh);
        }
        fclose($fh);

        return [
            'dry_run' => ! $apply,
            'candidates' => count($rows),
            'would_rollback' => $would,
            'rolled_back' => $undone,
            'skipped' => $skipped,
            'export_path' => $export,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $pending
     * @param  resource  $fh
     * @param  list<array<string, mixed>>  $auditRows
     * @param  list<array<string, mixed>>  $rollbackRows
     */
    private function flushApplyChunk(
        array $pending,
        $fh,
        array &$auditRows,
        array &$rollbackRows,
        string $ranAt,
    ): int {
        $updated = 0;
        DB::transaction(function () use ($pending, $fh, &$auditRows, &$rollbackRows, &$updated, $ranAt) {
            foreach ($pending as $plan) {
                /** @var CaMaster|null $master */
                $master = CaMaster::query()->lockForUpdate()->find($plan['ca_id']);
                if (! $master) {
                    $entry = $this->auditEntry(
                        (int) $plan['ca_id'],
                        (string) $plan['firm_name'],
                        null,
                        null,
                        (string) $plan['ocr_city'],
                        $plan['source_ocr_row_id'],
                        $plan['source_ocr_document_id'],
                        (string) $plan['ae_class'],
                        (string) $plan['decision'],
                        'master_not_found',
                        'skipped',
                        false,
                        $ranAt
                    );
                    $this->putCsv($fh, $entry);
                    $auditRows[] = $entry;
                    continue;
                }

                $before = $master->city_id !== null && (int) $master->city_id > 0
                    ? (int) $master->city_id
                    : null;
                if ($before !== null) {
                    $entry = $this->auditEntry(
                        (int) $plan['ca_id'],
                        (string) ($master->firm_name ?? $plan['firm_name']),
                        $before,
                        $before,
                        (string) $plan['ocr_city'],
                        $plan['source_ocr_row_id'],
                        $plan['source_ocr_document_id'],
                        (string) $plan['ae_class'],
                        (string) $plan['decision'],
                        'city_id already set',
                        'skipped_already_has_city',
                        false,
                        $ranAt
                    );
                    $this->putCsv($fh, $entry);
                    $auditRows[] = $entry;
                    continue;
                }

                $after = (int) $plan['new_city_id'];
                $master->city_id = $after;
                // City link only for firm/CA fields — but clear stale missing_city quality flag.
                foreach (\App\Support\Ocr\CaMasterCityQuality::attributesAfterRealCityLinked($master) as $key => $value) {
                    $master->{$key} = $value;
                }
                $master->saveQuietly();
                $updated++;

                $this->writeActivityLog(
                    (int) $plan['ca_id'],
                    $before,
                    $after,
                    (string) ($plan['resolved_city'] ?? $plan['ocr_city'] ?? ''),
                    (string) $plan['decision']
                );

                $entry = $this->auditEntry(
                    (int) $plan['ca_id'],
                    (string) ($master->firm_name ?? $plan['firm_name']),
                    null,
                    $after,
                    (string) $plan['ocr_city'],
                    $plan['source_ocr_row_id'],
                    $plan['source_ocr_document_id'],
                    (string) $plan['ae_class'],
                    (string) $plan['decision'],
                    (string) $plan['reason'],
                    'updated',
                    true,
                    $ranAt
                );
                $this->putCsv($fh, $entry);
                $auditRows[] = $entry;
                $rollbackRows[] = [
                    'ca_id' => (int) $plan['ca_id'],
                    'firm_name' => (string) ($master->firm_name ?? $plan['firm_name']),
                    'before_city_id' => null,
                    'after_city_id' => $after,
                    'ocr_city' => (string) $plan['ocr_city'],
                    'source_ocr_row_id' => $plan['source_ocr_row_id'],
                    'source_ocr_document_id' => $plan['source_ocr_document_id'],
                    'decision' => (string) $plan['decision'],
                    'applied' => true,
                    'timestamp' => $ranAt,
                ];
            }
        });

        return $updated;
    }

    /**
     * @param  list<array<string, mixed>>  $pending
     * @param  resource  $fh
     */
    private function flushRollbackChunk(array $pending, $fh): int
    {
        $n = 0;
        DB::transaction(function () use ($pending, $fh, &$n) {
            foreach ($pending as $item) {
                /** @var CaMaster|null $master */
                $master = CaMaster::query()->lockForUpdate()->find($item['ca_id']);
                if (! $master) {
                    fputcsv($fh, [$item['ca_id'], '', '', 'master_not_found', 'no']);
                    continue;
                }
                $current = $master->city_id !== null && (int) $master->city_id > 0
                    ? (int) $master->city_id
                    : null;
                if ($current !== $item['applied_city_id']) {
                    fputcsv($fh, [$item['ca_id'], $current, $item['restore_city_id'], 'skipped_city_changed', 'no']);
                    continue;
                }

                $master->city_id = $item['restore_city_id'];
                $master->saveQuietly();
                $n++;

                $this->writeActivityLog(
                    (int) $item['ca_id'],
                    $current,
                    $item['restore_city_id'] !== null ? (int) $item['restore_city_id'] : 0,
                    '',
                    'rollback'
                );

                fputcsv($fh, [
                    $item['ca_id'],
                    $current,
                    $item['restore_city_id'],
                    'rolled_back',
                    'yes',
                ]);
            }
        });

        return $n;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditEntry(
        int $caId,
        string $firmName,
        mixed $oldCityId,
        mixed $newCityId,
        string $ocrCity,
        mixed $rowId,
        mixed $docId,
        string $aeClass,
        string $decision,
        string $reason,
        string $status,
        bool $applied,
        string $timestamp,
    ): array {
        return [
            'ca_id' => $caId,
            'firm_name' => $firmName,
            'old_city_id' => $oldCityId,
            'new_city_id' => $newCityId,
            'ocr_city' => $ocrCity,
            'source_ocr_row_id' => $rowId,
            'source_ocr_document_id' => $docId,
            'ae_class' => $aeClass,
            'decision' => $decision,
            'reason' => $reason,
            'status' => $status,
            'applied' => $applied,
            'timestamp' => $timestamp,
        ];
    }

    /**
     * @param  resource  $fh
     * @param  array<string, mixed>  $entry
     */
    private function putCsv($fh, array $entry): void
    {
        fputcsv($fh, [
            $entry['ca_id'],
            $entry['firm_name'],
            $entry['old_city_id'] ?? '',
            $entry['new_city_id'] ?? '',
            $entry['ocr_city'] ?? '',
            $entry['source_ocr_row_id'] ?? '',
            $entry['source_ocr_document_id'] ?? '',
            $entry['ae_class'] ?? '',
            $entry['decision'] ?? '',
            $entry['reason'] ?? '',
            $entry['status'] ?? '',
            ! empty($entry['applied']) ? 'yes' : 'no',
            $entry['timestamp'] ?? date('c'),
        ]);
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
                'description' => $decision === 'rollback'
                    ? 'OCR missing-city repair rollback: restore city_id'
                    : 'OCR missing-city Category A repair: set city_id from '.$decision,
                'before_value' => json_encode(['city_id' => $before]),
                'after_value' => json_encode([
                    'city_id' => $afterCityId > 0 ? $afterCityId : null,
                    'city' => $cityName !== '' ? $cityName : null,
                    'category' => 'A',
                ]),
                'ip_address' => 'cli',
            ]);
        } catch (Throwable) {
            // Audit must not roll back a successful city_id update.
        }
    }

    private function countMissingCity(bool $includeDeleted = false, bool $ocrLinkedOnly = false): int
    {
        $q = DB::table('ca_masters')->where(function ($w) {
            $w->whereNull('city_id')->orWhere('city_id', 0);
        });
        if (! $includeDeleted && Schema::hasColumn('ca_masters', 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        if ($ocrLinkedOnly && Schema::hasColumn('ca_masters', 'source_ocr_row_id')) {
            $q->whereNotNull('source_ocr_row_id');
        }

        return (int) $q->count();
    }
}
