<?php

namespace App\Services\Ocr;

use App\Models\CaAddress;
use App\Models\CaFirm;
use App\Models\CaMaster;
use App\Models\CaPartner;
use App\Models\MappingLog;
use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Services\Activity\ActivityLogService;
use App\Services\Mapping\DataNormalizationService;
use App\Services\Mapping\MasterDataMappingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Manual review gate for OCR firms that auto-import / Mapping Engine could not apply.
 * Master CA Approve uses MasterCaDirectImportService (force insert).
 * Sales Team Approve uses MasterDataMappingService::confirmOcrFirm().
 */
class OcrFirmApprovalService
{
    public function __construct(
        private readonly MasterDataMappingService $mappingService,
        private readonly MasterCaDirectImportService $masterCaImporter,
        private readonly DataNormalizationService $normalizer,
        private readonly ActivityLogService $activityLogService,
    ) {}

    /**
     * @return array{
     *     firm: OcrParsedFirm,
     *     ca_id: int|null,
     *     created: bool,
     *     updated: bool,
     *     action: string,
     *     message: string
     * }
     */
    public function review(OcrDocument $document, OcrParsedFirm $firm, string $reviewStatus, ?int $matchedCaId = null): array
    {
        if ((int) $firm->ocr_document_id !== (int) $document->id) {
            abort(404);
        }

        Log::info('ocr.approve.pipeline', [
            'step' => 'api_called',
            'ocr_document_id' => $document->id,
            'staging_id' => $firm->id,
            'import_type' => $document->import_type,
            'review_status' => $reviewStatus,
            'matched_ca_id' => $matchedCaId,
            'actor_id' => auth()->id(),
        ]);

        return match ($reviewStatus) {
            OcrParsedFirm::REVIEW_APPROVED => $this->approve($document, $firm, $matchedCaId),
            OcrParsedFirm::REVIEW_REJECTED => $this->reject($firm, $document),
            default => $this->markPending($firm),
        };
    }

    /**
     * @return array{
     *     firm: OcrParsedFirm,
     *     ca_id: int|null,
     *     created: bool,
     *     updated: bool,
     *     action: string,
     *     message: string
     * }
     */
    public function approve(OcrDocument $document, OcrParsedFirm $firm, ?int $matchedCaId = null): array
    {
        try {
            if ($document->isMasterCaImport()) {
                $result = $this->masterCaImporter->approveFirm(
                    $document,
                    $firm,
                    auth()->id() ? (int) auth()->id() : null,
                );
            } else {
                $result = $this->approveSalesTeam($document, $firm, $matchedCaId);
            }
        } catch (ValidationException $exception) {
            Log::warning('ocr.approve.pipeline', [
                'step' => 'validation_failed',
                'ocr_document_id' => $document->id,
                'staging_id' => $firm->id,
                'errors' => $exception->errors(),
            ]);
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('ocr.approve.pipeline', [
                'step' => 'failed',
                'ocr_document_id' => $document->id,
                'staging_id' => $firm->id,
                'import_type' => $document->import_type,
                'error_message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
            throw ValidationException::withMessages([
                'approve' => ['Approve failed: '.$exception->getMessage()],
            ]);
        }

        $lead = $result['ca_id'] ? CaMaster::query()->find($result['ca_id']) : null;
        $fresh = $result['firm'];
        if ($lead && $fresh && ! $document->isMasterCaImport()) {
            $referenceFirmId = $this->syncReferenceFirmAndPartners($fresh, $lead);
            if ($referenceFirmId) {
                $fresh->update(['matched_reference_firm_id' => $referenceFirmId]);
                $result['firm'] = $fresh->fresh(['members']);
            }
        }

        try {
            $this->activityLogService->log(
                moduleName: 'OCR',
                action: ($result['created'] ?? false) ? 'OCR Firm Approved' : 'OCR Firm Linked',
                recordId: (string) $firm->id,
                description: trim((string) ($firm->firm_name ?: '')).' → CA #'.($result['ca_id'] ?? '?').' (manual review)',
            );
        } catch (\Throwable) {
            // Activity logging must not block mapping.
        }

        Log::info('ocr.approve.pipeline', [
            'step' => 'completed',
            'ocr_document_id' => $document->id,
            'staging_id' => $firm->id,
            'ca_id' => $result['ca_id'] ?? null,
            'action' => $result['action'] ?? null,
            'match_status' => $result['firm']->match_status ?? null,
            'review_status' => $result['firm']->review_status ?? null,
            'document_progress' => $document->fresh()?->processing_progress,
        ]);

        return $result;
    }

    /**
     * Human field correction before Approve. Marks the row as human-corrected so
     * remaining collision checks can pass after the reviewer fixed mixed fields.
     *
     * @param  array<string, mixed>  $fields
     */
    public function correctFields(OcrDocument $document, OcrParsedFirm $firm, array $fields): OcrParsedFirm
    {
        if ((int) $firm->ocr_document_id !== (int) $document->id) {
            abort(404);
        }

        $threeField = config('ocr_workflow.mode', 'firm_ca_city') === 'firm_ca_city';
        $allowed = $threeField
            ? ['firm_name', 'ca_name', 'city']
            : ['firm_name', 'frn', 'gst_no', 'pan_no', 'address', 'city', 'state', 'pincode', 'phone', 'email', 'website', 'firm_type', 'ca_name'];
        $updates = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $fields)) {
                $value = $fields[$key];
                $updates[$key] = is_string($value) ? trim($value) : $value;
                if ($updates[$key] === '') {
                    $updates[$key] = null;
                }
            }
        }
        if (isset($updates['firm_name'])) {
            $updates['raw_firm_name'] = $updates['firm_name'];
            $updates['normalized_firm_name'] = $this->normalizer->firmName($updates['firm_name']);
        }

        $source = is_array($firm->source_data) ? $firm->source_data : [];
        $raw = is_array($source['raw'] ?? null) ? $source['raw'] : [];
        $parsed = is_array($source['parsed'] ?? null) ? $source['parsed'] : [];
        $normalized = is_array($source['normalized'] ?? null) ? $source['normalized'] : [];
        foreach (['firm_name', 'ca_name', 'city'] as $key) {
            if (! array_key_exists($key, $updates)) {
                continue;
            }
            if (! array_key_exists($key, $raw)) {
                $raw[$key] = $parsed[$key] ?? ($firm->{$key} ?? null);
            }
            $parsed[$key] = $updates[$key];
            $normalized[$key] = match ($key) {
                'firm_name' => $this->normalizer->firmName($updates[$key]),
                'ca_name' => $this->normalizer->caName($updates[$key]),
                'city' => $this->normalizer->city($updates[$key]),
                default => $updates[$key],
            };
        }
        $source['raw'] = $raw;
        $source['parsed'] = $parsed;
        $source['normalized'] = $normalized;
        unset($source['match_type']);
        $source['validation'] = [
            'ok' => true,
            'verified' => false,
            'auto_apply_ok' => false,
            'errors' => [],
            'warnings' => [],
            'collision_codes' => [],
            'collision_messages' => [],
            'fields' => [],
            'require_verification' => true,
            'human_corrected' => true,
            'corrected_at' => now()->toIso8601String(),
        ];

        // Do not persist ca_name on ocr_parsed_firms (no column); keep only in source_data.
        $caUpdate = $updates['ca_name'] ?? null;
        unset($updates['ca_name']);
        $updates['source_data'] = $source;
        $updates['validation_errors'] = null;
        $updates['match_status'] = 'pending';
        $updates['match_reason'] = null;
        $updates['matched_ca_id'] = null;
        $updates['match_confidence'] = null;
        $firm->update($updates);

        if ($caUpdate !== null || array_key_exists('membership_no', $fields)) {
            $member = $firm->members()->orderBy('sequence_no')->first();
            if ($member) {
                $memberUpdates = [];
                if ($caUpdate !== null) {
                    $memberUpdates['ca_name'] = $caUpdate !== '' ? $caUpdate : null;
                    $memberUpdates['raw_ca_name'] = $memberUpdates['ca_name'];
                    $memberUpdates['normalized_ca_name'] = $this->normalizer->caName($memberUpdates['ca_name']);
                }
                if (array_key_exists('membership_no', $fields)) {
                    $mem = trim((string) $fields['membership_no']);
                    $memberUpdates['membership_no'] = $mem !== '' ? $mem : null;
                }
                if ($memberUpdates !== []) {
                    $member->update($memberUpdates);
                }
            }
        }

        $fresh = $firm->fresh(['members']);
        if ($document->isMasterCaImport() && $threeField) {
            $this->masterCaImporter->importFirm($fresh, $document, null);
            $fresh = $fresh->fresh(['members']);
            app(OcrReconciliationReportService::class)->refreshDocumentReport($document->fresh());
        }

        return $fresh;
    }

    /**
     * @return array{
     *     firm: OcrParsedFirm,
     *     ca_id: int|null,
     *     created: bool,
     *     updated: bool,
     *     action: string,
     *     message: string
     * }
     */
    private function approveSalesTeam(OcrDocument $document, OcrParsedFirm $firm, ?int $matchedCaId = null): array
    {
        $firm->loadMissing('members');
        $firmName = trim((string) ($firm->firm_name ?: $firm->raw_firm_name ?: ''));
        if ($firmName === '') {
            throw ValidationException::withMessages([
                'firm_name' => ['Cannot approve: firm name is missing from the OCR result.'],
            ]);
        }

        Log::info('ocr.approve.pipeline', [
            'step' => 'validation_passed',
            'ocr_document_id' => $document->id,
            'staging_id' => $firm->id,
            'firm_name' => $firmName,
        ]);

        if ($firm->review_status === OcrParsedFirm::REVIEW_APPROVED && $firm->crm_ca_id) {
            $existing = CaMaster::query()->find($firm->crm_ca_id);
            if ($existing) {
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

        $preferred = $matchedCaId ?: ($firm->matched_ca_id ? (int) $firm->matched_ca_id : null);
        Log::info('ocr.approve.pipeline', [
            'step' => 'sales_confirm_start',
            'ocr_document_id' => $document->id,
            'staging_id' => $firm->id,
            'preferred_ca_id' => $preferred,
        ]);

        $mapped = $this->mappingService->confirmOcrFirm(
            $firm,
            $preferred,
            auth()->id() ? (int) auth()->id() : null,
        );

        Log::info('ocr.approve.pipeline', [
            'step' => 'sales_insert_committed',
            'ocr_document_id' => $document->id,
            'staging_id' => $firm->id,
            'ca_id' => $mapped['ca_id'],
            'created' => $mapped['created'],
            'updated' => $mapped['updated'],
        ]);

        $this->refreshSalesDocumentCompletion($document);

        return [
            'firm' => $firm->fresh(['members']),
            'ca_id' => $mapped['ca_id'],
            'created' => $mapped['created'],
            'updated' => $mapped['updated'],
            'action' => $mapped['created'] ? 'created' : 'updated',
            'message' => $mapped['created']
                ? 'Firm approved and added to Master Data.'
                : 'Firm approved and linked to an existing Master Data record.',
        ];
    }

    /**
     * Re-run import/mapping and auto-apply every firm that meets safe auto rules.
     *
     * @return array<string, mixed>
     */
    public function approveAllSafe(OcrDocument $document): array
    {
        Log::info('ocr.approve.pipeline', [
            'step' => 'approve_all_safe_start',
            'ocr_document_id' => $document->id,
            'import_type' => $document->import_type,
        ]);

        // Fail-closed: bulk approve of OCR rows is disabled by default.
        if (! (bool) config('ocr_safety.allow_bulk_approve_safe', false)
            && (bool) config('ocr_safety.require_verification', true)) {
            throw ValidationException::withMessages([
                'approve_safe' => [
                    'Accept All Eligible is disabled (OCR_ALLOW_BULK_APPROVE_SAFE=false). Set it to true in .env on the server, run php artisan config:clear, then retry — or Accept rows individually.',
                ],
            ]);
        }

        if ($document->isMasterCaImport()) {
            $actorId = auth()->id() ? (int) auth()->id() : null;
            $stats = $this->masterCaImporter->approveAllEligible($document->fresh(), $actorId);

            return [
                'processed' => (int) ($stats['processed'] ?? 0),
                'auto_created' => (int) ($stats['imported'] ?? 0),
                'auto_updated' => (int) ($stats['updated'] ?? 0),
                'needs_review' => (int) ($stats['skipped'] ?? 0),
                'conflicts' => (int) ($stats['duplicates'] ?? 0),
                'failed' => (int) ($stats['failed'] ?? 0),
                'eligible' => (int) ($stats['eligible'] ?? 0),
                'import_batch_id' => null,
            ];
        }

        $stats = $this->mappingService->approveAllSafeOcrFirms(
            (int) $document->id,
            auth()->id() ? (int) auth()->id() : null,
        );

        $structured = is_array($document->structured_data) ? $document->structured_data : [];
        $structured['mapping'] = array_merge($structured['mapping'] ?? [], [
            'processed' => (int) ($stats['processed'] ?? 0),
            'auto_created' => (int) ($stats['auto_created'] ?? 0),
            'auto_updated' => (int) ($stats['auto_updated'] ?? 0),
            'needs_review' => (int) ($stats['needs_review'] ?? 0),
            'conflicts' => (int) ($stats['conflicts'] ?? 0),
            'bulk_approve_safe_at' => now()->toIso8601String(),
        ]);
        $document->update([
            'structured_data' => $structured,
            'processing_progress' => 'Completed',
        ]);

        return $stats;
    }

    /**
     * @param  list<int>  $firmIds
     * @return array{rejected: int}
     */
    public function rejectSelected(OcrDocument $document, array $firmIds): array
    {
        $rejected = 0;
        $firms = OcrParsedFirm::query()
            ->where('ocr_document_id', $document->id)
            ->whereIn('id', $firmIds)
            ->get();

        foreach ($firms as $firm) {
            if ($firm->review_status === OcrParsedFirm::REVIEW_APPROVED && $firm->crm_ca_id) {
                continue;
            }
            $this->reject($firm, $document);
            $rejected++;
        }

        if ($document->isMasterCaImport()) {
            $this->masterCaImporter->refreshDocumentCompletion($document->fresh());
        } else {
            $this->refreshSalesDocumentCompletion($document->fresh());
        }

        return ['rejected' => $rejected];
    }

    /**
     * @return array<string, mixed>
     */
    public function retryMapping(OcrDocument $document): array
    {
        OcrParsedFirm::query()
            ->where('ocr_document_id', $document->id)
            ->where(function ($q) {
                $q->whereNull('crm_ca_id')
                    ->orWhere('review_status', '!=', OcrParsedFirm::REVIEW_APPROVED);
            })
            ->update([
                'match_status' => null,
                'match_confidence' => null,
                'match_reason' => null,
                'match_candidates' => null,
                'mapped_at' => null,
            ]);

        return $this->approveAllSafe($document);
    }

    /**
     * @return array{
     *     firm: OcrParsedFirm,
     *     ca_id: int|null,
     *     created: bool,
     *     updated: bool,
     *     action: string,
     *     message: string
     * }
     */
    public function reject(OcrParsedFirm $firm, ?OcrDocument $document = null): array
    {
        $firm->update([
            'review_status' => OcrParsedFirm::REVIEW_REJECTED,
            'match_status' => 'rejected',
        ]);

        if (Schema::hasTable('master_mapping_decisions')) {
            \App\Models\MasterMappingDecision::query()->create([
                'source_type' => 'manual',
                'source_ref' => (string) $firm->ocr_document_id,
                'staging_id' => $firm->id,
                'decision' => \App\Models\MasterMappingDecision::DECISION_REJECTED,
                'matched_ca_id' => $firm->crm_ca_id ?: $firm->matched_ca_id,
                'confidence' => $firm->match_confidence,
                'matched_on' => $firm->match_reason,
                'candidates' => $firm->match_candidates,
                'actor_id' => auth()->id(),
                'remarks' => 'Rejected during manual OCR review',
                'applied_at' => now(),
            ]);
        }

        $document = $document ?: OcrDocument::query()->find($firm->ocr_document_id);
        if ($document && $document->isMasterCaImport()) {
            $this->masterCaImporter->refreshDocumentCompletion($document);
        }

        return [
            'firm' => $firm->fresh(['members']),
            'ca_id' => $firm->crm_ca_id ? (int) $firm->crm_ca_id : null,
            'created' => false,
            'updated' => false,
            'action' => 'rejected',
            'message' => 'Firm rejected. No Master Data record was created.',
        ];
    }

    private function refreshSalesDocumentCompletion(OcrDocument $document): void
    {
        $open = OcrParsedFirm::query()
            ->where('ocr_document_id', $document->id)
            ->whereNull('crm_ca_id')
            ->where(function ($q) {
                $q->whereNull('review_status')
                    ->orWhere('review_status', OcrParsedFirm::REVIEW_PENDING);
            })
            ->where(function ($q) {
                $q->whereNull('match_status')
                    ->orWhereIn('match_status', ['pending', 'needs_review', 'conflict', 'unmatched']);
            })
            ->count();

        if ($open < 1) {
            $document->update(['processing_progress' => 'Completed']);
        }
    }

    /**
     * @return array{
     *     firm: OcrParsedFirm,
     *     ca_id: int|null,
     *     created: bool,
     *     updated: bool,
     *     action: string,
     *     message: string
     * }
     */
    private function markPending(OcrParsedFirm $firm): array
    {
        $firm->update(['review_status' => OcrParsedFirm::REVIEW_PENDING]);

        return [
            'firm' => $firm->fresh(['members']),
            'ca_id' => $firm->crm_ca_id ? (int) $firm->crm_ca_id : null,
            'created' => false,
            'updated' => false,
            'action' => 'pending',
            'message' => 'Firm review status set to pending.',
        ];
    }

    private function syncReferenceFirmAndPartners(OcrParsedFirm $firm, CaMaster $lead): ?int
    {
        try {
            if (! Schema::connection('ca_reference')->hasTable('ca_firms')) {
                return null;
            }
        } catch (\Throwable $e) {
            Log::warning('ocr.approve.ca_reference_unavailable', [
                'ocr_parsed_firm_id' => $firm->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        try {
            $referenceFirm = null;
            $partnerLinks = [];

            DB::connection('ca_reference')->transaction(function () use ($firm, $lead, &$referenceFirm, &$partnerLinks) {
                $referenceFirm = $this->upsertReferenceFirm($firm, $lead);
                $partnerLinks = $this->syncReferencePartners($referenceFirm, $firm);
                $this->syncReferenceAddress($referenceFirm, $firm);
                $this->writeMappingLog($referenceFirm, $lead, $firm);
            });

            foreach ($partnerLinks as $memberId => $partnerId) {
                \App\Models\OcrParsedMember::query()->whereKey($memberId)->update([
                    'matched_reference_member_id' => $partnerId,
                    'review_status' => 'approved',
                ]);
            }

            return $referenceFirm ? (int) $referenceFirm->id : null;
        } catch (\Throwable $e) {
            Log::error('ocr.approve.ca_reference_sync_failed', [
                'ocr_parsed_firm_id' => $firm->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function upsertReferenceFirm(OcrParsedFirm $firm, CaMaster $lead): CaFirm
    {
        $gst = $this->normalizer->gst($firm->gst_no);
        $frn = $this->normalizer->frn($firm->frn);
        $existing = null;
        if ($firm->matched_reference_firm_id) {
            $existing = CaFirm::query()->find($firm->matched_reference_firm_id);
        }
        if (! $existing && $frn) {
            $existing = CaFirm::query()->where('frn', $frn)->first();
        }
        if (! $existing && $gst) {
            $existing = CaFirm::query()->where('gst_number', $gst)->first();
        }

        $attrs = [
            'firm_name' => (string) ($firm->firm_name ?: $lead->firm_name),
            'frn' => $frn,
            'firm_type' => $firm->firm_type,
            'partner_count' => (int) ($firm->partner_count ?: $firm->members->count()),
            'address' => $firm->address,
            'city' => $firm->city,
            'state' => $firm->state,
            'pin_code' => $this->normalizer->postalCode($firm->pincode),
            'gst_number' => $gst,
            'email' => $this->normalizer->email($firm->email),
            'phone' => $this->normalizer->phone($firm->phone),
            'website' => $firm->website,
            'status' => 'active',
        ];

        if ($existing) {
            $existing->fill(array_filter($attrs, fn ($v) => $v !== null && $v !== ''))->save();

            return $existing->fresh();
        }

        return CaFirm::query()->create($attrs);
    }

    /**
     * @return array<int, int>
     */
    private function syncReferencePartners(CaFirm $referenceFirm, OcrParsedFirm $firm): array
    {
        if (! Schema::connection('ca_reference')->hasTable('ca_partners')) {
            return [];
        }

        $links = [];
        foreach ($firm->members as $member) {
            $name = trim((string) ($member->ca_name ?: $member->raw_ca_name ?: ''));
            if ($name === '') {
                continue;
            }
            $membership = $this->normalizer->membershipNumber($member->membership_no);
            $query = CaPartner::query()->where('firm_id', $referenceFirm->id);
            $partner = $membership
                ? (clone $query)->where('membership_number', $membership)->first()
                : (clone $query)->whereRaw('LOWER(TRIM(partner_name)) = ?', [mb_strtolower($name)])->first();

            $attrs = [
                'firm_id' => $referenceFirm->id,
                'partner_name' => $name,
                'membership_number' => $membership,
                'designation' => $member->role,
                'mobile' => $this->normalizer->phone($member->mobile),
                'email' => $this->normalizer->email($member->email),
                'status' => 'active',
            ];

            if ($partner) {
                $partner->update($attrs);
                $links[(int) $member->id] = (int) $partner->id;
            } else {
                $created = CaPartner::query()->create($attrs);
                $links[(int) $member->id] = (int) $created->id;
            }
        }

        return $links;
    }

    private function syncReferenceAddress(CaFirm $referenceFirm, OcrParsedFirm $firm): void
    {
        if (! Schema::connection('ca_reference')->hasTable('ca_addresses')) {
            return;
        }
        if (! filled($firm->address) && ! filled($firm->city) && ! filled($firm->pincode)) {
            return;
        }

        $attrs = [
            'firm_id' => $referenceFirm->id,
            'address_line_1' => $firm->address,
            'city' => $firm->city,
            'state' => $firm->state,
            'pin_code' => $this->normalizer->postalCode($firm->pincode),
            'country' => 'India',
        ];
        $existing = CaAddress::query()->where('firm_id', $referenceFirm->id)->first();
        if ($existing) {
            $existing->update($attrs);
        } else {
            CaAddress::query()->create($attrs);
        }
    }

    private function writeMappingLog(CaFirm $referenceFirm, CaMaster $lead, OcrParsedFirm $firm): void
    {
        if (! Schema::connection('ca_reference')->hasTable('mapping_logs')) {
            return;
        }

        MappingLog::query()->create([
            'firm_id' => $referenceFirm->id,
            'crm_record_id' => (int) $lead->ca_id,
            'mapping_type' => 'ocr_manual_approve',
            'confidence_score' => $firm->match_confidence ?: $firm->overall_confidence,
            'status' => 'approved',
            'remarks' => 'Manual review approve for OCR firm #'.$firm->id,
        ]);
    }
}
