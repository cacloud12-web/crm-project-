<?php

namespace App\Services\Ocr;

use App\Models\CaMaster;
use App\Models\MasterImportBatch;
use App\Models\MasterMappingDecision;
use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Services\Mapping\DataNormalizationService;
use App\Services\Mapping\MasterDataMatchingService;
use App\Services\Mapping\MasterDataMappingService;
use App\Services\Master\LookupResolverService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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

    public const MATCH_FAILED = 'failed';

    public function __construct(
        private readonly DataNormalizationService $normalizer,
        private readonly MasterDataMatchingService $matching,
        private readonly MasterDataMappingService $mappingService,
        private readonly LookupResolverService $lookupResolver,
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
            'review' => 0,
            'failed' => 0,
            'import_batch_id' => null,
        ];

        $pending = OcrParsedFirm::query()
            ->where('ocr_document_id', $ocrDocumentId)
            ->where(function ($q) {
                $q->whereNull('match_status')
                    ->orWhereIn('match_status', ['pending', 'unmatched', self::MATCH_NEEDS_REVIEW, self::MATCH_FAILED]);
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
            'review' => $stats['review'],
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

        Log::info('ocr.pipeline.step', [
            'step' => 'master_ca_import_completed',
            'ocr_document_id' => $ocrDocumentId,
            'stats' => $stats,
        ]);

        return $stats;
    }

    /**
     * @return array{bucket: string, created_ca_id: int|null, ca_id: int|null, decision: string}
     */
    private function importFirm(OcrParsedFirm $firm, OcrDocument $document, ?int $actorId): array
    {
        try {
            $row = $this->firmToRow($firm);
            $payload = $this->matching->normalizePayload($row);
            if (empty($payload['state_id']) && filled($payload['state'] ?? null)) {
                $payload['state_id'] = $this->lookupResolver->resolveStateId($payload['state']);
            }
            if (empty($payload['city_id']) && filled($payload['city'] ?? null)) {
                $payload['city_id'] = $this->lookupResolver->resolveCityId($payload['city'], $payload['state_id'] ?? null);
            }
            $payload['source_name'] = 'Master CA Import';
            $payload['_staging_id'] = $firm->id;
            $payload['_matching_profile'] = 'master_ca_direct';

            if (! $this->hasEnoughIdentity($payload)) {
                return $this->finishFirm($firm, null, self::MATCH_NEEDS_REVIEW, 'insufficient_official_identity', $document, $actorId, $payload, 0.0);
            }

            $existing = $this->findExactOfficialMatch($payload);
            if ($existing) {
                $changed = $this->fillMissingOfficialFields($existing, $payload);
                $status = $changed ? self::MATCH_UPDATED : self::MATCH_DUPLICATE;
                $reason = $changed ? 'filled_missing_official_fields' : 'exact_official_duplicate';

                return $this->finishFirm($firm, (int) $existing->ca_id, $status, $reason, $document, $actorId, $payload, 1.0, $changed);
            }

            $attrs = $this->mappingService->toCaMasterAttributes($payload);
            // Official Master files usually have no mobiles — do not invent or require them.
            if (! filled($payload['normalized_mobile'] ?? null)) {
                unset($attrs['mobile_no'], $attrs['alternate_mobile_no']);
            }

            $lead = DB::transaction(fn () => $this->mappingService->createMaster($attrs));

            return $this->finishFirm($firm, (int) $lead->ca_id, self::MATCH_IMPORTED, 'created_official_master', $document, $actorId, $payload, 1.0, true, (int) $lead->ca_id);
        } catch (Throwable $exception) {
            Log::warning('ocr.pipeline.master_ca_firm_failed', [
                'ocr_document_id' => $document->id,
                'staging_id' => $firm->id,
                'error_message' => $exception->getMessage(),
            ]);

            return $this->finishFirm(
                $firm,
                null,
                self::MATCH_FAILED,
                'import_exception',
                $document,
                $actorId,
                ['error' => $exception->getMessage()],
                0.0,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasEnoughIdentity(array $payload): bool
    {
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

        $fillable = [
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

        // Mobile: only add when Master has none and OCR extracted one.
        $incomingMobile = $payload['normalized_mobile'] ?? $this->normalizer->phone($payload['mobile_no'] ?? null);
        if (filled($incomingMobile) && ! filled($lead->mobile_no)) {
            $updates['mobile_no'] = $payload['mobile_no'] ?: $incomingMobile;
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
            self::MATCH_NEEDS_REVIEW => 'review',
            default => 'failed',
        };

        $decision = match ($matchStatus) {
            self::MATCH_IMPORTED => MasterMappingDecision::DECISION_AUTO_CREATE,
            self::MATCH_UPDATED => MasterMappingDecision::DECISION_AUTO_UPDATE,
            self::MATCH_DUPLICATE => MasterMappingDecision::DECISION_SKIPPED,
            self::MATCH_NEEDS_REVIEW => MasterMappingDecision::DECISION_NEEDS_REVIEW,
            default => MasterMappingDecision::DECISION_REJECTED,
        };

        $firm->update([
            'match_status' => $matchStatus,
            'match_reason' => $reason,
            'match_confidence' => $confidence,
            'crm_ca_id' => $caId,
            'matched_ca_id' => $caId,
            'mapped_at' => $applied || $matchStatus === self::MATCH_DUPLICATE ? now() : null,
            'review_status' => $matchStatus === self::MATCH_NEEDS_REVIEW
                ? OcrParsedFirm::REVIEW_PENDING
                : ($matchStatus === self::MATCH_FAILED ? OcrParsedFirm::REVIEW_PENDING : OcrParsedFirm::REVIEW_APPROVED),
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
            'overall_confidence' => $firm->overall_confidence ?? 0.9,
            'field_meta' => $firm->field_meta,
            'members' => $firm->members->map(fn ($m) => [
                'ca_name' => $m->ca_name ?: $m->raw_ca_name,
                'membership_no' => $m->membership_no,
                'mobile' => $m->mobile,
                'email' => $m->email,
            ])->all(),
        ];
    }
}
