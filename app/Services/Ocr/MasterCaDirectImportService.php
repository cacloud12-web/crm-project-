<?php

namespace App\Services\Ocr;

use App\Models\CaMaster;
use App\Models\MasterImportBatch;
use App\Models\MasterMappingDecision;
use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Services\Cache\CrmCacheService;
use App\Services\Mapping\DataNormalizationService;
use App\Services\Mapping\FirmCaCityMatchingProfile;
use App\Services\Mapping\MasterDataMatchingService;
use App\Services\Mapping\MasterDataMappingService;
use App\Services\Master\LookupResolverService;
use App\Services\Ocr\OcrFieldValidationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Official Master CA bulk load from OCR staging.
 *
 * Upload → OCR → Parse → Validate → Direct Master Insert → Completed
 * Never dispatches MapOcrParsedFirmsJob / sales-team fuzzy matching.
 */
class MasterCaDirectImportService
{
    public const MATCH_IMPORTED = 'imported';

    public const MATCH_UPDATED = 'updated_official';

    public const MATCH_DUPLICATE = 'duplicate';

    public const MATCH_NEEDS_REVIEW = 'needs_review';

    public const MATCH_MATCHED = 'matched';

    public const MATCH_VERIFIED = 'verified';

    public const MATCH_CONFLICT = 'conflict';

    public const MATCH_FAILED = 'failed';

    public const MATCH_INVALID = 'invalid';

    public function __construct(
        private readonly DataNormalizationService $normalizer,
        private readonly MasterDataMatchingService $matching,
        private readonly MasterDataMappingService $mappingService,
        private readonly LookupResolverService $lookupResolver,
        private readonly CrmCacheService $cacheService,
        private readonly OcrFieldValidationService $fieldValidator,
        private readonly OcrFieldCollisionService $collisionDetector,
        private readonly FirmCaCityMatchingProfile $firmCaCityMatcher,
        private readonly OcrSourceVerificationService $sourceVerifier,
    ) {}

    /**
     * @return array{
     *     processed: int,
     *     imported: int,
     *     updated: int,
     *     duplicates: int,
     *     review: int,
     *     failed: int,
     *     import_batch_id: int|null
     * }
     */
    public function processDocument(int $ocrDocumentId, ?int $actorId = null): array
    {
        $document = OcrDocument::query()->findOrFail($ocrDocumentId);
        if ($document->import_type !== OcrDocument::IMPORT_MASTER_CA) {
            throw new \InvalidArgumentException('Document is not a Master CA import.');
        }

        $document->update([
            'processing_progress' => 'Validating official Master records',
            'error_code' => null,
            'error_message' => null,
        ]);

        $stats = [
            'processed' => 0,
            'imported' => 0,
            'updated' => 0,
            'duplicates' => 0,
            'verified' => 0,
            'review' => 0,
            'conflict' => 0,
            'failed' => 0,
            'import_batch_id' => null,
        ];

        $pending = OcrParsedFirm::query()
            ->where('ocr_document_id', $ocrDocumentId)
            ->where(function ($q) {
                $q->whereNull('match_status')
                    ->orWhereIn('match_status', ['pending', 'unmatched', self::MATCH_NEEDS_REVIEW, self::MATCH_MATCHED, self::MATCH_VERIFIED, self::MATCH_CONFLICT, self::MATCH_FAILED, self::MATCH_INVALID]);
            })
            ->where(function ($q) {
                $q->whereNull('review_status')
                    ->orWhere('review_status', '!=', OcrParsedFirm::REVIEW_APPROVED)
                    ->orWhereNull('crm_ca_id');
            });

        $expected = (clone $pending)->count();
        $batch = null;
        if ($expected > 0 && Schema::hasTable('master_import_batches')) {
            $batch = MasterImportBatch::query()->create([
                'source_type' => OcrDocument::IMPORT_MASTER_CA,
                'source_ref' => (string) $ocrDocumentId,
                'file_name' => $document->original_filename,
                'file_hash' => $document->checksum,
                'status' => MasterImportBatch::STATUS_PROCESSING,
                'total_records' => $expected,
                'progress_stage' => 'validating',
                'progress_pct' => 20,
                'actor_id' => $actorId,
                'created_ca_ids' => [],
                'updated_snapshots' => [],
            ]);
            $stats['import_batch_id'] = $batch->id;
        }

        $document->update(['processing_progress' => 'Importing official Master CA records']);
        if ($batch) {
            $batch->update(['progress_stage' => 'importing', 'progress_pct' => 40]);
        }

        $chunkSize = max(50, (int) config('crm_mapping.master_ca_import_chunk', 200));
        $createdIds = [];

        try {
            (clone $pending)
                ->with('members')
                ->orderBy('sequence_no')
                ->chunkById($chunkSize, function ($firms) use ($document, $actorId, $batch, &$stats, &$createdIds) {
                    foreach ($firms as $firm) {
                        $result = $this->importFirm($firm, $document, $actorId);
                        $stats['processed']++;
                        $stats[$result['bucket']] = (int) ($stats[$result['bucket']] ?? 0) + 1;
                        if (! empty($result['created_ca_id'])) {
                            $createdIds[] = (int) $result['created_ca_id'];
                        }
                    }
                    if ($batch) {
                        $done = $stats['processed'];
                        $total = max(1, (int) $batch->total_records);
                        $batch->update([
                            'progress_pct' => min(95, 40 + (int) round(($done / $total) * 55)),
                            'created_count' => $stats['imported'],
                            'updated_count' => $stats['updated'],
                            'duplicate_count' => $stats['duplicates'],
                            'review_count' => $stats['review'],
                            'failed_count' => $stats['failed'],
                            'created_ca_ids' => array_values(array_unique($createdIds)),
                        ]);
                    }
                });
        } catch (Throwable $exception) {
            Log::error('ocr.pipeline.master_ca_import_failed', [
                'ocr_document_id' => $ocrDocumentId,
                'error_message' => $exception->getMessage(),
            ]);
            $document->update([
                'error_code' => 'master_import_failed',
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                'processing_progress' => 'Import failed — retry available',
            ]);
            if ($batch) {
                $batch->update([
                    'status' => MasterImportBatch::STATUS_FAILED,
                    'progress_stage' => 'failed',
                ]);
            }
            throw $exception;
        }

        if ($batch) {
            $batch->update([
                'status' => MasterImportBatch::STATUS_COMPLETED,
                'progress_stage' => 'completed',
                'progress_pct' => 100,
                'total_records' => $stats['processed'],
                'created_count' => $stats['imported'],
                'updated_count' => $stats['updated'],
                'duplicate_count' => $stats['duplicates'],
                'review_count' => $stats['review'],
                'failed_count' => $stats['failed'],
                'created_ca_ids' => array_values(array_unique($createdIds)),
            ]);
        }

        $structured = is_array($document->structured_data) ? $document->structured_data : [];
        $structured['master_import'] = [
            'processed' => $stats['processed'],
            'imported' => $stats['imported'],
            'updated' => $stats['updated'],
            'duplicates' => $stats['duplicates'],
            'verified' => $stats['verified'],
            'review' => $stats['review'],
            'conflict' => $stats['conflict'],
            'failed' => $stats['failed'],
            'import_batch_id' => $stats['import_batch_id'],
            'completed_at' => now()->toIso8601String(),
        ];

        $document->update([
            'structured_data' => $structured,
            'processing_progress' => 'Completed',
            'error_code' => null,
            'error_message' => null,
        ]);

        $canonical = app(OcrReconciliationReportService::class)->refreshDocumentReport($document->fresh());
        $stats['canonical_report'] = $canonical;
        if ($batch) {
            $batch->update([
                'total_records' => $canonical['parsed_rows'],
                'review_count' => $canonical['needs_review'],
                'duplicate_count' => $canonical['conflicts'],
                'failed_count' => $canonical['invalid'] + $canonical['failed'],
            ]);
        }

        $this->bustMasterCaches();

        Log::info('ocr.pipeline.step', [
            'step' => 'master_ca_import_completed',
            'ocr_document_id' => $ocrDocumentId,
            'stats' => $stats,
        ]);

        return $stats;
    }

    /**
     * Manual Approve for a single Master CA staging firm — always inserts/links when firm name exists.
     *
     * @return array{
     *     firm: OcrParsedFirm,
     *     ca_id: int|null,
     *     created: bool,
     *     updated: bool,
     *     action: string,
     *     message: string
     * }
     */
    public function approveFirm(OcrDocument $document, OcrParsedFirm $firm, ?int $actorId = null): array
    {
        if (! $document->isMasterCaImport()) {
            throw new \InvalidArgumentException('approveFirm is only for Master CA imports.');
        }
        if ((int) $firm->ocr_document_id !== (int) $document->id) {
            abort(404);
        }

        Log::info('ocr.approve.pipeline', [
            'step' => 'master_ca_approve_start',
            'ocr_document_id' => $document->id,
            'staging_id' => $firm->id,
            'actor_id' => $actorId,
        ]);

        $firm->loadMissing('members');
        $firmName = trim((string) ($firm->firm_name ?: $firm->raw_firm_name ?: ''));
        if ($firmName === '') {
            throw ValidationException::withMessages([
                'firm_name' => ['Cannot approve: firm name is missing from the OCR result.'],
            ]);
        }

        if ($firm->review_status === OcrParsedFirm::REVIEW_APPROVED && $firm->crm_ca_id) {
            $existing = CaMaster::query()->find($firm->crm_ca_id);
            if ($existing) {
                $this->refreshDocumentCompletion($document);

                return [
                    'firm' => $firm->fresh(['members']),
                    'ca_id' => (int) $existing->ca_id,
                    'created' => false,
                    'updated' => false,
                    'action' => 'already_approved',
                    'message' => 'Firm was already approved and linked to Master Data.',
                ];
            }
        }

        // Reset so importFirm re-processes after a prior needs_review / failed attempt.
        $firm->update([
            'match_status' => 'pending',
            'match_reason' => null,
            'match_confidence' => null,
            'mapped_at' => null,
            'review_status' => OcrParsedFirm::REVIEW_PENDING,
            'crm_ca_id' => null,
        ]);

        Log::info('ocr.approve.pipeline', [
            'step' => 'master_ca_validation_passed',
            'ocr_document_id' => $document->id,
            'staging_id' => $firm->id,
            'firm_name' => $firmName,
        ]);

        $result = $this->importFirm($firm->fresh(['members']), $document, $actorId, true);

        if (in_array($result['bucket'], ['failed', 'review'], true) || empty($result['ca_id'])) {
            $reason = is_array($firm->fresh()?->match_reason) ? '' : (string) ($firm->fresh()?->match_reason ?? 'import_failed');
            Log::error('ocr.approve.pipeline', [
                'step' => 'master_ca_insert_failed',
                'ocr_document_id' => $document->id,
                'staging_id' => $firm->id,
                'bucket' => $result['bucket'],
                'reason' => $reason,
            ]);
            throw ValidationException::withMessages([
                'approve' => ['Accept failed: '.$reason.'. Check Firm Name, CA Name, and City, then try again.'],
            ]);
        }

        Log::info('ocr.approve.pipeline', [
            'step' => 'master_ca_insert_committed',
            'ocr_document_id' => $document->id,
            'staging_id' => $firm->id,
            'ca_id' => $result['ca_id'],
            'bucket' => $result['bucket'],
        ]);

        $this->bustMasterCaches();
        $this->refreshDocumentCompletion($document->fresh());

        $fresh = $firm->fresh(['members']);
        $created = $result['bucket'] === 'imported';
        $updated = $result['bucket'] === 'updated';

        Log::info('ocr.approve.pipeline', [
            'step' => 'master_ca_ocr_row_updated',
            'ocr_document_id' => $document->id,
            'staging_id' => $firm->id,
            'match_status' => $fresh?->match_status,
            'review_status' => $fresh?->review_status,
            'crm_ca_id' => $fresh?->crm_ca_id,
            'document_progress' => $document->fresh()?->processing_progress,
        ]);

        return [
            'firm' => $fresh,
            'ca_id' => $result['ca_id'] ? (int) $result['ca_id'] : null,
            'created' => $created,
            'updated' => $updated,
            'action' => $created ? 'created' : ($updated ? 'updated' : 'duplicate'),
            'message' => $created
                ? 'Firm approved and added to Master Data.'
                : ($updated
                    ? 'Firm approved and official Master Data fields updated.'
                    : 'Firm approved — exact official duplicate linked to Master Data.'),
        ];
    }

    /**
     * Mark document Completed when no staging rows remain Pending / Needs review.
     */
    public function refreshDocumentCompletion(OcrDocument $document): void
    {
        if (! $document->isMasterCaImport()) {
            return;
        }

        // Open = still awaiting Approve/Reject and not linked to Master.
        $open = OcrParsedFirm::query()
            ->where('ocr_document_id', $document->id)
            ->whereNull('crm_ca_id')
            ->where(function ($q) {
                $q->whereNull('review_status')
                    ->orWhere('review_status', OcrParsedFirm::REVIEW_PENDING);
            })
            ->where(function ($q) {
                $q->whereNull('match_status')
                    ->orWhereNotIn('match_status', ['rejected']);
            })
            ->count();

        if ($open > 0) {
            $document->update(['processing_progress' => 'Importing official Master CA records']);

            return;
        }

        $document->update([
            'processing_progress' => 'Completed',
            'error_code' => null,
            'error_message' => null,
        ]);
    }

    private function bustMasterCaches(): void
    {
        $this->cacheService->forgetMasterListings();
        $this->cacheService->forgetDashboardMetrics();
        $this->cacheService->forgetLeadSegmentCounts();
        $this->cacheService->forgetPipelineStageCounts();
    }

    /**
     * @return array{bucket: string, created_ca_id: int|null, ca_id: int|null, decision: string}
     */
    public function importFirm(OcrParsedFirm $firm, OcrDocument $document, ?int $actorId, bool $forceCreate = false): array
    {
        try {
            $row = $this->firmToRow($firm);
            if ($this->isThreeFieldMode()) {
                $row = $this->stripToThreeFields($row);
            }
            $payload = $this->matching->normalizePayload($row);
            if (empty($payload['state_id']) && filled($payload['state'] ?? null) && ! $this->isThreeFieldMode()) {
                $payload['state_id'] = $this->lookupResolver->resolveStateId($payload['state']);
            }
            if (empty($payload['city_id']) && filled($payload['city'] ?? null)) {
                $payload['city_id'] = $this->lookupResolver->resolveCityId($payload['city'], $payload['state_id'] ?? null);
            }
            $payload['source_name'] = 'Master CA Import';
            $payload['_staging_id'] = $firm->id;
            $payload['_matching_profile'] = $this->isThreeFieldMode() ? FirmCaCityMatchingProfile::PROFILE : 'master_ca_direct';
            $payload['field_meta'] = is_array($firm->field_meta) ? $firm->field_meta : [];
            $payload['overall_confidence'] = $firm->overall_confidence;

            $sourceValidation = is_array($firm->source_data['validation'] ?? null) ? $firm->source_data['validation'] : null;
            if ($this->isThreeFieldMode()) {
                // Always re-verify — stale persist-time errors (e.g. Unicode raw≠parsed) must not block rematch.
                $sourceValidation = $this->sourceVerifier->verify($row);
            } elseif ($sourceValidation === null) {
                $sourceValidation = $this->fieldValidator->validateFirm($row);
            }
            $payload['_validation'] = $sourceValidation;

            $collision = $this->collisionDetector->detect($row);
            $payload['_collision'] = $collision;
            $rejectCollision = (bool) config('ocr_safety.reject_on_field_collision', true);
            $humanCorrected = ! empty($firm->source_data['validation']['human_corrected']);

            // Fail-closed: collision codes block all Master writes until fields are corrected.
            if ($rejectCollision && ! $collision['ok'] && ! $humanCorrected) {
                $code = $collision['codes'][0] ?? 'FIELD_COLLISION';
                if ($forceCreate) {
                    throw ValidationException::withMessages([
                        'review_status' => [
                            'Cannot approve: '.$code.'. Correct the mixed fields first, then Approve.',
                        ],
                    ]);
                }

                return $this->finishFirm($firm, null, self::MATCH_NEEDS_REVIEW, $code, $document, $actorId, $payload, (float) ($firm->overall_confidence ?? 0));
            }

            if ($this->isThreeFieldMode()) {
                return $this->importFirmThreeField($firm, $document, $actorId, $payload, $sourceValidation, $forceCreate);
            }

            $allowAutoCreate = (bool) config('ocr_safety.auto_create', false);
            $allowAutoUpdate = (bool) config('ocr_safety.auto_update', false);
            $requireVerification = (bool) config('ocr_safety.require_verification', true);

            // Never silently write OCR into Master (manual Approve may force after human review).
            if (! $forceCreate) {
                if ($requireVerification || empty($sourceValidation['auto_apply_ok'])) {
                    $reason = $sourceValidation['errors'][0]
                        ?? ($sourceValidation['warnings'][0] ?? 'verification_required');

                    return $this->finishFirm($firm, null, self::MATCH_NEEDS_REVIEW, $reason, $document, $actorId, $payload, (float) ($firm->overall_confidence ?? 0));
                }
                if (! $allowAutoCreate && ! $allowAutoUpdate) {
                    return $this->finishFirm($firm, null, self::MATCH_NEEDS_REVIEW, 'ocr_auto_write_disabled', $document, $actorId, $payload, (float) ($firm->overall_confidence ?? 0));
                }
            }

            if (! $this->hasEnoughIdentity($payload) && ! $forceCreate) {
                return $this->finishFirm($firm, null, self::MATCH_NEEDS_REVIEW, 'insufficient_official_identity', $document, $actorId, $payload, 0.0);
            }

            $existing = $this->findExactOfficialMatch($payload);
            if ($existing) {
                if (! $forceCreate && ! $allowAutoUpdate) {
                    return $this->finishFirm($firm, (int) $existing->ca_id, self::MATCH_NEEDS_REVIEW, 'auto_update_disabled', $document, $actorId, $payload, 1.0);
                }
                $changed = $this->fillMissingOfficialFields($existing, $payload);
                $status = $changed ? self::MATCH_UPDATED : self::MATCH_DUPLICATE;
                $reason = $changed ? 'filled_missing_official_fields' : 'exact_official_duplicate';

                return $this->finishFirm($firm, (int) $existing->ca_id, $status, $reason, $document, $actorId, $payload, 1.0, $changed);
            }

            if (! $forceCreate && ! $allowAutoCreate) {
                return $this->finishFirm($firm, null, self::MATCH_NEEDS_REVIEW, 'auto_create_disabled', $document, $actorId, $payload, (float) ($firm->overall_confidence ?? 0));
            }

            if (! $this->hasEnoughIdentity($payload) && $forceCreate) {
                $firmName = trim((string) ($payload['firm_name'] ?? ''));
                if ($firmName === '') {
                    return $this->finishFirm($firm, null, self::MATCH_FAILED, 'missing_firm_name', $document, $actorId, $payload, 0.0);
                }
            }

            Log::info('ocr.approve.pipeline', [
                'step' => 'master_ca_insert_into_ca_masters',
                'ocr_document_id' => $document->id,
                'staging_id' => $firm->id,
                'force_create' => $forceCreate,
                'frn' => $payload['frn'] ?? null,
            ]);

            $attrs = $this->mappingService->toCaMasterAttributes($payload);
            // Official Master files usually have no mobiles — do not invent or require them.
            if (! filled($payload['normalized_mobile'] ?? null)) {
                unset($attrs['mobile_no'], $attrs['alternate_mobile_no']);
            }

            $lead = DB::transaction(function () use ($attrs) {
                $created = $this->mappingService->createMaster($attrs);
                Log::info('ocr.approve.pipeline', [
                    'step' => 'master_ca_transaction_committed',
                    'ca_id' => $created->ca_id,
                ]);

                return $created;
            });

            return $this->finishFirm($firm, (int) $lead->ca_id, self::MATCH_IMPORTED, $forceCreate ? 'manual_approve_created' : 'created_official_master', $document, $actorId, $payload, 1.0, true, (int) $lead->ca_id);
        } catch (Throwable $exception) {
            Log::warning('ocr.pipeline.master_ca_firm_failed', [
                'ocr_document_id' => $document->id,
                'staging_id' => $firm->id,
                'error_message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            if ($forceCreate) {
                throw $exception;
            }

            return $this->finishFirm(
                $firm,
                null,
                self::MATCH_FAILED,
                'import_exception: '.$exception->getMessage(),
                $document,
                $actorId,
                ['error' => $exception->getMessage()],
                0.0,
            );
        }
    }

    /**
     * Firm+CA+City workflow: match on those 3 fields only; never auto-write.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $sourceValidation
     * @return array{bucket: string, created_ca_id: int|null, ca_id: int|null, decision: string}
     */
    private function importFirmThreeField(
        OcrParsedFirm $firm,
        OcrDocument $document,
        ?int $actorId,
        array $payload,
        array $sourceValidation,
        bool $forceCreate,
    ): array {
        $blocking = array_flip(config('ocr_workflow.blocking_codes', []));
        $ignored = array_flip(config('ocr_workflow.ignored_decision_codes', []));
        $scopedErrors = [];
        foreach ($sourceValidation['errors'] ?? [] as $error) {
            $upper = mb_strtoupper((string) $error);
            $skip = false;
            foreach (array_keys($ignored) as $code) {
                if (str_contains($upper, (string) $code)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            $scopedErrors[] = $error;
        }
        foreach ($sourceValidation['collision_codes'] ?? [] as $code) {
            if (isset($ignored[$code])) {
                continue;
            }
            if (isset($blocking[$code]) || str_starts_with((string) $code, 'MISSING_')) {
                $scopedErrors[] = $code;
            }
        }

        $fieldMeta = is_array($firm->field_meta) ? $firm->field_meta : [];
        $thresholds = [
            'firm_name' => (float) config('ocr_workflow.min_firm_name_confidence', config('ocr_workflow.min_field_confidence', 0.55)),
            'ca_name' => (float) config('ocr_workflow.min_ca_name_confidence', config('ocr_workflow.min_field_confidence', 0.55)),
            'city' => (float) config('ocr_workflow.min_city_confidence', config('ocr_workflow.min_field_confidence', 0.55)),
        ];
        $lowFields = [];
        foreach (['firm_name', 'ca_name', 'city'] as $field) {
            $conf = $fieldMeta[$field]['confidence'] ?? null;
            if ($conf !== null && (float) $conf < $thresholds[$field]) {
                $lowFields[] = $field;
            }
        }

        if ($scopedErrors !== [] || $lowFields !== []) {
            $reason = $scopedErrors[0] ?? ('LOW_FIELD_CONFIDENCE:'.implode(',', $lowFields));
            $missingHit = false;
            foreach ($scopedErrors as $err) {
                $u = mb_strtoupper((string) $err);
                if (str_contains($u, 'MISSING_') || str_contains($u, 'REQUIRED')) {
                    $missingHit = true;
                    break;
                }
            }
            $status = $missingHit ? self::MATCH_INVALID : self::MATCH_NEEDS_REVIEW;
            if ($missingHit) {
                $payload['match_type'] = 'INCOMPLETE_SCOPED_FIELDS';
            }

            return $this->finishFirm($firm, null, $status, $reason, $document, $actorId, $payload, (float) ($firm->overall_confidence ?? 0));
        }

        if (! $this->hasEnoughIdentity($payload)) {
            $payload['match_type'] = 'INCOMPLETE_SCOPED_FIELDS';

            return $this->finishFirm($firm, null, self::MATCH_INVALID, 'MISSING_FIRM_CA_OR_CITY', $document, $actorId, $payload, 0.0);
        }

        $match = $this->firmCaCityMatcher->match($payload);
        $payload['match_type'] = null;

        if (! $forceCreate) {
            if ($match->isConflict()) {
                $payload['match_type'] = 'CONFLICT';

                return $this->finishFirm($firm, null, self::MATCH_CONFLICT, 'multiple_firm_ca_city', $document, $actorId, $payload, 1.0);
            }
            if ($match->isExact() && ($match->caId || $match->referenceFirmId)) {
                // Exact unique 3-field match — Verified candidate (Approve still required to write).
                $payload['match_type'] = 'EXACT_VERIFIED';
                if ($match->referenceFirmId) {
                    $payload['matched_reference_firm_id'] = $match->referenceFirmId;
                }

                return $this->finishFirm($firm, $match->caId, self::MATCH_VERIFIED, 'EXACT_VERIFIED', $document, $actorId, $payload, 1.0);
            }
            $payload['match_type'] = 'NO_EXACT_MATCH';

            return $this->finishFirm($firm, null, self::MATCH_NEEDS_REVIEW, $match->reason ?? 'no_exact_firm_ca_city', $document, $actorId, $payload, (float) ($firm->overall_confidence ?? 0));
        }

        if ($match->isConflict()) {
            throw ValidationException::withMessages([
                'review_status' => ['Cannot approve: multiple Master records match firm+CA+city. Resolve the conflict first.'],
            ]);
        }

        if ($match->isExact() && $match->caId) {
            $existing = CaMaster::query()->find($match->caId);
            if ($existing) {
                $changed = $this->fillMissingOfficialFields($existing, $this->stripToThreeFields($payload));
                $status = $changed ? self::MATCH_UPDATED : self::MATCH_DUPLICATE;
                $reason = $changed ? 'approved_filled_missing_firm_ca_city' : 'approved_exact_firm_ca_city';
                $payload['match_type'] = 'EXACT_VERIFIED';
                if ($match->referenceFirmId) {
                    $payload['matched_reference_firm_id'] = $match->referenceFirmId;
                }

                return $this->finishFirm($firm, (int) $existing->ca_id, $status, $reason, $document, $actorId, $payload, 1.0, $changed);
            }
        }

        $attrs = $this->mappingService->toCaMasterAttributes($this->stripToThreeFields($payload));
        unset($attrs['mobile_no'], $attrs['alternate_mobile_no'], $attrs['address'], $attrs['pincode'],
            $attrs['gst_no'], $attrs['pan_no'], $attrs['frn'], $attrs['membership_no'], $attrs['email_id'], $attrs['website']);

        $lead = DB::transaction(function () use ($attrs) {
            return $this->mappingService->createMaster($attrs);
        });
        $payload['match_type'] = 'MANUAL_CREATE';

        return $this->finishFirm($firm, (int) $lead->ca_id, self::MATCH_IMPORTED, 'manual_approve_created_firm_ca_city', $document, $actorId, $payload, 1.0, true, (int) $lead->ca_id);
    }

    private function isThreeFieldMode(): bool
    {
        return config('ocr_workflow.mode', 'firm_ca_city') === 'firm_ca_city';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function stripToThreeFields(array $row): array
    {
        $kept = [
            'firm_name' => $row['firm_name'] ?? ($row['raw_firm_name'] ?? null),
            'raw_firm_name' => $row['raw_firm_name'] ?? ($row['firm_name'] ?? null),
            'ca_name' => $row['ca_name'] ?? ($row['raw_ca_name'] ?? null),
            'raw_ca_name' => $row['raw_ca_name'] ?? ($row['ca_name'] ?? null),
            'city' => $row['city'] ?? ($row['raw_city'] ?? null),
            'raw_city' => $row['raw_city'] ?? ($row['city'] ?? null),
            'normalized_firm_name' => $row['normalized_firm_name'] ?? null,
            'normalized_ca_name' => $row['normalized_ca_name'] ?? null,
            'city_id' => $row['city_id'] ?? null,
            'field_meta' => $row['field_meta'] ?? null,
            'overall_confidence' => $row['overall_confidence'] ?? null,
            'low_confidence_fields' => $row['low_confidence_fields'] ?? null,
            'missing_required_fields' => $row['missing_required_fields'] ?? null,
            'row_merge_suspected' => $row['row_merge_suspected'] ?? false,
            'row_merge_evidence' => $row['row_merge_evidence'] ?? [],
            'row_split_suspected' => $row['row_split_suspected'] ?? false,
            'raw' => $row['raw'] ?? null,
            'parsed' => $row['parsed'] ?? null,
            'members' => [],
            'page_number' => $row['page_number'] ?? null,
            'row_number' => $row['row_number'] ?? null,
        ];

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasEnoughIdentity(array $payload): bool
    {
        if ($this->isThreeFieldMode()) {
            $firm = trim((string) ($payload['normalized_firm_name'] ?? $payload['firm_name'] ?? ''));
            $ca = trim((string) ($payload['normalized_ca_name'] ?? $payload['ca_name'] ?? ''));
            $cityId = (int) ($payload['city_id'] ?? 0);
            $city = trim((string) ($payload['city'] ?? $payload['raw_city'] ?? ''));

            return $firm !== '' && mb_strlen($firm) >= 3 && $ca !== '' && ($cityId > 0 || $city !== '');
        }

        if (filled($payload['normalized_frn'] ?? $payload['frn'] ?? null)) {
            return true;
        }
        if (filled($payload['normalized_membership_no'] ?? $payload['membership_no'] ?? null)) {
            return true;
        }
        if (filled($payload['normalized_gst'] ?? $payload['gst_no'] ?? null)) {
            return true;
        }
        if (filled($payload['normalized_pan'] ?? $payload['pan_no'] ?? null)) {
            return true;
        }

        $firm = trim((string) ($payload['normalized_firm_name'] ?? $payload['firm_name'] ?? ''));
        $ca = trim((string) ($payload['normalized_ca_name'] ?? $payload['ca_name'] ?? ''));
        $stateId = (int) ($payload['state_id'] ?? 0);

        return $firm !== '' && mb_strlen($firm) >= 3 && $ca !== '' && $stateId > 0;
    }

    /**
     * Exact official identifiers only — no fuzzy matching.
     *
     * @param  array<string, mixed>  $payload
     */
    private function findExactOfficialMatch(array $payload): ?CaMaster
    {
        $frn = $payload['normalized_frn'] ?? ($payload['frn'] ?? null);
        if (filled($frn)) {
            $hit = CaMaster::query()->where('frn', $frn)->first()
                ?: CaMaster::query()->whereRaw(
                    "REPLACE(REPLACE(REPLACE(UPPER(COALESCE(frn, '')), '-', ''), ' ', ''), '/', '') = ?",
                    [mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $frn) ?: '')],
                )->first();
            if ($hit) {
                return $hit;
            }
        }

        $membership = $payload['normalized_membership_no'] ?? ($payload['membership_no'] ?? null);
        if (filled($membership)) {
            $hit = CaMaster::query()->where('membership_no', $membership)->first();
            if ($hit) {
                return $hit;
            }
        }

        $gst = $payload['normalized_gst'] ?? ($payload['gst_no'] ?? null);
        if (filled($gst)) {
            $hit = CaMaster::query()->where('gst_no', $gst)->first();
            if ($hit) {
                return $hit;
            }
        }

        $pan = $payload['normalized_pan'] ?? ($payload['pan_no'] ?? null);
        if (filled($pan)) {
            $hit = CaMaster::query()->where('pan_no', $pan)->first();
            if ($hit) {
                return $hit;
            }
        }

        $firm = mb_strtoupper(trim((string) ($payload['normalized_firm_name'] ?? '')));
        $ca = mb_strtoupper(trim((string) ($payload['normalized_ca_name'] ?? '')));
        $stateId = (int) ($payload['state_id'] ?? 0);
        if ($firm === '' || $ca === '' || $stateId < 1) {
            return null;
        }

        $query = CaMaster::query()->where('state_id', $stateId);
        if (Schema::hasColumn('ca_masters', 'normalized_firm_name')) {
            $query->whereRaw('UPPER(TRIM(COALESCE(normalized_firm_name, firm_name))) = ?', [$firm]);
        } else {
            $query->whereRaw('UPPER(TRIM(firm_name)) = ?', [$firm]);
        }
        if (Schema::hasColumn('ca_masters', 'normalized_ca_name')) {
            $query->whereRaw('UPPER(TRIM(COALESCE(normalized_ca_name, ca_name))) = ?', [$ca]);
        } else {
            $query->whereRaw('UPPER(TRIM(ca_name)) = ?', [$ca]);
        }

        return $query->first();
    }

    /**
     * Fill only empty official fields — never overwrite stronger existing data.
     *
     * @param  array<string, mixed>  $payload
     */
    private function fillMissingOfficialFields(CaMaster $lead, array $payload): bool
    {
        $attrs = $this->mappingService->toCaMasterAttributes($payload);
        unset(
            $attrs['status'], $attrs['priority'], $attrs['rating'], $attrs['created_by_employee_id'],
            $attrs['lead_tags'], $attrs['source_id'], $attrs['mobile_no'], $attrs['alternate_mobile_no'],
        );

        $fillable = $this->isThreeFieldMode()
            ? ['firm_name', 'ca_name', 'normalized_firm_name', 'normalized_ca_name', 'city_id']
            : [
                'firm_name', 'ca_name', 'normalized_firm_name', 'normalized_ca_name', 'normalized_state',
                'address', 'pincode', 'city_id', 'state_id', 'gst_no', 'pan_no', 'frn', 'membership_no',
                'email_id', 'website',
            ];
        $updates = [];
        foreach ($fillable as $key) {
            if (! array_key_exists($key, $attrs) || $attrs[$key] === null || $attrs[$key] === '') {
                continue;
            }
            if (! Schema::hasColumn('ca_masters', $key)) {
                continue;
            }
            $current = $lead->{$key} ?? null;
            if ($current === null || $current === '') {
                $updates[$key] = $attrs[$key];
            }
        }

        // Mobile: only add when Master has none and OCR extracted one (not in 3-field workflow).
        if (! $this->isThreeFieldMode()) {
            $incomingMobile = $payload['normalized_mobile'] ?? $this->normalizer->phone($payload['mobile_no'] ?? null);
            if (filled($incomingMobile) && ! filled($lead->mobile_no)) {
                $updates['mobile_no'] = $payload['mobile_no'] ?: $incomingMobile;
            }
        }

        if ($updates === []) {
            return false;
        }

        $lead->update($updates);

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{bucket: string, created_ca_id: int|null, ca_id: int|null, decision: string}
     */
    private function finishFirm(
        OcrParsedFirm $firm,
        ?int $caId,
        string $matchStatus,
        string $reason,
        OcrDocument $document,
        ?int $actorId,
        array $payload,
        float $confidence,
        bool $applied = false,
        ?int $createdCaId = null,
    ): array {
        $bucket = match ($matchStatus) {
            self::MATCH_IMPORTED => 'imported',
            self::MATCH_UPDATED => 'updated',
            self::MATCH_DUPLICATE => 'duplicates',
            self::MATCH_VERIFIED, self::MATCH_MATCHED => 'verified',
            self::MATCH_NEEDS_REVIEW => 'review',
            self::MATCH_CONFLICT => 'conflict',
            self::MATCH_INVALID => 'failed',
            default => 'failed',
        };

        $decision = match ($matchStatus) {
            self::MATCH_IMPORTED => MasterMappingDecision::DECISION_AUTO_CREATE,
            self::MATCH_UPDATED => MasterMappingDecision::DECISION_AUTO_UPDATE,
            self::MATCH_DUPLICATE => MasterMappingDecision::DECISION_SKIPPED,
            self::MATCH_CONFLICT => MasterMappingDecision::DECISION_CONFLICT,
            self::MATCH_MATCHED, self::MATCH_VERIFIED, self::MATCH_NEEDS_REVIEW => MasterMappingDecision::DECISION_NEEDS_REVIEW,
            self::MATCH_INVALID => MasterMappingDecision::DECISION_REJECTED,
            default => MasterMappingDecision::DECISION_REJECTED,
        };

        $pendingReview = in_array($matchStatus, [
            self::MATCH_NEEDS_REVIEW, self::MATCH_MATCHED, self::MATCH_VERIFIED, self::MATCH_CONFLICT, self::MATCH_FAILED, self::MATCH_INVALID,
        ], true);
        $linkedToMaster = $applied
            || in_array($matchStatus, [self::MATCH_DUPLICATE, self::MATCH_IMPORTED, self::MATCH_UPDATED], true);

        $sourceData = is_array($firm->source_data) ? $firm->source_data : [];
        if (isset($payload['match_type'])) {
            $sourceData['match_type'] = $payload['match_type'];
            $sourceData['firm_name_ocr_confidence'] = is_array($firm->field_meta['firm_name'] ?? null)
                ? ($firm->field_meta['firm_name']['ocr_confidence'] ?? $firm->field_meta['firm_name']['confidence'] ?? null)
                : null;
            $sourceData['ca_name_ocr_confidence'] = is_array($firm->field_meta['ca_name'] ?? null)
                ? ($firm->field_meta['ca_name']['ocr_confidence'] ?? $firm->field_meta['ca_name']['confidence'] ?? null)
                : null;
            $sourceData['city_ocr_confidence'] = is_array($firm->field_meta['city'] ?? null)
                ? ($firm->field_meta['city']['ocr_confidence'] ?? $firm->field_meta['city']['confidence'] ?? null)
                : null;
            $sourceData['parser_confidence'] = $sourceData['parser_confidence'] ?? $firm->overall_confidence;
        }

        $firm->update([
            'match_status' => $matchStatus,
            'match_reason' => $reason,
            'match_confidence' => $confidence,
            'crm_ca_id' => $linkedToMaster ? $caId : null,
            'matched_ca_id' => $caId,
            'matched_reference_firm_id' => isset($payload['matched_reference_firm_id'])
                ? (int) $payload['matched_reference_firm_id']
                : $firm->matched_reference_firm_id,
            'mapped_at' => $linkedToMaster || $matchStatus === self::MATCH_DUPLICATE ? now() : null,
            'review_status' => $pendingReview ? OcrParsedFirm::REVIEW_PENDING : OcrParsedFirm::REVIEW_APPROVED,
            'source_data' => $sourceData,
        ]);

        if (Schema::hasTable('master_mapping_decisions')) {
            $meta = [
                'import_type' => OcrDocument::IMPORT_MASTER_CA,
                'matching_profile' => 'master_ca_direct',
                'imported' => [
                    'state' => $payload['state'] ?? null,
                    'state_id' => $payload['state_id'] ?? null,
                    'ca_name' => $payload['ca_name'] ?? null,
                    'firm_name' => $payload['firm_name'] ?? null,
                    'frn' => $payload['frn'] ?? null,
                    'membership_no' => $payload['membership_no'] ?? null,
                ],
                'mapping_reason' => $reason,
                'status' => $matchStatus,
            ];
            $data = [
                'source_type' => OcrDocument::IMPORT_MASTER_CA,
                'source_ref' => (string) $document->id,
                'staging_id' => $firm->id,
                'decision' => $decision,
                'matched_ca_id' => $caId,
                'confidence' => $confidence,
                'matched_on' => $reason,
                'candidates' => $caId ? [['ca_id' => $caId, 'score' => $confidence, 'matched_on' => $reason]] : [],
                'payload_snapshot' => collect($payload)->except(['members', 'field_meta', '_staging_id', '_matching_profile'])->all(),
                'actor_id' => $actorId,
                'remarks' => $reason,
                'applied_at' => ($applied || $matchStatus === self::MATCH_DUPLICATE) ? now() : null,
            ];
            if (Schema::hasColumn('master_mapping_decisions', 'decision_meta')) {
                $data['decision_meta'] = $meta;
            }
            MasterMappingDecision::query()->create($data);
        }

        return [
            'bucket' => $bucket,
            'created_ca_id' => $createdCaId,
            'ca_id' => $caId,
            'decision' => $decision,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function firmToRow(OcrParsedFirm $firm): array
    {
        $primary = $firm->members->first(fn ($m) => (bool) $m->is_primary) ?: $firm->members->first();
        $source = is_array($firm->source_data) ? $firm->source_data : [];
        $raw = is_array($source['raw'] ?? null) ? $source['raw'] : [];
        $parsed = is_array($source['parsed'] ?? null) ? $source['parsed'] : [];
        // Prefer Unicode-normalized parsed values for matching; keep raw for audit/verification.
        $firmName = $parsed['firm_name'] ?? ($source['firm_name'] ?? ($firm->firm_name ?: ($raw['firm_name'] ?? $firm->raw_firm_name)));
        $caName = $parsed['ca_name'] ?? ($source['ca_name'] ?? ($primary?->ca_name ?: ($raw['ca_name'] ?? $primary?->raw_ca_name)));
        $city = $parsed['city'] ?? ($firm->city ?: ($raw['city'] ?? null));

        return [
            'staging_id' => $firm->id,
            'firm_name' => $firmName,
            'raw_firm_name' => $raw['firm_name'] ?? ($firm->raw_firm_name ?: $firmName),
            'ca_name' => $caName,
            'raw_ca_name' => $raw['ca_name'] ?? ($primary?->raw_ca_name ?: $caName),
            'phone' => $raw['phone'] ?? ($firm->phone ?: $primary?->mobile),
            'email' => $raw['email'] ?? ($firm->email ?: $primary?->email),
            'gst_no' => $raw['gst_no'] ?? $firm->gst_no,
            'pan_no' => $raw['pan_no'] ?? ($firm->pan_no ?: $primary?->pan_no),
            'frn' => $raw['frn'] ?? $firm->frn,
            'membership_no' => $raw['membership_no'] ?? $primary?->membership_no,
            'address' => $raw['address'] ?? $firm->address,
            'city' => $city,
            'raw_city' => $raw['city'] ?? $city,
            'state' => $raw['state'] ?? $firm->state,
            'pincode' => $raw['pincode'] ?? $firm->pincode,
            'website' => $firm->website,
            'firm_type' => $firm->firm_type,
            'partner_count' => $firm->partner_count ?: $firm->members->count(),
            'overall_confidence' => $firm->overall_confidence ?? 0.9,
            'field_meta' => $firm->field_meta,
            'raw' => $raw,
            'parsed' => $parsed !== [] ? $parsed : [
                'firm_name' => $firmName,
                'ca_name' => $caName,
                'city' => $city,
            ],
            'row_merge_suspected' => $source['row_merge_suspected'] ?? false,
            'row_merge_evidence' => $source['row_merge_evidence'] ?? [],
            'row_split_suspected' => $source['row_split_suspected'] ?? false,
            'missing_required_fields' => $source['missing_required_fields'] ?? [],
            'members' => $firm->members->map(fn ($m) => [
                'ca_name' => $m->ca_name ?: $m->raw_ca_name,
                'membership_no' => $m->membership_no,
                'mobile' => $m->mobile,
                'email' => $m->email,
            ])->all(),
        ];
    }
}
