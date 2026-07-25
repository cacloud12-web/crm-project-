<?php

namespace App\Services\Ocr;

use App\Models\ActivityLog;
use App\Models\CaMaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Restore source_ocr_row_id / source_ocr_document_id for Exact offline matches only.
 * Never writes city_id or business fields. Never overwrites an existing OCR link.
 */
class OcrRelinkExactOfflineMatchesService
{
    public function __construct(
        private readonly ?OcrOfflineNoLinkFirmMatchService $matcher = null,
    ) {}

    private function matcher(): OcrOfflineNoLinkFirmMatchService
    {
        return $this->matcher ?? new OcrOfflineNoLinkFirmMatchService;
    }

    /**
     * @param  array{
     *   apply?: bool,
     *   matches_csv?: string,
     *   chunk?: int,
     *   export?: string|null,
     *   limit?: int,
     *   trust_csv_ids?: bool,
     *   skip_duplicate_ocr_ids?: bool,
     *   skip_already_linked_ocr_ids?: bool
     * }  $options
     * @return array<string, mixed>
     */
    public function run(array $options = []): array
    {
        $apply = (bool) ($options['apply'] ?? false);
        $dryRun = ! $apply;
        $chunk = max(50, (int) ($options['chunk'] ?? 200));
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $trustCsvIds = (bool) ($options['trust_csv_ids'] ?? false);
        $skipDupOcrIds = (bool) ($options['skip_duplicate_ocr_ids'] ?? false);
        $skipAlreadyLinked = (bool) ($options['skip_already_linked_ocr_ids'] ?? false);
        $matchesPath = (string) ($options['matches_csv']
            ?? storage_path('app/audits/no-ocr-link-offline-firm-matches.csv'));
        $stamp = date('Ymd_His');
        $export = $options['export'] ?? storage_path(
            'app/audits/relink-exact-ocr-'.($dryRun ? 'dryrun' : 'apply').'-'.$stamp.'.csv'
        );
        $auditJson = preg_replace('/\.csv$/i', '.audit.json', (string) $export)
            ?: ((string) $export.'.audit.json');
        $rollbackPath = preg_replace('/\.csv$/i', '.rollback.json', (string) $export)
            ?: ((string) $export.'.rollback.json');

        if (! is_file($matchesPath)) {
            throw new RuntimeException('Matches CSV not found: '.$matchesPath);
        }

        $exactRows = $this->loadExactRows($matchesPath, $limit);
        if ($exactRows === []) {
            throw new RuntimeException('No Confidence=Exact rows found in matches CSV.');
        }

        // Build OCR firm lookup from current DB (memory index — no SQL join to ca_masters).
        $ocrById = [];
        $ocrByNorm = [];
        if (Schema::hasTable('ocr_parsed_firms')) {
            foreach (DB::table('ocr_parsed_firms')->select(['id', 'firm_name', 'city', 'ocr_document_id'])->cursor() as $f) {
                $ocrById[(int) $f->id] = $f;
                $k = $this->matcher()->normalizeFirmName((string) $f->firm_name);
                if ($k !== null) {
                    $ocrByNorm[$k][] = $f;
                }
            }
        }

        $plans = [];
        $skipped = [
            'missing_ids' => 0,
            'unresolvable_ocr' => 0,
            'ambiguous_ocr' => 0,
        ];

        foreach ($exactRows as $row) {
            $caId = (int) ($row['CA ID'] ?? 0);
            $csvFirmId = (int) ($row['OCR Firm ID'] ?? 0);
            $csvDocId = (int) ($row['OCR Document ID'] ?? 0);
            $norm = trim((string) ($row['Normalized Key'] ?? ''));
            if ($norm === '') {
                $norm = (string) ($this->matcher()->normalizeFirmName((string) ($row['Master Firm Name'] ?? '')) ?? '');
            }

            $resolved = $this->resolveOcrTarget(
                $csvFirmId,
                $csvDocId,
                $norm,
                (string) ($row['Master Firm Name'] ?? ''),
                $ocrById,
                $ocrByNorm,
                $trustCsvIds
            );

            if ($resolved['status'] !== 'ok') {
                $skipped[$resolved['status']] = ($skipped[$resolved['status']] ?? 0) + 1;
                $plans[] = [
                    'ca_id' => $caId,
                    'firm_name' => (string) ($row['Master Firm Name'] ?? ''),
                    'ocr_city' => (string) ($row['OCR City'] ?? ''),
                    'confidence' => 'Exact',
                    'csv_ocr_firm_id' => $csvFirmId,
                    'csv_ocr_document_id' => $csvDocId,
                    'source_ocr_row_id' => null,
                    'source_ocr_document_id' => null,
                    'resolve_status' => $resolved['status'],
                    'resolve_detail' => $resolved['detail'] ?? '',
                    'eligible' => false,
                ];
                continue;
            }

            $plans[] = [
                'ca_id' => $caId,
                'firm_name' => (string) ($row['Master Firm Name'] ?? ''),
                'ocr_city' => (string) ($row['OCR City'] ?? $resolved['city'] ?? ''),
                'confidence' => 'Exact',
                'csv_ocr_firm_id' => $csvFirmId,
                'csv_ocr_document_id' => $csvDocId,
                'source_ocr_row_id' => (int) $resolved['ocr_firm_id'],
                'source_ocr_document_id' => (int) $resolved['ocr_document_id'],
                'resolve_status' => 'ok',
                'resolve_detail' => $resolved['via'] ?? '',
                'eligible' => true,
            ];
        }

        // Ambiguous OCR matches are skipped (not aborted) so unique Exact rows can proceed.
        $skippedAmbiguousOcr = (int) ($skipped['ambiguous_ocr'] ?? 0);

        $eligible = array_values(array_filter($plans, static fn ($p) => ! empty($p['eligible'])));

        // Abort if any OCR row ID would be assigned to more than one Master.
        $byOcrRow = [];
        foreach ($eligible as $p) {
            $oid = (int) $p['source_ocr_row_id'];
            $byOcrRow[$oid][] = (int) $p['ca_id'];
        }
        $dupOcr = [];
        foreach ($byOcrRow as $oid => $cas) {
            if (count($cas) > 1) {
                $dupOcr[$oid] = $cas;
            }
        }
        $skippedDuplicateOcrIds = 0;
        if ($dupOcr !== []) {
            if (! $skipDupOcrIds) {
                $firstOid = array_key_first($dupOcr);
                throw new RuntimeException(
                    'ABORT: '.count($dupOcr).' OCR firm id(s) would be linked to multiple Masters (unique source_ocr_row_id). '
                    .'Example ocr_firm_id='.$firstOid.' ca_ids='.json_encode($dupOcr[$firstOid])
                    .'. Re-run with --skip-duplicate-ocr-ids to drop conflicting Exact rows, or fix the matches CSV. No links written.'
                );
            }
            $dropCas = [];
            foreach ($dupOcr as $cas) {
                foreach ($cas as $ca) {
                    $dropCas[(int) $ca] = true;
                }
            }
            $eligible = array_values(array_filter(
                $eligible,
                static fn ($p) => ! isset($dropCas[(int) $p['ca_id']])
            ));
            $skippedDuplicateOcrIds = count($dropCas);
            // Mark dropped plans
            foreach ($plans as &$p) {
                if (isset($dropCas[(int) $p['ca_id']])) {
                    $p['eligible'] = false;
                    $p['resolve_status'] = 'duplicate_ocr_row_id';
                    $p['resolve_detail'] = 'skipped_duplicate_ocr_row_assignment';
                }
            }
            unset($p);
        }

        // Abort / skip if any target OCR row is already linked to another Master in DB.
        $alreadyLinked = [];
        $skippedAlreadyLinkedOcrIds = 0;
        if (Schema::hasColumn('ca_masters', 'source_ocr_row_id') && $eligible !== []) {
            $targetIds = array_values(array_unique(array_map(static fn ($p) => (int) $p['source_ocr_row_id'], $eligible)));
            $plannedByOcr = [];
            foreach ($eligible as $p) {
                $plannedByOcr[(int) $p['source_ocr_row_id']] = (int) $p['ca_id'];
            }
            foreach (array_chunk($targetIds, 500) as $chunkIds) {
                $existing = DB::table('ca_masters')
                    ->whereIn('source_ocr_row_id', $chunkIds)
                    ->select(['ca_id', 'source_ocr_row_id'])
                    ->get();
                foreach ($existing as $ex) {
                    $oid = (int) $ex->source_ocr_row_id;
                    $owner = (int) $ex->ca_id;
                    $plannedCa = $plannedByOcr[$oid] ?? null;
                    if ($plannedCa !== null && $owner !== $plannedCa) {
                        $alreadyLinked[] = [
                            'ocr_firm_id' => $oid,
                            'already_linked_ca_id' => $owner,
                            'planned_ca_id' => $plannedCa,
                        ];
                    }
                }
            }
        }
        if ($alreadyLinked !== []) {
            if (! $skipAlreadyLinked) {
                throw new RuntimeException(
                    'ABORT: '.count($alreadyLinked).' OCR firm id(s) already linked to other Masters. No links written. First: '
                    .json_encode($alreadyLinked[0])
                    .'. Re-run with --skip-already-linked-ocr-ids to drop those Exact rows.'
                );
            }
            $dropOids = [];
            foreach ($alreadyLinked as $item) {
                $dropOids[(int) $item['ocr_firm_id']] = true;
            }
            $before = count($eligible);
            $eligible = array_values(array_filter(
                $eligible,
                static fn ($p) => ! isset($dropOids[(int) $p['source_ocr_row_id']])
            ));
            $skippedAlreadyLinkedOcrIds = $before - count($eligible);
            foreach ($plans as &$p) {
                if (! empty($p['eligible']) && isset($dropOids[(int) ($p['source_ocr_row_id'] ?? 0)])) {
                    $p['eligible'] = false;
                    $p['resolve_status'] = 'already_linked_ocr_row_id';
                    $p['resolve_detail'] = 'skipped_ocr_row_already_linked_elsewhere';
                }
            }
            unset($p);
        }

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
            'Master Firm Name',
            'OCR City',
            'Confidence',
            'Before source_ocr_row_id',
            'Before source_ocr_document_id',
            'After source_ocr_row_id',
            'After source_ocr_document_id',
            'Resolve Via',
            'Status',
            'Applied',
        ]);

        $counts = [
            'exact_csv_rows' => count($exactRows),
            'eligible' => count($eligible),
            'would_relink' => 0,
            'relinked' => 0,
            'skipped_has_link' => 0,
            'skipped_not_found' => 0,
            'skipped_wrong_source' => 0,
            'skipped_ineligible' => count($plans) - count($eligible),
            'errors' => 0,
        ];
        $auditRows = [];
        $rollbackRows = [];
        $pending = [];

        // Index eligible by ca_id for apply path
        $eligibleByCa = [];
        foreach ($eligible as $p) {
            $eligibleByCa[(int) $p['ca_id']] = $p;
        }

        foreach ($plans as $plan) {
            if (empty($plan['eligible'])) {
                $this->putCsv($fh, $plan, null, null, null, null, 'skipped_'.$plan['resolve_status'], false);
                $auditRows[] = $this->auditEntry($plan, null, null, null, null, 'skipped_'.$plan['resolve_status'], false);
                continue;
            }

            $master = DB::table('ca_masters')
                ->where('ca_id', $plan['ca_id'])
                ->first([
                    'ca_id', 'firm_name', 'source_id', 'source_ocr_row_id', 'source_ocr_document_id', 'deleted_at',
                ]);

            if (! $master || (Schema::hasColumn('ca_masters', 'deleted_at') && $master->deleted_at !== null)) {
                if ($dryRun) {
                    // Offline planning against a non-production DB: still count as would_relink.
                    $counts['would_relink']++;
                    $this->putCsv(
                        $fh,
                        $plan,
                        null,
                        null,
                        $plan['source_ocr_row_id'],
                        $plan['source_ocr_document_id'],
                        'would_relink',
                        false
                    );
                    $auditRows[] = $this->auditEntry(
                        $plan,
                        null,
                        null,
                        $plan['source_ocr_row_id'],
                        $plan['source_ocr_document_id'],
                        'would_relink',
                        false
                    );
                } else {
                    $counts['skipped_not_found']++;
                    $this->putCsv($fh, $plan, null, null, null, null, 'master_not_in_database', false);
                    $auditRows[] = $this->auditEntry($plan, null, null, null, null, 'master_not_in_database', false);
                }
                continue;
            }

            if (Schema::hasColumn('ca_masters', 'source_id') && (int) ($master->source_id ?? 0) !== 1) {
                $counts['skipped_wrong_source']++;
                $this->putCsv(
                    $fh,
                    $plan,
                    $master->source_ocr_row_id,
                    $master->source_ocr_document_id ?? null,
                    null,
                    null,
                    'skipped_source_id_not_ocr_import',
                    false
                );
                $auditRows[] = $this->auditEntry(
                    $plan,
                    $master->source_ocr_row_id,
                    $master->source_ocr_document_id ?? null,
                    null,
                    null,
                    'skipped_source_id_not_ocr_import',
                    false
                );
                continue;
            }

            $beforeRow = $master->source_ocr_row_id !== null && (int) $master->source_ocr_row_id > 0
                ? (int) $master->source_ocr_row_id
                : null;
            $beforeDoc = isset($master->source_ocr_document_id) && (int) $master->source_ocr_document_id > 0
                ? (int) $master->source_ocr_document_id
                : null;

            if ($beforeRow !== null || $beforeDoc !== null) {
                $counts['skipped_has_link']++;
                $this->putCsv($fh, $plan, $beforeRow, $beforeDoc, $beforeRow, $beforeDoc, 'skipped_already_has_ocr_link', false);
                $auditRows[] = $this->auditEntry($plan, $beforeRow, $beforeDoc, $beforeRow, $beforeDoc, 'skipped_already_has_ocr_link', false);
                continue;
            }

            $counts['would_relink']++;

            if ($dryRun) {
                $this->putCsv(
                    $fh,
                    $plan,
                    null,
                    null,
                    $plan['source_ocr_row_id'],
                    $plan['source_ocr_document_id'],
                    'would_relink',
                    false
                );
                $auditRows[] = $this->auditEntry(
                    $plan,
                    null,
                    null,
                    $plan['source_ocr_row_id'],
                    $plan['source_ocr_document_id'],
                    'would_relink',
                    false
                );
                continue;
            }

            $pending[] = $plan;
            if (count($pending) >= $chunk) {
                $counts['relinked'] += $this->flushChunk($pending, $fh, $auditRows, $rollbackRows);
                $pending = [];
            }
        }

        if (! $dryRun && $pending !== []) {
            $counts['relinked'] += $this->flushChunk($pending, $fh, $auditRows, $rollbackRows);
        }

        fclose($fh);

        $result = [
            'dry_run' => $dryRun,
            'apply' => ! $dryRun,
            'exact_csv_rows' => $counts['exact_csv_rows'],
            'eligible' => $counts['eligible'],
            'would_relink' => $counts['would_relink'],
            'relinked' => $counts['relinked'],
            'skipped_has_link' => $counts['skipped_has_link'],
            'skipped_not_found' => $counts['skipped_not_found'],
            'skipped_wrong_source' => $counts['skipped_wrong_source'],
            'skipped_ineligible' => $counts['skipped_ineligible'],
            'skipped_ambiguous_ocr' => $skippedAmbiguousOcr,
            'skipped_duplicate_ocr_ids' => $skippedDuplicateOcrIds,
            'skipped_already_linked_ocr_ids' => $skippedAlreadyLinkedOcrIds,
            'duplicate_ocr_id_groups' => count($dupOcr),
            'already_linked_conflicts' => count($alreadyLinked),
            'errors' => $counts['errors'],
            'export_path' => $export,
            'audit_json_path' => $auditJson,
            'rollback_path' => $rollbackPath,
            'matches_csv' => $matchesPath,
            'trust_csv_ids' => $trustCsvIds,
            'skip_duplicate_ocr_ids' => $skipDupOcrIds,
            'skip_already_linked_ocr_ids' => $skipAlreadyLinked,
            'counts' => $counts,
        ];

        file_put_contents($auditJson, json_encode([
            'ran_at' => date('c'),
            'mode' => $dryRun ? 'dry-run' : 'apply',
            'result' => $result,
            'rows' => $auditRows,
        ], JSON_PRETTY_PRINT));

        file_put_contents($rollbackPath, json_encode([
            'created_at' => date('c'),
            'mode' => $dryRun ? 'dry-run' : 'apply',
            'note' => 'Pass this file to ocr:relink-exact-offline-matches --rollback= to undo applied OCR links only.',
            'rows' => $rollbackRows,
        ], JSON_PRETTY_PRINT));

        return $result;
    }

    /**
     * Roll back links from a prior apply rollback JSON.
     * Only clears source_ocr_* when they still equal the applied values.
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
        $export = storage_path('app/audits/relink-exact-ocr-rollback-'.date('Ymd_His').'.csv');
        $fh = fopen($export, 'wb');
        fputcsv($fh, [
            'CA ID', 'Before source_ocr_row_id', 'Before source_ocr_document_id',
            'Restored source_ocr_row_id', 'Restored source_ocr_document_id', 'Status', 'Applied',
        ]);

        $would = 0;
        $undone = 0;
        $skipped = 0;
        $pending = [];

        foreach ($rows as $r) {
            $caId = (int) ($r['ca_id'] ?? 0);
            $appliedRow = isset($r['after_source_ocr_row_id']) ? (int) $r['after_source_ocr_row_id'] : null;
            $appliedDoc = isset($r['after_source_ocr_document_id']) ? (int) $r['after_source_ocr_document_id'] : null;
            $restoreRow = $r['before_source_ocr_row_id'] ?? null;
            $restoreDoc = $r['before_source_ocr_document_id'] ?? null;

            $master = CaMaster::query()->find($caId);
            if (! $master) {
                $skipped++;
                fputcsv($fh, [$caId, '', '', '', '', 'master_not_found', 'no']);
                continue;
            }
            $currentRow = $master->source_ocr_row_id !== null ? (int) $master->source_ocr_row_id : null;
            $currentDoc = $master->source_ocr_document_id !== null ? (int) $master->source_ocr_document_id : null;

            // Only undo if still exactly what we applied (never clobber newer links).
            if ($currentRow !== $appliedRow || $currentDoc !== $appliedDoc) {
                $skipped++;
                fputcsv($fh, [$caId, $currentRow, $currentDoc, $restoreRow, $restoreDoc, 'skipped_link_changed', 'no']);
                continue;
            }

            $would++;
            if (! $apply) {
                fputcsv($fh, [$caId, $currentRow, $currentDoc, $restoreRow, $restoreDoc, 'would_rollback', 'no']);
                continue;
            }
            $pending[] = [
                'ca_id' => $caId,
                'restore_row' => $restoreRow,
                'restore_doc' => $restoreDoc,
                'current_row' => $currentRow,
                'current_doc' => $currentDoc,
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
     */
    private function flushRollbackChunk(array $pending, $fh): int
    {
        $n = 0;
        DB::transaction(function () use ($pending, $fh, &$n) {
            foreach ($pending as $item) {
                /** @var CaMaster|null $master */
                $master = CaMaster::query()->lockForUpdate()->find($item['ca_id']);
                if (! $master) {
                    fputcsv($fh, [$item['ca_id'], '', '', '', '', 'master_not_found', 'no']);
                    continue;
                }
                $curRow = $master->source_ocr_row_id !== null ? (int) $master->source_ocr_row_id : null;
                $curDoc = $master->source_ocr_document_id !== null ? (int) $master->source_ocr_document_id : null;
                if ($curRow !== $item['current_row'] || $curDoc !== $item['current_doc']) {
                    fputcsv($fh, [$item['ca_id'], $curRow, $curDoc, '', '', 'skipped_link_changed', 'no']);
                    continue;
                }
                $master->source_ocr_row_id = $item['restore_row'];
                if (Schema::hasColumn('ca_masters', 'source_ocr_document_id')) {
                    $master->source_ocr_document_id = $item['restore_doc'];
                }
                $master->saveQuietly();
                $n++;
                $this->writeActivityLog(
                    (int) $item['ca_id'],
                    ['source_ocr_row_id' => $curRow, 'source_ocr_document_id' => $curDoc],
                    ['source_ocr_row_id' => $item['restore_row'], 'source_ocr_document_id' => $item['restore_doc']],
                    'OCR exact-match relink rollback'
                );
                fputcsv($fh, [
                    $item['ca_id'], $curRow, $curDoc, $item['restore_row'], $item['restore_doc'], 'rolled_back', 'yes',
                ]);
            }
        });

        return $n;
    }

    /**
     * @param  list<array<string, mixed>>  $pending
     * @param  resource  $fh
     * @param  list<array<string, mixed>>  $auditRows
     * @param  list<array<string, mixed>>  $rollbackRows
     */
    private function flushChunk(array $pending, $fh, array &$auditRows, array &$rollbackRows): int
    {
        $updated = 0;
        DB::transaction(function () use ($pending, $fh, &$auditRows, &$rollbackRows, &$updated) {
            foreach ($pending as $plan) {
                /** @var CaMaster|null $master */
                $master = CaMaster::query()->lockForUpdate()->find($plan['ca_id']);
                if (! $master) {
                    $this->putCsv($fh, $plan, null, null, null, null, 'master_not_in_database', false);
                    $auditRows[] = $this->auditEntry($plan, null, null, null, null, 'master_not_in_database', false);
                    continue;
                }
                if (Schema::hasColumn('ca_masters', 'source_id') && (int) ($master->source_id ?? 0) !== 1) {
                    $this->putCsv($fh, $plan, null, null, null, null, 'skipped_source_id_not_ocr_import', false);
                    $auditRows[] = $this->auditEntry($plan, null, null, null, null, 'skipped_source_id_not_ocr_import', false);
                    continue;
                }
                $beforeRow = $master->source_ocr_row_id !== null && (int) $master->source_ocr_row_id > 0
                    ? (int) $master->source_ocr_row_id : null;
                $beforeDoc = $master->source_ocr_document_id !== null && (int) $master->source_ocr_document_id > 0
                    ? (int) $master->source_ocr_document_id : null;
                if ($beforeRow !== null || $beforeDoc !== null) {
                    $this->putCsv($fh, $plan, $beforeRow, $beforeDoc, $beforeRow, $beforeDoc, 'skipped_already_has_ocr_link', false);
                    $auditRows[] = $this->auditEntry($plan, $beforeRow, $beforeDoc, $beforeRow, $beforeDoc, 'skipped_already_has_ocr_link', false);
                    continue;
                }

                $afterRow = (int) $plan['source_ocr_row_id'];
                $afterDoc = (int) $plan['source_ocr_document_id'];
                $master->source_ocr_row_id = $afterRow;
                if (Schema::hasColumn('ca_masters', 'source_ocr_document_id')) {
                    $master->source_ocr_document_id = $afterDoc > 0 ? $afterDoc : null;
                }
                // Only OCR link fields — never city_id / firm / CA / address.
                $master->saveQuietly();
                $updated++;

                $this->writeActivityLog(
                    (int) $plan['ca_id'],
                    ['source_ocr_row_id' => null, 'source_ocr_document_id' => null],
                    ['source_ocr_row_id' => $afterRow, 'source_ocr_document_id' => $afterDoc],
                    'OCR exact-match relink (source_ocr_* only)'
                );

                $this->putCsv($fh, $plan, null, null, $afterRow, $afterDoc, 'relinked', true);
                $auditRows[] = $this->auditEntry($plan, null, null, $afterRow, $afterDoc, 'relinked', true);
                $rollbackRows[] = [
                    'ca_id' => (int) $plan['ca_id'],
                    'before_source_ocr_row_id' => null,
                    'before_source_ocr_document_id' => null,
                    'after_source_ocr_row_id' => $afterRow,
                    'after_source_ocr_document_id' => $afterDoc,
                    'applied' => true,
                ];
            }
        });

        return $updated;
    }

    /**
     * @param  array<int, object>  $ocrById
     * @param  array<string, list<object>>  $ocrByNorm
     * @return array<string, mixed>
     */
    private function resolveOcrTarget(
        int $csvFirmId,
        int $csvDocId,
        string $norm,
        string $masterFirm,
        array $ocrById,
        array $ocrByNorm,
        bool $trustCsvIds,
    ): array {
        if ($csvFirmId > 0 && isset($ocrById[$csvFirmId])) {
            $f = $ocrById[$csvFirmId];

            return [
                'status' => 'ok',
                'ocr_firm_id' => (int) $f->id,
                'ocr_document_id' => (int) ($f->ocr_document_id ?? $csvDocId),
                'city' => (string) ($f->city ?? ''),
                'via' => 'csv_id_exists_in_db',
            ];
        }

        if ($trustCsvIds && $csvFirmId > 0) {
            // Explicit opt-in when applying CSV ids that are known valid on target DB
            // but ocr_parsed_firms was not loaded (should be rare).
            return [
                'status' => 'ok',
                'ocr_firm_id' => $csvFirmId,
                'ocr_document_id' => $csvDocId,
                'city' => '',
                'via' => 'trust_csv_ids',
            ];
        }

        $key = $norm !== '' ? $norm : ($this->matcher()->normalizeFirmName($masterFirm) ?? '');
        if ($key === '' || ! isset($ocrByNorm[$key])) {
            return ['status' => 'unresolvable_ocr', 'detail' => 'no_ocr_firm_for_normalized_key'];
        }
        $hits = $ocrByNorm[$key];
        if (count($hits) === 1) {
            $f = $hits[0];

            return [
                'status' => 'ok',
                'ocr_firm_id' => (int) $f->id,
                'ocr_document_id' => (int) ($f->ocr_document_id ?? 0),
                'city' => (string) ($f->city ?? ''),
                'via' => 'normalized_unique_in_db',
            ];
        }

        // Multiple OCR rows with same normalized name: Exact only if all share one id (impossible) or same city + pick is unsafe.
        // Require unique id set size 1 — otherwise ambiguous.
        $ids = array_unique(array_map(static fn ($h) => (int) $h->id, $hits));
        if (count($ids) === 1) {
            $f = $hits[0];

            return [
                'status' => 'ok',
                'ocr_firm_id' => (int) $f->id,
                'ocr_document_id' => (int) ($f->ocr_document_id ?? 0),
                'city' => (string) ($f->city ?? ''),
                'via' => 'normalized_duplicate_rows_same_id',
            ];
        }

        return [
            'status' => 'ambiguous_ocr',
            'detail' => 'multiple_ocr_firms_for_key:'.count($ids),
        ];
    }

    /**
     * @param  resource  $fh
     * @param  array<string, mixed>  $plan
     */
    private function putCsv(
        $fh,
        array $plan,
        mixed $beforeRow,
        mixed $beforeDoc,
        mixed $afterRow,
        mixed $afterDoc,
        string $status,
        bool $applied,
    ): void {
        fputcsv($fh, [
            $plan['ca_id'],
            $plan['firm_name'],
            $plan['ocr_city'],
            $plan['confidence'] ?? 'Exact',
            $beforeRow ?? '',
            $beforeDoc ?? '',
            $afterRow ?? '',
            $afterDoc ?? '',
            $plan['resolve_detail'] ?? '',
            $status,
            $applied ? 'yes' : 'no',
        ]);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function auditEntry(
        array $plan,
        mixed $beforeRow,
        mixed $beforeDoc,
        mixed $afterRow,
        mixed $afterDoc,
        string $status,
        bool $applied,
    ): array {
        return [
            'ca_id' => $plan['ca_id'],
            'firm_name' => $plan['firm_name'],
            'before_source_ocr_row_id' => $beforeRow,
            'before_source_ocr_document_id' => $beforeDoc,
            'after_source_ocr_row_id' => $afterRow,
            'after_source_ocr_document_id' => $afterDoc,
            'resolve_via' => $plan['resolve_detail'] ?? '',
            'status' => $status,
            'applied' => $applied,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function writeActivityLog(int $caId, array $before, array $after, string $description): void
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
                'description' => $description,
                'before_value' => json_encode($before),
                'after_value' => json_encode($after),
                'ip_address' => 'cli',
            ]);
        } catch (Throwable) {
            // Audit must not roll back a successful link update.
        }
    }

    /**
     * @return list<array<string, string>>
     */
    private function loadExactRows(string $path, int $limit = 0): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Unable to open matches CSV: '.$path);
        }
        $header = fgetcsv($fh);
        if ($header === false) {
            fclose($fh);
            throw new RuntimeException('Matches CSV empty.');
        }
        $rows = [];
        while (($row = fgetcsv($fh)) !== false) {
            $map = array_combine($header, $row);
            if ($map === false) {
                continue;
            }
            if (trim((string) ($map['Confidence'] ?? '')) !== 'Exact') {
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
}
