<?php

namespace App\Services\Mapping;

use App\Models\CaMaster;
use App\Models\MasterImportBatch;
use App\Models\MasterMappingDecision;
use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Models\SourceLead;
use App\Services\Cache\CrmCacheService;
use App\Services\Leads\DuplicateLeadDetectionService;
use App\Services\Leads\LeadFieldNormalizationService;
use App\Services\Leads\PhoneClassificationService;
use App\Services\Master\LookupResolverService;
use App\Services\Rbac\EmployeeDataScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Shared apply layer for every inbound source (OCR, Excel, CSV, API).
 *
 * Flow: normalize → batch match → decide → auto-apply or park for review.
 */
class MasterDataMappingService
{
    public function __construct(
        private readonly MasterDataMatchingService $matching,
        private readonly DataNormalizationService $normalizer,
        private readonly LookupResolverService $lookupResolver,
        private readonly DuplicateLeadDetectionService $duplicateLeadDetection,
        private readonly LeadFieldNormalizationService $fieldNormalization,
        private readonly PhoneClassificationService $phoneClassification,
        private readonly EmployeeDataScopeService $employeeDataScope,
        private readonly CrmCacheService $cacheService,
    ) {}

    /**
     * Map a batch of raw source rows through the engine.
     *
     * @param  list<array<string, mixed>>  $rows  Each row may include staging_id for OCR
     * @return array{
     *     processed: int,
     *     auto_updated: int,
     *     auto_created: int,
     *     needs_review: int,
     *     conflicts: int,
     *     skipped: int,
     *     decisions: list<array<string, mixed>>
     * }
     */
    /**
     * @param  array{file_name?: ?string, file_hash?: ?string, import_batch_id?: ?int}|null  $meta
     */
    public function processBatch(string $sourceType, string $sourceRef, array $rows, ?int $actorId = null, ?array $meta = null): array
    {
        $payloads = [];
        foreach ($rows as $i => $row) {
            $payload = $this->matching->normalizePayload($row);
            $payload['_staging_id'] = $row['staging_id'] ?? ($row['id'] ?? null);
            $payload['_row_index'] = $i;
            $payload['field_meta'] = $row['field_meta'] ?? ($payload['field_meta'] ?? null);
            $payload['overall_confidence'] = $row['overall_confidence'] ?? ($payload['overall_confidence'] ?? null);
            $payload['members'] = $row['members'] ?? ($payload['members'] ?? []);
            $payload['source_name'] = $row['source_name'] ?? ($meta['source_name'] ?? config('crm_mapping.source_types.'.$sourceType, 'Import'));
            $payloads[] = $payload;
        }

        $batch = $this->resolveOrCreateBatch($sourceType, $sourceRef, count($payloads), $actorId, $meta);
        $finalize = ! array_key_exists('finalize', $meta ?? []) || (bool) ($meta['finalize'] ?? true);
        $index = $this->matching->buildIndex($payloads);
        $stats = [
            'processed' => 0,
            'auto_updated' => 0,
            'auto_created' => 0,
            'needs_review' => 0,
            'conflicts' => 0,
            'skipped' => 0,
            'decisions' => [],
            'import_batch_id' => $batch?->id,
        ];
        $createdIds = is_array($batch?->created_ca_ids) ? $batch->created_ca_ids : [];
        $updatedSnapshots = is_array($batch?->updated_snapshots) ? $batch->updated_snapshots : [];
        $baseCreated = (int) ($batch?->created_count ?? 0);
        $baseUpdated = (int) ($batch?->updated_count ?? 0);
        $baseReview = (int) ($batch?->review_count ?? 0);
        $baseConflict = (int) ($batch?->conflict_count ?? 0);
        $baseFailed = (int) ($batch?->failed_count ?? 0);
        $baseProcessed = (int) ($batch?->total_records ?? 0);

        if ($batch) {
            $batch->update([
                'status' => MasterImportBatch::STATUS_PROCESSING,
                'progress_stage' => 'mapping',
                'progress_pct' => max(20, (int) ($batch->progress_pct ?? 0)),
            ]);
        }

        foreach ($payloads as $payload) {
            $stats['processed']++;
            $match = $this->matching->match($payload, $index);
            $decision = $this->decide($match, $payload);
            $result = $this->applyDecision($sourceType, $sourceRef, $payload, $match, $decision, $actorId, $batch?->id);
            $stats['decisions'][] = $result;

            match ($result['decision']) {
                MasterMappingDecision::DECISION_AUTO_UPDATE => $stats['auto_updated']++,
                MasterMappingDecision::DECISION_AUTO_CREATE => $stats['auto_created']++,
                MasterMappingDecision::DECISION_NEEDS_REVIEW => $stats['needs_review']++,
                MasterMappingDecision::DECISION_CONFLICT => $stats['conflicts']++,
                default => $stats['skipped']++,
            };

            if ($result['decision'] === MasterMappingDecision::DECISION_AUTO_CREATE && ! empty($result['ca_id'])) {
                $createdIds[] = (int) $result['ca_id'];
            }
            if ($result['decision'] === MasterMappingDecision::DECISION_AUTO_UPDATE && ! empty($result['snapshot'])) {
                $updatedSnapshots[] = $result['snapshot'];
            }

            if ($batch && $stats['processed'] % 25 === 0) {
                $done = $baseProcessed + $stats['processed'];
                $target = max(1, (int) ($meta['expected_total'] ?? ($baseProcessed + count($payloads))));
                $pct = min(90, 20 + (int) round(($done / $target) * 70));
                $batch->update([
                    'progress_stage' => 'mapping',
                    'progress_pct' => $pct,
                    'created_count' => $baseCreated + $stats['auto_created'],
                    'updated_count' => $baseUpdated + $stats['auto_updated'],
                    'review_count' => $baseReview + $stats['needs_review'],
                    'conflict_count' => $baseConflict + $stats['conflicts'],
                    'failed_count' => $baseFailed + $stats['skipped'],
                    'total_records' => $done,
                    'created_ca_ids' => array_values(array_unique($createdIds)),
                    'updated_snapshots' => $updatedSnapshots,
                ]);
            }
        }

        if ($batch) {
            $batch->update([
                'status' => $finalize ? MasterImportBatch::STATUS_COMPLETED : MasterImportBatch::STATUS_PROCESSING,
                'progress_stage' => $finalize ? 'completed' : 'mapping',
                'progress_pct' => $finalize ? 100 : min(90, (int) ($batch->progress_pct ?? 20)),
                'total_records' => $baseProcessed + $stats['processed'],
                'created_count' => $baseCreated + $stats['auto_created'],
                'updated_count' => $baseUpdated + $stats['auto_updated'],
                'review_count' => $baseReview + $stats['needs_review'],
                'conflict_count' => $baseConflict + $stats['conflicts'],
                'failed_count' => $baseFailed + $stats['skipped'],
                'created_ca_ids' => array_values(array_unique($createdIds)),
                'updated_snapshots' => $updatedSnapshots,
            ]);
        }

        if ($stats['auto_updated'] > 0 || $stats['auto_created'] > 0) {
            $this->cacheService->forgetMasterListings();
            $this->cacheService->forgetDashboardMetrics();
            $this->cacheService->forgetLeadSegmentCounts();
            $this->cacheService->forgetPipelineStageCounts();
        }

        Log::info('mapping.batch.completed', [
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
            'import_batch_id' => $batch?->id,
            'processed' => $stats['processed'],
            'auto_updated' => $stats['auto_updated'],
            'auto_created' => $stats['auto_created'],
            'needs_review' => $stats['needs_review'],
            'conflicts' => $stats['conflicts'],
        ]);

        return $stats;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function resolveOrCreateBatch(string $sourceType, string $sourceRef, int $total, ?int $actorId, ?array $meta): ?MasterImportBatch
    {
        if (! Schema::hasTable('master_import_batches')) {
            return null;
        }
        if (! empty($meta['import_batch_id'])) {
            return MasterImportBatch::query()->find((int) $meta['import_batch_id']);
        }

        return MasterImportBatch::query()->create([
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
            'file_name' => $meta['file_name'] ?? null,
            'file_hash' => $meta['file_hash'] ?? null,
            'status' => MasterImportBatch::STATUS_PROCESSING,
            'total_records' => $total,
            'progress_stage' => 'parsing',
            'progress_pct' => 10,
            'actor_id' => $actorId,
            'created_ca_ids' => [],
            'updated_snapshots' => [],
        ]);
    }

    /**
     * Map all pending OCR parsed firms for a document.
     *
     * @return array<string, mixed>
     */
    public function processOcrDocument(int $ocrDocumentId, ?int $actorId = null): array
    {
        $chunkSize = max(25, (int) config('crm_mapping.map_chunk_size', 200));
        $stats = [
            'processed' => 0,
            'auto_updated' => 0,
            'auto_created' => 0,
            'needs_review' => 0,
            'conflicts' => 0,
            'skipped' => 0,
            'decisions' => [],
            'import_batch_id' => null,
        ];

        $pendingQuery = OcrParsedFirm::query()
            ->where('ocr_document_id', $ocrDocumentId)
            ->where(function ($q) {
                $q->whereNull('match_status')
                    ->orWhereIn('match_status', ['pending', 'unmatched', 'needs_review', 'conflict']);
            })
            ->where(function ($q) {
                $q->whereNull('review_status')
                    ->orWhere('review_status', '!=', OcrParsedFirm::REVIEW_APPROVED)
                    ->orWhereNull('crm_ca_id');
            });

        $expectedTotal = (clone $pendingQuery)->count();
        $doc = OcrDocument::query()->find($ocrDocumentId);
        $batch = null;
        if ($expectedTotal > 0 && Schema::hasTable('master_import_batches')) {
            $batch = MasterImportBatch::query()->create([
                'source_type' => 'ocr',
                'source_ref' => (string) $ocrDocumentId,
                'file_name' => $doc?->original_filename,
                'file_hash' => $doc?->checksum,
                'status' => MasterImportBatch::STATUS_PROCESSING,
                'total_records' => 0,
                'progress_stage' => 'parsing',
                'progress_pct' => 10,
                'actor_id' => $actorId,
                'created_ca_ids' => [],
                'updated_snapshots' => [],
            ]);
            $stats['import_batch_id'] = $batch->id;
        }

        (clone $pendingQuery)
            ->with('members')
            ->orderBy('sequence_no')
            ->chunkById($chunkSize, function ($firms) use ($ocrDocumentId, $actorId, $batch, $expectedTotal, &$stats) {
                $rows = $firms->map(fn (OcrParsedFirm $firm) => $this->firmToMappingRow($firm))->all();
                if ($rows === []) {
                    return;
                }
                // Keep batch open across chunks; finalize once after all chunks.
                $chunkStats = $this->processBatch('ocr', (string) $ocrDocumentId, $rows, $actorId, [
                    'import_batch_id' => $batch?->id,
                    'file_name' => $batch?->file_name,
                    'file_hash' => $batch?->file_hash,
                    'finalize' => false,
                    'expected_total' => $expectedTotal,
                ]);
                foreach (['processed', 'auto_updated', 'auto_created', 'needs_review', 'conflicts', 'skipped'] as $key) {
                    $stats[$key] += (int) ($chunkStats[$key] ?? 0);
                }
                $stats['decisions'] = array_merge($stats['decisions'], $chunkStats['decisions'] ?? []);
            });

        if ($batch) {
            $batch->refresh();
            $batch->update([
                'status' => MasterImportBatch::STATUS_COMPLETED,
                'progress_stage' => 'completed',
                'progress_pct' => 100,
                'total_records' => $stats['processed'],
                'created_count' => $stats['auto_created'],
                'updated_count' => $stats['auto_updated'],
                'review_count' => $stats['needs_review'],
                'conflict_count' => $stats['conflicts'],
                'failed_count' => $stats['skipped'],
            ]);
        }

        return $stats;
    }

    /**
     * Apply auto-create / auto-update for firms that currently meet safe rules.
     * Conflicts and incomplete rows stay in review.
     *
     * @return array<string, mixed>
     */
    public function approveAllSafeOcrFirms(int $ocrDocumentId, ?int $actorId = null): array
    {
        return $this->processOcrDocument($ocrDocumentId, $actorId);
    }

    /**
     * @return array<string, mixed>
     */
    private function firmToMappingRow(OcrParsedFirm $firm): array
    {
        $primary = $firm->members->first(fn ($m) => (bool) $m->is_primary) ?: $firm->members->first();
        $source = is_array($firm->source_data) ? $firm->source_data : [];
        $raw = is_array($source['raw'] ?? null) ? $source['raw'] : [];

        return [
            'staging_id' => $firm->id,
            'firm_name' => $raw['firm_name'] ?? ($firm->firm_name ?: $firm->raw_firm_name),
            'ca_name' => $raw['ca_name'] ?? ($primary?->ca_name ?: $primary?->raw_ca_name),
            'phone' => $raw['phone'] ?? ($firm->phone ?: $primary?->mobile),
            'email' => $raw['email'] ?? ($firm->email ?: $primary?->email),
            'gst_no' => $raw['gst_no'] ?? $firm->gst_no,
            'pan_no' => $raw['pan_no'] ?? ($firm->pan_no ?: $primary?->pan_no),
            'frn' => $raw['frn'] ?? $firm->frn,
            'membership_no' => $raw['membership_no'] ?? $primary?->membership_no,
            'address' => $raw['address'] ?? $firm->address,
            'city' => $raw['city'] ?? $firm->city,
            'state' => $raw['state'] ?? $firm->state,
            'pincode' => $raw['pincode'] ?? $firm->pincode,
            'website' => $firm->website,
            'firm_type' => $firm->firm_type,
            'partner_count' => $firm->partner_count ?: $firm->members->count(),
            'overall_confidence' => $firm->overall_confidence,
            'field_meta' => $firm->field_meta,
            'members' => $firm->members->map(fn ($m) => [
                'ca_name' => $m->ca_name ?: $m->raw_ca_name,
                'membership_no' => $m->membership_no,
                'mobile' => $m->mobile,
                'email' => $m->email,
            ])->all(),
        ];
    }

    /**
     * Manually confirm a review/conflict firm into Master Data (Approve button).
     *
     * @return array{ca_id: int, created: bool, updated: bool, decision: string}
     */
    public function confirmOcrFirm(OcrParsedFirm $firm, ?int $preferredCaId = null, ?int $actorId = null): array
    {
        $firm->loadMissing('members');
        $primary = $firm->members->first(fn ($m) => (bool) $m->is_primary) ?: $firm->members->first();
        $raw = [
            'staging_id' => $firm->id,
            'firm_name' => $firm->firm_name ?: $firm->raw_firm_name,
            'ca_name' => $primary?->ca_name,
            'phone' => $firm->phone ?: $primary?->mobile,
            'email' => $firm->email ?: $primary?->email,
            'gst_no' => $firm->gst_no,
            'pan_no' => $firm->pan_no,
            'frn' => $firm->frn,
            'membership_no' => $primary?->membership_no,
            'address' => $firm->address,
            'city' => $firm->city,
            'state' => $firm->state,
            'pincode' => $firm->pincode,
            'website' => $firm->website,
            'firm_type' => $firm->firm_type,
            'partner_count' => $firm->partner_count ?: $firm->members->count(),
        ];

        $payload = $this->matching->normalizePayload($raw);
        $payload['_staging_id'] = $firm->id;

        if ($preferredCaId || $firm->matched_ca_id) {
            $caId = (int) ($preferredCaId ?: $firm->matched_ca_id);
            $match = MatchResult::exact($caId, 'manual_confirm', [[
                'ca_id' => $caId,
                'score' => 1.0,
                'matched_on' => 'manual_confirm',
                'firm_name' => null,
                'ca_name' => null,
            ]]);
            $decision = MasterMappingDecision::DECISION_AUTO_UPDATE;
        } else {
            $index = $this->matching->buildIndex([$payload]);
            $match = $this->matching->match($payload, $index);
            if ($match->isUnmatched()) {
                $decision = MasterMappingDecision::DECISION_AUTO_CREATE;
            } elseif ($match->isConflict()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'match' => ['Multiple Master matches found. Pass matched_ca_id to confirm one.'],
                ]);
            } else {
                $decision = MasterMappingDecision::DECISION_AUTO_UPDATE;
            }
        }

        $result = $this->applyDecision('manual', (string) $firm->ocr_document_id, $payload, $match, $decision, $actorId);
        $this->cacheService->forgetMasterListings();
        $this->cacheService->forgetDashboardMetrics();

        return [
            'ca_id' => (int) $result['ca_id'],
            'created' => $result['decision'] === MasterMappingDecision::DECISION_AUTO_CREATE,
            'updated' => $result['decision'] === MasterMappingDecision::DECISION_AUTO_UPDATE,
            'decision' => $result['decision'],
        ];
    }

    public function decide(MatchResult $match, ?array $payload = null): string
    {
        $autoExact = (bool) config('crm_mapping.auto_apply_exact', true);
        $autoCreate = (bool) config('crm_mapping.auto_create_unmatched', true);
        $autoMin = (float) config('crm_mapping.auto_update_min_confidence', 0.90);
        $reviewMin = (float) config('crm_mapping.review_min_confidence', 0.55);
        $fuzzyAutoMin = (float) config('crm_mapping.fuzzy_auto_update_min', 0.97);

        if ($match->isConflict()) {
            return MasterMappingDecision::DECISION_CONFLICT;
        }

        $lowFieldConfidence = $payload !== null && $this->hasLowFieldConfidence($payload);

        if ($match->isExact() && $autoExact && $match->caId && ! $lowFieldConfidence) {
            return MasterMappingDecision::DECISION_AUTO_UPDATE;
        }

        if ($match->status === MatchResult::STATUS_POSSIBLE && $match->caId) {
            // Never auto-merge on fuzzy name alone unless confidence is extremely high.
            if (($match->matchedOn === 'fuzzy_firm_name') && $match->confidence < $fuzzyAutoMin) {
                return MasterMappingDecision::DECISION_NEEDS_REVIEW;
            }
            if ($match->confidence >= $autoMin && ! $lowFieldConfidence) {
                return MasterMappingDecision::DECISION_AUTO_UPDATE;
            }

            return MasterMappingDecision::DECISION_NEEDS_REVIEW;
        }

        if ($match->isUnmatched()) {
            if ($autoCreate && $payload !== null && $this->matching->hasCompleteValidData($payload) && ! $lowFieldConfidence) {
                return MasterMappingDecision::DECISION_AUTO_CREATE;
            }

            return MasterMappingDecision::DECISION_NEEDS_REVIEW;
        }

        return MasterMappingDecision::DECISION_NEEDS_REVIEW;
    }

    /**
     * Gate auto-apply only on overall / firm-name confidence.
     * Other low-confidence fields are highlighted in the UI but do not block exact matches.
     *
     * @param  array<string, mixed>  $payload
     */
    private function hasLowFieldConfidence(array $payload): bool
    {
        $threshold = (float) config('crm_mapping.field_confidence_review_min', 0.55);
        $overall = $payload['overall_confidence'] ?? null;
        if ($overall !== null && (float) $overall < $threshold) {
            return true;
        }

        $meta = is_array($payload['field_meta'] ?? null) ? $payload['field_meta'] : [];
        $firmMeta = is_array($meta['firm_name'] ?? null) ? $meta['firm_name'] : [];
        $firmConfidence = $firmMeta['confidence'] ?? null;

        return $firmConfidence !== null && (float) $firmConfidence < $threshold;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyDecision(
        string $sourceType,
        string $sourceRef,
        array $payload,
        MatchResult $match,
        string $decision,
        ?int $actorId,
        ?int $importBatchId = null,
    ): array {
        $stagingId = isset($payload['_staging_id']) ? (int) $payload['_staging_id'] : null;
        $caId = null;
        $appliedAt = null;
        $oldValues = null;
        $newValues = null;
        $snapshot = null;

        if ($decision === MasterMappingDecision::DECISION_AUTO_UPDATE && $match->caId) {
            $merge = DB::transaction(function () use ($payload, $match) {
                $existing = CaMaster::query()->lockForUpdate()->find($match->caId);
                if (! $existing) {
                    return null;
                }
                $before = $this->snapshotLead($existing);
                $attrs = $this->toCaMasterAttributes($payload);
                $lead = $this->mergeIntoExisting($existing, $attrs, $payload);
                $this->syncPartners($lead, $payload['members'] ?? []);

                return [
                    'lead' => $lead,
                    'before' => $before,
                    'after' => $this->snapshotLead($lead),
                ];
            });
            $caId = $merge['lead']->ca_id ?? null;
            $appliedAt = now();
            if (! $caId) {
                $decision = MasterMappingDecision::DECISION_NEEDS_REVIEW;
            } else {
                $oldValues = $merge['before'];
                $newValues = $merge['after'];
                $snapshot = ['ca_id' => $caId, 'before' => $merge['before']];
            }
        } elseif ($decision === MasterMappingDecision::DECISION_AUTO_CREATE) {
            $lead = DB::transaction(function () use ($payload) {
                $created = $this->createMaster($this->toCaMasterAttributes($payload));
                $this->syncPartners($created, $payload['members'] ?? []);

                return $created;
            });
            $caId = $lead->ca_id;
            $appliedAt = now();
            $newValues = $this->snapshotLead($lead);
        }

        $this->persistStagingState($stagingId, $decision, $match, $caId, $appliedAt);
        $log = $this->writeDecisionLog(
            $sourceType,
            $sourceRef,
            $stagingId,
            $decision,
            $match,
            $payload,
            $caId,
            $actorId,
            $appliedAt,
            $importBatchId,
            $oldValues,
            $newValues,
        );

        return [
            'decision' => $decision,
            'ca_id' => $caId,
            'staging_id' => $stagingId,
            'confidence' => $match->confidence,
            'matched_on' => $match->matchedOn,
            'log_id' => $log?->id,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotLead(CaMaster $lead): array
    {
        $keys = [
            'ca_name', 'firm_name', 'mobile_no', 'alternate_mobile_no', 'email_id',
            'gst_no', 'pan_no', 'frn', 'membership_no', 'address', 'pincode',
            'website', 'city_id', 'state_id', 'field_confidence',
        ];
        $out = ['ca_id' => $lead->ca_id];
        foreach ($keys as $key) {
            if (Schema::hasColumn('ca_masters', $key)) {
                $out[$key] = $lead->{$key};
            }
        }

        return $out;
    }

    /**
     * Deduped partner sync into ca_reference when available.
     *
     * @param  list<array<string, mixed>>  $members
     */
    private function syncPartners(CaMaster $lead, array $members): void
    {
        if ($members === []) {
            return;
        }
        try {
            if (! Schema::connection('ca_reference')->hasTable('ca_partners')
                || ! Schema::connection('ca_reference')->hasTable('ca_firms')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        app(PartnerMappingService::class)->syncForMaster($lead, $members);
    }

    private function persistStagingState(?int $stagingId, string $decision, MatchResult $match, ?int $caId, $appliedAt): void
    {
        if (! $stagingId || ! Schema::hasTable('ocr_parsed_firms')) {
            return;
        }

        $firm = OcrParsedFirm::query()->find($stagingId);
        if (! $firm) {
            return;
        }

        $matchStatus = match ($decision) {
            MasterMappingDecision::DECISION_AUTO_UPDATE => 'auto_mapped',
            MasterMappingDecision::DECISION_AUTO_CREATE => 'auto_created',
            MasterMappingDecision::DECISION_CONFLICT => 'conflict',
            MasterMappingDecision::DECISION_NEEDS_REVIEW => 'needs_review',
            default => 'skipped',
        };

        $updates = [];
        if (Schema::hasColumn('ocr_parsed_firms', 'matched_ca_id')) {
            $updates['matched_ca_id'] = $caId ?: $match->caId;
        }
        if (Schema::hasColumn('ocr_parsed_firms', 'match_status')) {
            $updates['match_status'] = $matchStatus;
        }
        if (Schema::hasColumn('ocr_parsed_firms', 'match_confidence')) {
            $updates['match_confidence'] = $match->confidence;
        }
        if (Schema::hasColumn('ocr_parsed_firms', 'match_reason')) {
            $updates['match_reason'] = $match->matchedOn ?: $match->reason;
        }
        if (Schema::hasColumn('ocr_parsed_firms', 'match_candidates')) {
            $updates['match_candidates'] = $match->candidates;
        }
        if (Schema::hasColumn('ocr_parsed_firms', 'mapped_at')) {
            $updates['mapped_at'] = $appliedAt;
        }

        if ($caId && in_array($decision, [MasterMappingDecision::DECISION_AUTO_UPDATE, MasterMappingDecision::DECISION_AUTO_CREATE], true)) {
            $updates['crm_ca_id'] = $caId;
            $updates['review_status'] = OcrParsedFirm::REVIEW_APPROVED;
        }

        if ($updates !== []) {
            $firm->update($updates);
        }

        if ($caId && $firm->ocr_document_id) {
            DB::table('ocr_documents')
                ->where('id', $firm->ocr_document_id)
                ->whereNull('ca_id')
                ->update(['ca_id' => $caId]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeDecisionLog(
        string $sourceType,
        string $sourceRef,
        ?int $stagingId,
        string $decision,
        MatchResult $match,
        array $payload,
        ?int $caId,
        ?int $actorId,
        $appliedAt,
        ?int $importBatchId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): ?MasterMappingDecision {
        if (! Schema::hasTable('master_mapping_decisions')) {
            return null;
        }

        $snapshot = $payload;
        unset($snapshot['_staging_id'], $snapshot['_row_index'], $snapshot['members'], $snapshot['field_meta']);

        $data = [
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
            'staging_id' => $stagingId,
            'decision' => $decision,
            'matched_ca_id' => $caId,
            'confidence' => $match->confidence,
            'matched_on' => $match->matchedOn ?: $match->reason,
            'candidates' => $match->candidates,
            'payload_snapshot' => $snapshot,
            'actor_id' => $actorId,
            'remarks' => $match->reason,
            'applied_at' => $appliedAt,
        ];
        if (Schema::hasColumn('master_mapping_decisions', 'import_batch_id')) {
            $data['import_batch_id'] = $importBatchId;
        }
        if (Schema::hasColumn('master_mapping_decisions', 'old_values')) {
            $data['old_values'] = $oldValues;
        }
        if (Schema::hasColumn('master_mapping_decisions', 'new_values')) {
            $data['new_values'] = $newValues;
        }

        return MasterMappingDecision::query()->create($data);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function toCaMasterAttributes(array $payload): array
    {
        $stateId = $payload['state_id'] ?? $this->lookupResolver->resolveStateId($payload['state'] ?? null);
        $cityId = $payload['city_id'] ?? $this->lookupResolver->resolveCityId($payload['city'] ?? null, $stateId);
        $sourceId = $this->resolveSourceId($payload['source_name'] ?? 'OCR Import');

        $phone = $payload['normalized_mobile'] ?? null;
        if ($phone && $this->phoneClassification->validateForSave($phone, 'mobile_no') !== null) {
            $phone = null;
        }

        // Persist OCR source text as-is; normalized_* columns are for matching/dedupe only.
        $data = [
            'ca_name' => $payload['ca_name'] ?: ($payload['firm_name'] ?: 'Unknown'),
            'firm_name' => $payload['firm_name'],
            'normalized_firm_name' => $payload['normalized_firm_name'] ?? $this->normalizer->firmName($payload['firm_name'] ?? null),
            'mobile_no' => $payload['mobile_no'] ?: $phone,
            'alternate_mobile_no' => $payload['alternate_mobile_no'] ?? null,
            'email_id' => $payload['email_id'] ?? null,
            'gst_no' => $payload['gst_no'] ?? null,
            'pan_no' => $payload['pan_no'] ?? null,
            'frn' => $payload['frn'] ?? null,
            'membership_no' => $payload['membership_no'] ?? null,
            'address' => $payload['address'] ?? null,
            'pincode' => $payload['pincode'] ?? null,
            'website' => $payload['website'] ?? null,
            'city_id' => $cityId,
            'state_id' => $stateId,
            'source_id' => $sourceId,
            'team_size' => $payload['partner_count'] ?? ($payload['team_size'] ?? null),
            'status' => 'New',
            'priority' => 'Medium',
            'rating' => 1,
            'lead_tags' => array_values(array_filter([
                $payload['source_name'] ?? 'Import',
                $payload['firm_type'] ?? null,
            ])),
            'created_by_employee_id' => $this->employeeDataScope->resolveEmployeeId(auth()->user()),
        ];

        if (Schema::hasColumn('ca_masters', 'field_confidence')) {
            $data['field_confidence'] = $this->incomingFieldConfidence($payload);
        }

        $data = $this->duplicateLeadDetection->applyNormalizedFields($data);
        if (array_key_exists('website', $data)) {
            $data['normalized_website'] = $this->fieldNormalization->normalizeWebsite($data['website'] ?? null);
        }

        if (! Schema::hasColumn('ca_masters', 'normalized_firm_name')) {
            unset($data['normalized_firm_name']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, float>
     */
    private function incomingFieldConfidence(array $payload): array
    {
        $meta = is_array($payload['field_meta'] ?? null) ? $payload['field_meta'] : [];
        $out = [];
        foreach (['firm_name', 'ca_name', 'gst_no', 'pan_no', 'address', 'phone', 'mobile_no', 'city', 'pincode', 'email'] as $field) {
            $conf = $meta[$field]['confidence'] ?? null;
            if ($conf !== null) {
                $out[$field] = (float) $conf;
            }
        }
        if (isset($payload['overall_confidence'])) {
            $out['overall'] = (float) $payload['overall_confidence'];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    public function createMaster(array $attrs): CaMaster
    {
        $lead = CaMaster::query()->create($attrs);
        $this->duplicateLeadDetection->syncLeadPhones($lead);

        return $lead;
    }

    /**
     * Merge rules: fill empties; never overwrite good data with lower-confidence OCR;
     * promote secondary mobiles into alternate_mobile_no.
     *
     * @param  array<string, mixed>  $attrs
     * @param  array<string, mixed>  $payload
     */
    public function mergeIntoExisting(CaMaster $lead, array $attrs, array $payload = []): CaMaster
    {
        unset($attrs['status'], $attrs['priority'], $attrs['rating'], $attrs['created_by_employee_id']);
        $incomingConf = $this->incomingFieldConfidence($payload);
        $existingConf = is_array($lead->field_confidence ?? null) ? $lead->field_confidence : [];
        $updates = [];

        foreach ($attrs as $key => $value) {
            if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                continue;
            }
            if ($key === 'field_confidence') {
                continue;
            }
            $current = $lead->{$key} ?? null;
            if ($current === null || $current === '' || (is_array($current) && $current === [])) {
                $updates[$key] = $value;
                continue;
            }
            if ($this->shouldOverwriteWithHigherConfidence($key, $incomingConf, $existingConf)) {
                $updates[$key] = $value;
            }
        }

        // Mobile uniqueness: if incoming mobile matches existing primary, keep primary.
        // If primary is filled and incoming is different, store as alternate when empty.
        $incomingMobile = $attrs['mobile_no'] ?? null;
        $incomingAlt = $attrs['alternate_mobile_no'] ?? null;
        if (filled($incomingMobile) && filled($lead->mobile_no)
            && (string) $incomingMobile !== (string) $lead->mobile_no
            && ! filled($lead->alternate_mobile_no)
            && (string) $incomingMobile !== (string) ($lead->alternate_mobile_no ?? '')) {
            $updates['alternate_mobile_no'] = $incomingMobile;
            unset($updates['mobile_no']);
        }
        if (filled($incomingAlt) && ! filled($lead->alternate_mobile_no)
            && (string) $incomingAlt !== (string) ($lead->mobile_no ?? '')) {
            $updates['alternate_mobile_no'] = $incomingAlt;
        }

        if (isset($attrs['lead_tags']) && is_array($attrs['lead_tags'])) {
            $existing = is_array($lead->lead_tags) ? $lead->lead_tags : [];
            $merged = array_values(array_unique(array_merge($existing, $attrs['lead_tags'])));
            if ($merged !== $existing) {
                $updates['lead_tags'] = $merged;
            }
        }

        if (Schema::hasColumn('ca_masters', 'field_confidence') && $incomingConf !== []) {
            $updates['field_confidence'] = array_merge($existingConf, $incomingConf);
        }

        if ($updates !== []) {
            $lead->update($updates);
        }
        $this->duplicateLeadDetection->syncLeadPhones($lead->fresh());

        return $lead->fresh();
    }

    /**
     * @param  array<string, float>  $incoming
     * @param  array<string, float>  $existing
     */
    private function shouldOverwriteWithHigherConfidence(string $field, array $incoming, array $existing): bool
    {
        $map = [
            'firm_name' => 'firm_name',
            'ca_name' => 'ca_name',
            'city_id' => 'city',
            'address' => 'address',
            'pincode' => 'pincode',
            'gst_no' => 'gst_no',
            'pan_no' => 'pan_no',
            'mobile_no' => 'phone',
            'email_id' => 'email',
        ];
        $confKey = $map[$field] ?? $field;
        $in = $incoming[$confKey] ?? ($incoming['overall'] ?? null);
        $ex = $existing[$confKey] ?? ($existing['overall'] ?? null);
        if ($in === null || $ex === null) {
            return false;
        }

        return (float) $in > (float) $ex + 0.05;
    }

    private function resolveSourceId(string $sourceName): ?int
    {
        $existing = $this->lookupResolver->resolveSourceId($sourceName);
        if ($existing) {
            return $existing;
        }

        return (int) SourceLead::query()->firstOrCreate(
            ['source_name' => $sourceName],
            ['source_name' => $sourceName],
        )->source_id;
    }
}
