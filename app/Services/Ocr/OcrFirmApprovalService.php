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
 * Manual review gate for OCR firms that the Mapping Engine could not auto-apply.
 * High-confidence matches are handled by MasterDataMappingService after parse.
 */
class OcrFirmApprovalService
{
    public function __construct(
        private readonly MasterDataMappingService $mappingService,
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

        return match ($reviewStatus) {
            OcrParsedFirm::REVIEW_APPROVED => $this->approve($document, $firm, $matchedCaId),
            OcrParsedFirm::REVIEW_REJECTED => $this->reject($firm),
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
        $result = $this->mappingService->confirmOcrFirm(
            $firm,
            $preferred,
            auth()->id() ? (int) auth()->id() : null,
        );

        $fresh = $firm->fresh(['members']);
        $lead = CaMaster::query()->find($result['ca_id']);
        if ($lead && $fresh) {
            $referenceFirmId = $this->syncReferenceFirmAndPartners($fresh, $lead);
            if ($referenceFirmId) {
                $fresh->update(['matched_reference_firm_id' => $referenceFirmId]);
            }
        }

        try {
            $this->activityLogService->log(
                moduleName: 'OCR',
                action: $result['created'] ? 'OCR Firm Approved' : 'OCR Firm Linked',
                recordId: (string) $firm->id,
                description: $firmName.' → CA #'.$result['ca_id'].' (manual review)',
            );
        } catch (\Throwable) {
            // Activity logging must not block mapping.
        }

        return [
            'firm' => $firm->fresh(['members']),
            'ca_id' => $result['ca_id'],
            'created' => $result['created'],
            'updated' => $result['updated'],
            'action' => $result['created'] ? 'created' : 'updated',
            'message' => $result['created']
                ? 'Firm approved and added to Master Data.'
                : 'Firm approved and linked to an existing Master Data record.',
        ];
    }

    /**
     * Re-run mapping and auto-apply every firm that meets safe auto rules.
     *
     * @return array<string, mixed>
     */
    public function approveAllSafe(OcrDocument $document): array
    {
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
            $this->reject($firm);
            $rejected++;
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
    public function reject(OcrParsedFirm $firm): array
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

        return [
            'firm' => $firm->fresh(['members']),
            'ca_id' => $firm->crm_ca_id ? (int) $firm->crm_ca_id : null,
            'created' => false,
            'updated' => false,
            'action' => 'rejected',
            'message' => 'Firm rejected. No Master Data record was created.',
        ];
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
