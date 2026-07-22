<?php

namespace App\Services\Mapping;

use App\Models\MasterMappingDecision;
use App\Models\SalesImportRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Remap existing sales_import_rows against CA Reference.
 * Never re-imports CSV, never creates/updates/deletes CA Master or CA Reference.
 */
class SalesImportRemapService
{
    public const SOURCE_TYPE = 'sales_import_row';

    public const ACTION_AUTO_REMAP = 'automatic_remap';

    public function __construct(
        private readonly SalesImportMatchingService $matcher,
        private readonly SalesImportRemapProtection $protection,
    ) {}

    /**
     * @param  array{
     *   all?: bool,
     *   file?: string|null,
     *   batch?: int|null,
     *   employee?: string|null,
     *   status?: string|null,
     *   dry_run?: bool,
     *   chunk?: int,
     *   include_auto_matched?: bool,
     *   include_manual_unmatched?: bool,
     *   progress?: callable(int $processed, int $eligible): void|null
     * }  $options
     * @return array<string, mixed>
     */
    public function run(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $chunk = max(50, min(2000, (int) ($options['chunk'] ?? 500)));
        $includeAutoMatched = (bool) ($options['include_auto_matched'] ?? false);
        $includeManualUnmatched = (bool) ($options['include_manual_unmatched'] ?? false);
        $progress = $options['progress'] ?? null;

        $scope = $this->resolveScope($options);
        $preflight = $this->matcher->preflightCaReference();

        if (! $preflight['ok']) {
            return [
                'ok' => false,
                'dry_run' => $dryRun,
                'mapping_run_id' => null,
                'preflight' => $preflight,
                'error' => $preflight['error'] ?? 'CA Reference preflight failed',
                'scope' => $scope,
                'files' => [],
                'status_transitions' => [],
                'samples' => [],
                'totals' => $this->emptyTotals(),
                'updated' => 0,
                'errors' => [],
            ];
        }

        $mappingRunId = (string) Str::uuid();
        $fileTotals = [];
        $totals = $this->emptyTotals();
        $errors = [];
        $statusTransitions = [];
        $samples = [];
        $updated = 0;
        $processed = 0;
        $auditCreated = 0;

        $baseQuery = $this->scopedQuery($scope);
        $scopedCount = (clone $baseQuery)->count();
        $candidateQuery = $this->applyEligibility(clone $baseQuery, $includeAutoMatched, $includeManualUnmatched);
        $candidateCount = (clone $candidateQuery)->count();
        $totals['candidates'] = $candidateCount;
        // Rows outside the candidate status set (e.g. matched/ignored) are protected by default.
        $totals['skipped_protected'] = max(0, $scopedCount - $candidateCount);

        $candidateQuery->orderBy('id')->chunkById($chunk, function ($rows) use (
            $dryRun,
            $mappingRunId,
            $includeAutoMatched,
            $includeManualUnmatched,
            &$fileTotals,
            &$totals,
            &$errors,
            &$statusTransitions,
            &$samples,
            &$updated,
            &$processed,
            &$auditCreated,
            $candidateCount,
            $progress,
        ) {
            $applyBuffer = [];

            foreach ($rows as $row) {
                /** @var SalesImportRow $row */
                $processed++;
                $totals['processed'] = $processed;
                if (is_callable($progress)) {
                    $progress($processed, $candidateCount);
                }

                $fileKey = (string) ($row->source_file_name ?: 'unknown');
                if (! isset($fileTotals[$fileKey])) {
                    $fileTotals[$fileKey] = $this->emptyFileBucket($row);
                }

                $guard = $this->protection->inspect($row, $includeAutoMatched, $includeManualUnmatched);
                if ($guard['protected']) {
                    $fileTotals[$fileKey]['skipped_protected']++;
                    $totals['skipped_protected']++;
                    continue;
                }

                $fileTotals[$fileKey]['eligible']++;
                $totals['eligible']++;

                try {
                    $beforeStatus = (string) ($row->mapping_status ?: '(empty)');
                    $proposal = $this->matcher->match($row->firm_name, $row->city_name);
                    $proposedStatus = (string) ($proposal['status'] ?? 'unmatched');

                    $transitionKey = $beforeStatus.' → '.$proposedStatus;
                    $statusTransitions[$transitionKey] = ($statusTransitions[$transitionKey] ?? 0) + 1;

                    if ($proposedStatus === 'matched') {
                        $fileTotals[$fileKey]['would_match']++;
                        $totals['would_match']++;
                        $totals['new_matched']++;
                    } elseif ($proposedStatus === 'needs_review') {
                        $fileTotals[$fileKey]['would_need_review']++;
                        $totals['would_need_review']++;
                        $totals['new_needs_review']++;
                    } else {
                        $fileTotals[$fileKey]['would_stay_unmatched']++;
                        $totals['would_stay_unmatched']++;
                        $totals['still_unmatched']++;
                    }

                    if (count($samples) < 15 && $proposedStatus === 'matched') {
                        $samples[] = [
                            'id' => $row->id,
                            'firm_name' => $row->firm_name,
                            'city_name' => $row->city_name,
                            'proposed_reference_firm_id' => $proposal['matched_reference_firm_id'] ?? null,
                            'proposed_ca_id' => $proposal['ca_id'] ?? null,
                            'match_reason' => $proposal['matched_on'] ?? $proposal['reason'] ?? null,
                        ];
                    }

                    if ($dryRun) {
                        continue;
                    }

                    $before = $this->snapshot($row);
                    if (! $this->mappingChanged($before, $proposal)) {
                        $totals['unchanged']++;
                        continue;
                    }

                    $applyBuffer[] = [
                        'row' => $row,
                        'before' => $before,
                        'proposal' => $proposal,
                    ];
                } catch (Throwable $e) {
                    $fileTotals[$fileKey]['errors']++;
                    $totals['errors']++;
                    $errors[] = [
                        'id' => $row->id,
                        'source_row_number' => $row->source_row_number,
                        'source_file_name' => $row->source_file_name,
                        'message' => $e->getMessage(),
                    ];
                }
            }

            if ($dryRun || $applyBuffer === []) {
                return;
            }

            try {
                DB::transaction(function () use (
                    $applyBuffer,
                    $mappingRunId,
                    $includeAutoMatched,
                    $includeManualUnmatched,
                    &$updated,
                    &$fileTotals,
                    &$totals,
                    &$auditCreated,
                ) {
                    foreach ($applyBuffer as $item) {
                        /** @var SalesImportRow $row */
                        $row = $item['row'];
                        $proposal = $item['proposal'];
                        $before = $item['before'];

                        $fresh = SalesImportRow::query()->lockForUpdate()->find($row->id);
                        if (! $fresh) {
                            continue;
                        }

                        if ($this->protection->isProtected($fresh, $includeAutoMatched, $includeManualUnmatched)) {
                            continue;
                        }

                        $this->applyProposal($fresh, $proposal);
                        $this->writeAudit($fresh, $before, $this->snapshot($fresh), $proposal, $mappingRunId);
                        $auditCreated++;
                        $updated++;
                        $totals['changed']++;
                        $fileKey = (string) ($fresh->source_file_name ?: 'unknown');
                        if (isset($fileTotals[$fileKey])) {
                            $fileTotals[$fileKey]['updated']++;
                        }
                        $totals['updated']++;
                    }
                });
            } catch (Throwable $e) {
                $totals['errors']++;
                $errors[] = [
                    'id' => null,
                    'source_row_number' => null,
                    'source_file_name' => null,
                    'message' => 'Chunk transaction failed: '.$e->getMessage(),
                ];
            }
        });

        $totals['audit_rows_created'] = $auditCreated;

        return [
            'ok' => true,
            'dry_run' => $dryRun,
            'mapping_run_id' => $mappingRunId,
            'preflight' => $preflight,
            'error' => null,
            'scope' => $scope,
            'files' => array_values($fileTotals),
            'status_transitions' => $statusTransitions,
            'samples' => $samples,
            'totals' => $totals,
            'updated' => $updated,
            'errors' => $errors,
        ];
    }

    /**
     * Public for tests / callers.
     *
     * @return array{protected: bool, reason: string|null}
     */
    public function protectionFor(
        SalesImportRow $row,
        bool $includeAutoMatched = false,
        bool $includeManualUnmatched = false,
    ): array {
        return $this->protection->inspect($row, $includeAutoMatched, $includeManualUnmatched);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{all: bool, file: string|null, batch: int|null, employee: string|null, status: string|null}
     */
    private function resolveScope(array $options): array
    {
        $file = trim((string) ($options['file'] ?? ''));
        $employee = trim((string) ($options['employee'] ?? ''));
        $status = trim((string) ($options['status'] ?? ''));
        $batch = isset($options['batch']) && $options['batch'] !== null && $options['batch'] !== ''
            ? (int) $options['batch']
            : null;
        $all = (bool) ($options['all'] ?? false);

        if (! $all && $file === '' && $batch === null && $employee === '') {
            throw new RuntimeException('Specify --all, --file=, --batch=, or --employee=.');
        }

        return [
            'all' => $all,
            'file' => $file !== '' ? $file : null,
            'batch' => $batch,
            'employee' => $employee !== '' ? $employee : null,
            'status' => $status !== '' ? $status : null,
        ];
    }

    /**
     * @param  array{all: bool, file: string|null, batch: int|null, employee: string|null, status: string|null}  $scope
     */
    private function scopedQuery(array $scope): Builder
    {
        return SalesImportRow::query()
            ->when($scope['batch'] !== null, fn ($q) => $q->where('import_batch_id', $scope['batch']))
            ->when($scope['file'] !== null && $scope['batch'] === null, fn ($q) => $q->where('source_file_name', $scope['file']))
            ->when($scope['employee'] !== null, fn ($q) => $q->where('employee_name', $scope['employee']))
            ->when($scope['status'] !== null, fn ($q) => $q->where('mapping_status', $scope['status']));
    }

    private function applyEligibility(Builder $query, bool $includeAutoMatched, bool $includeManualUnmatched): Builder
    {
        // Status-based candidate set; fine-grained protection is enforced by SalesImportRemapProtection.
        return $query
            ->where(function ($q) use ($includeAutoMatched) {
                $q->whereIn('mapping_status', ['unmatched', 'needs_review', 'pending'])
                    ->orWhereNull('mapping_status')
                    ->orWhere('mapping_status', '');
                if ($includeAutoMatched) {
                    $q->orWhere('mapping_status', 'matched');
                }
            })
            ->where(function ($q) {
                $q->whereNull('mapping_status')->orWhere('mapping_status', '!=', 'ignored');
            });
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function applyProposal(SalesImportRow $row, array $proposal): void
    {
        $payload = [
            'mapping_status' => $proposal['status'] ?? 'unmatched',
            'matched_ca_id' => $proposal['ca_id'] ?? null,
            'matched_on' => $proposal['matched_on'] ?? null,
            'match_score' => $proposal['score'] ?? null,
            'review_reason' => $proposal['reason'] ?? null,
            'mapped_at' => now(),
        ];

        if (Schema::hasColumn('sales_import_rows', 'matched_reference_firm_id')) {
            $payload['matched_reference_firm_id'] = $proposal['matched_reference_firm_id'] ?? null;
        }
        if (Schema::hasColumn('sales_import_rows', 'match_candidates')) {
            $payload['match_candidates'] = $proposal['candidates'] ?? [];
        }

        $row->fill($payload);
        $row->save();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $proposal
     */
    private function writeAudit(
        SalesImportRow $row,
        array $before,
        array $after,
        array $proposal,
        string $mappingRunId,
    ): void {
        if (! Schema::hasTable('master_mapping_decisions')) {
            return;
        }

        $data = [
            'import_batch_id' => $row->import_batch_id,
            'source_type' => self::SOURCE_TYPE,
            'source_ref' => (string) $row->id,
            'decision' => MasterMappingDecision::DECISION_AUTO_UPDATE,
            'matched_ca_id' => $after['matched_ca_id'] ?? null,
            'confidence' => $after['match_score'] ?? null,
            'matched_on' => $after['matched_on'] ?? self::ACTION_AUTO_REMAP,
            'candidates' => $proposal['candidates'] ?? [],
            'payload_snapshot' => [
                'employee_name' => $row->employee_name,
                'firm_name' => $row->firm_name,
                'city_name' => $row->city_name,
                'source_file_name' => $row->source_file_name,
                'source_row_number' => $row->source_row_number,
                'previous_mapping_status' => $before['mapping_status'] ?? null,
                'new_mapping_status' => $after['mapping_status'] ?? null,
            ],
            'old_values' => $before,
            'new_values' => $after,
            'actor_id' => null,
            'remarks' => 'automatic_remap '.$mappingRunId,
            'applied_at' => now(),
        ];

        if (Schema::hasColumn('master_mapping_decisions', 'decision_meta')) {
            $data['decision_meta'] = [
                'action' => self::ACTION_AUTO_REMAP,
                'reason' => 'sales-list:remap',
                'mapping_run_id' => $mappingRunId,
                'sales_import_row_id' => $row->id,
                'previous_matched_ca_id' => $before['matched_ca_id'] ?? null,
                'new_matched_ca_id' => $after['matched_ca_id'] ?? null,
                'previous_matched_reference_firm_id' => $before['matched_reference_firm_id'] ?? null,
                'new_matched_reference_firm_id' => $after['matched_reference_firm_id'] ?? null,
            ];
        }

        MasterMappingDecision::query()->create($data);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(SalesImportRow $row): array
    {
        return [
            'mapping_status' => $row->mapping_status,
            'matched_ca_id' => $row->matched_ca_id,
            'matched_reference_firm_id' => $row->matched_reference_firm_id ?? null,
            'matched_on' => $row->matched_on,
            'match_score' => $row->match_score,
            'review_reason' => $row->review_reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $proposal
     */
    private function mappingChanged(array $before, array $proposal): bool
    {
        return (string) ($before['mapping_status'] ?? '') !== (string) ($proposal['status'] ?? '')
            || (int) ($before['matched_ca_id'] ?? 0) !== (int) ($proposal['ca_id'] ?? 0)
            || (int) ($before['matched_reference_firm_id'] ?? 0) !== (int) ($proposal['matched_reference_firm_id'] ?? 0)
            || (string) ($before['matched_on'] ?? '') !== (string) ($proposal['matched_on'] ?? '');
    }

    /**
     * @return array<string, int>
     */
    private function emptyTotals(): array
    {
        return [
            'eligible' => 0,
            'candidates' => 0,
            'skipped_protected' => 0,
            'processed' => 0,
            'would_match' => 0,
            'would_need_review' => 0,
            'would_stay_unmatched' => 0,
            'new_matched' => 0,
            'new_needs_review' => 0,
            'still_unmatched' => 0,
            'unchanged' => 0,
            'changed' => 0,
            'updated' => 0,
            'audit_rows_created' => 0,
            'errors' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyFileBucket(SalesImportRow $row): array
    {
        return [
            'file' => $row->source_file_name,
            'employee' => $row->employee_name,
            'import_batch_id' => $row->import_batch_id,
            'eligible' => 0,
            'skipped_protected' => 0,
            'would_match' => 0,
            'would_need_review' => 0,
            'would_stay_unmatched' => 0,
            'updated' => 0,
            'errors' => 0,
        ];
    }
}
