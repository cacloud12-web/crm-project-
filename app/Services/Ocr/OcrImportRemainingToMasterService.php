<?php

namespace App\Services\Ocr;

use App\Models\CaMaster;
use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Services\Mapping\FirmCaCityMatchingProfile;
use App\Services\Mapping\MasterDataMappingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Import remaining unlinked OCR rows into Master:
 * - Valid rows → verified Master (safe approval path)
 * - Incomplete/ambiguous → Needs Verification Master (visible, not trusted)
 *
 * Never overwrites verified Master fields. Never invents CA/city. Never deletes staging.
 */
class OcrImportRemainingToMasterService
{
    public const VERIFICATION_VERIFIED = 'verified';

    public const VERIFICATION_NEEDS = 'needs_verification';

    public function __construct(
        private readonly OcrUnlinkedCaNameAuditService $audit,
        private readonly OcrEntityClassificationService $entities,
        private readonly OcrSourceVerificationService $verifier,
        private readonly MasterCaDirectImportService $importer,
        private readonly MasterDataMappingService $mapping,
        private readonly FirmCaCityMatchingProfile $matcher,
    ) {}

    /**
     * @param  array{
     *   all?: bool,
     *   document?: int|null,
     *   dry_run?: bool,
     *   apply?: bool,
     *   actor?: int|null,
     *   chunk?: int,
     *   verified_only?: bool,
     *   needs_verification_only?: bool,
     *   limit?: int
     * }  $options
     * @return array<string, mixed>
     */
    public function run(array $options = []): array
    {
        $apply = (bool) ($options['apply'] ?? false);
        $dryRun = ! $apply || ! empty($options['dry_run']);
        if (! empty($options['dry_run'])) {
            $apply = false;
            $dryRun = true;
        }
        if ($apply && empty($options['actor'])) {
            throw new \InvalidArgumentException('--apply requires --actor=');
        }

        $actorId = isset($options['actor']) ? (int) $options['actor'] : null;
        $documentId = isset($options['document']) ? (int) $options['document'] : null;
        $chunk = max(50, (int) ($options['chunk'] ?? 500));
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $verifiedOnly = (bool) ($options['verified_only'] ?? false);
        $needsOnly = (bool) ($options['needs_verification_only'] ?? false);

        $counts = [
            'dry_run' => $dryRun,
            'scanned' => 0,
            'revalidated_verified' => 0,
            'revalidated_needs_review' => 0,
            'eligible_verified_rows' => 0,
            'needs_verification_rows' => 0,
            'would_create_verified_master' => 0,
            'would_create_needs_verification_master' => 0,
            'would_link_verified_master' => 0,
            'would_link_needs_verification_master' => 0,
            'would_link_existing' => 0,
            'duplicate_links' => 0,
            'ambiguous_rows' => 0,
            'noise_rows_skipped' => 0,
            'invalid_rows_skipped' => 0,
            'already_linked_skipped' => 0,
            'created_verified' => 0,
            'created_needs_verification' => 0,
            'linked_existing' => 0,
            'errors' => 0,
            'error_samples' => [],
        ];

        $query = OcrParsedFirm::query()
            ->with(['members'])
            ->whereNull('crm_ca_id')
            ->when($documentId, fn ($q) => $q->where('ocr_document_id', $documentId))
            ->orderBy('id');

        $processed = 0;
        $query->chunkById($chunk, function ($rows) use (
            &$counts,
            &$processed,
            $dryRun,
            $apply,
            $actorId,
            $limit,
            $verifiedOnly,
            $needsOnly,
        ) {
            foreach ($rows as $firm) {
                /** @var OcrParsedFirm $firm */
                $counts['scanned']++;

                try {
                    if ($firm->crm_ca_id) {
                        $counts['already_linked_skipped']++;
                        continue;
                    }
                    if ((bool) ($firm->is_noise ?? false)) {
                        $counts['noise_rows_skipped']++;
                        continue;
                    }

                    // Step 1: revalidate staging status consistency.
                    $reval = $this->revalidateStaging($firm, $dryRun);
                    if (! empty($reval['ok'])) {
                        $counts['revalidated_verified']++;
                    } else {
                        $counts['revalidated_needs_review']++;
                    }
                    if ($dryRun) {
                        // Apply revalidated fields in-memory for planning without DB write.
                        foreach ($reval['updates'] ?? [] as $key => $value) {
                            $firm->setAttribute($key, $value);
                        }
                    } else {
                        $firm->refresh();
                    }

                    $plan = $this->planImport($firm);
                    if ($plan['action'] === 'skip_invalid') {
                        $counts['invalid_rows_skipped']++;
                        continue;
                    }
                    if ($plan['action'] === 'ambiguous') {
                        $counts['ambiguous_rows']++;
                        continue;
                    }
                    if ($plan['action'] === 'link') {
                        $counts['would_link_existing']++;
                        $counts['duplicate_links']++;
                        $linkBucket = (string) ($plan['bucket'] ?? 'link');
                        if ($linkBucket === self::VERIFICATION_NEEDS || $linkBucket === 'needs_verification') {
                            $counts['would_link_needs_verification_master']++;
                        } else {
                            $counts['would_link_verified_master']++;
                        }
                        if ($apply && ! $dryRun) {
                            $this->linkExisting($firm, (int) $plan['ca_id'], $actorId, $plan);
                            $counts['linked_existing']++;
                        }
                        continue;
                    }

                    if ($plan['bucket'] === 'verified') {
                        $counts['eligible_verified_rows']++;
                        $counts['would_create_verified_master']++;
                        if ($needsOnly) {
                            continue;
                        }
                        if ($apply && ! $dryRun) {
                            $this->importVerified($firm, $actorId);
                            $counts['created_verified']++;
                        }
                        continue;
                    }

                    // needs_verification
                    $counts['needs_verification_rows']++;
                    $counts['would_create_needs_verification_master']++;
                    if ($verifiedOnly) {
                        continue;
                    }
                    if ($apply && ! $dryRun) {
                        $this->importNeedsVerification($firm, $actorId, $plan);
                        $counts['created_needs_verification']++;
                    }
                } catch (Throwable $e) {
                    $counts['errors']++;
                    if (count($counts['error_samples']) < 20) {
                        $counts['error_samples'][] = [
                            'id' => $firm->id,
                            'error' => $e->getMessage(),
                        ];
                    }
                }

                $processed++;
                if ($limit > 0 && $processed >= $limit) {
                    return false;
                }
            }

            return true;
        });

        return $counts;
    }

    /**
     * Fix stale Phase-3 / parser status on staging (no Master write).
     */
    public function revalidateStaging(OcrParsedFirm $firm, bool $dryRun = false): array
    {
        $source = is_array($firm->source_data) ? $firm->source_data : [];
        $raw = is_array($source['raw'] ?? null) ? $source['raw'] : [];
        $parsed = is_array($source['parsed'] ?? null) ? $source['parsed'] : [];

        $firmName = trim((string) ($firm->firm_name ?: $firm->raw_firm_name ?: ($parsed['firm_name'] ?? '') ?: ($raw['firm_name'] ?? '')));
        $caName = trim((string) (($parsed['ca_name'] ?? '') ?: ($raw['ca_name'] ?? '')));
        $city = trim((string) ($firm->city ?: ($parsed['city'] ?? '') ?: ($raw['city'] ?? '')));

        // Address-as-CA: clear parsed CA, move to address (preserve raw).
        if ($caName !== '' && ($this->entities->isAddress($caName) || $this->entities->isAddressShape($caName))) {
            $addr = trim((string) ($firm->address ?? ''));
            $parsed['address'] = $addr === '' ? $caName : ($addr.', '.$caName);
            $parsed['ca_name'] = null;
            $caName = '';
            $firm->address = $parsed['address'];
        }

        $payload = [
            'firm_name' => $firmName,
            'ca_name' => $caName !== '' ? $caName : null,
            'city' => $city !== '' ? $city : null,
            'raw' => $raw,
            'parsed' => array_merge($parsed, [
                'firm_name' => $firmName,
                'ca_name' => $caName !== '' ? $caName : null,
                'city' => $city !== '' ? $city : null,
            ]),
            'field_meta' => $firm->field_meta,
            'overall_confidence' => $firm->overall_confidence,
        ];
        $validation = $this->verifier->verify($payload);
        $ok = ! empty($validation['ok']) && $firmName !== '' && $caName !== '' && $city !== '';
        // Fail closed for address-as-CA / conflicts even if three fields filled after cleanup.
        $classified = $this->audit->classifyRow($firm);
        $issues = $classified['issue_codes'] ?? [];
        if (array_intersect($issues, [
            'numeric_prefix_address',
            'building_name_detected_as_ca_name',
            'address_detected_as_ca_name',
            'firm_name_person_extraction_conflict',
            'invalid_person_name',
            'missing_ca_name',
            'missing_city',
        ])) {
            // Re-check after local CA clear.
            if ($caName === '') {
                $ok = false;
            }
            if ($city === '') {
                $ok = false;
            }
            if (in_array('firm_name_person_extraction_conflict', $issues, true)
                || in_array('invalid_person_name', $issues, true)) {
                $ok = false;
            }
        }

        $updates = [
            'firm_name' => $firmName !== '' ? $firmName : $firm->firm_name,
            'city' => $city !== '' ? $city : null,
            'address' => $firm->address,
            'validation_errors' => $ok
                ? []
                : array_values(array_unique(array_filter(array_merge(
                    $validation['errors'] ?? [],
                    $classified['issue_codes'] ?? [],
                )))),
            'match_status' => $ok ? MasterCaDirectImportService::MATCH_VERIFIED : MasterCaDirectImportService::MATCH_NEEDS_REVIEW,
            // Phase-3 consistency: corrected complete rows are review_status=verified.
            'review_status' => $ok ? OcrParsedFirm::REVIEW_VERIFIED : OcrParsedFirm::REVIEW_PENDING,
            'match_reason' => $ok
                ? 'revalidated_complete_firm_ca_city'
                : ($validation['errors'][0] ?? ($classified['match_reason'] ?? 'needs_review_after_revalidation')),
        ];

        $source['parsed'] = $payload['parsed'];
        $source['raw'] = $raw; // never invent raw
        $source['validation'] = [
            'ok' => $ok,
            'verified' => $ok,
            'auto_apply_ok' => $ok && ! empty($validation['auto_apply_ok']),
            'errors' => $ok ? [] : ($validation['errors'] ?? []),
            'warnings' => $validation['warnings'] ?? [],
            'collision_codes' => $validation['collision_codes'] ?? [],
            'require_verification' => ! $ok,
            'revalidated_at' => now()->toIso8601String(),
        ];
        $updates['source_data'] = $source;

        if (! $dryRun) {
            $firm->fill($updates);
            $firm->save();
        }

        return [
            'ok' => $ok,
            'match_status' => $updates['match_status'],
            'review_status' => $updates['review_status'],
            'updates' => $updates,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function planImport(OcrParsedFirm $firm): array
    {
        $source = is_array($firm->source_data) ? $firm->source_data : [];
        $raw = is_array($source['raw'] ?? null) ? $source['raw'] : [];
        $parsed = is_array($source['parsed'] ?? null) ? $source['parsed'] : [];

        $firmName = trim((string) ($firm->firm_name ?: $firm->raw_firm_name ?: ($parsed['firm_name'] ?? '') ?: ($raw['firm_name'] ?? '')));
        $caName = trim((string) (($parsed['ca_name'] ?? '') ?: ($raw['ca_name'] ?? '')));
        $city = trim((string) ($firm->city ?: ($parsed['city'] ?? '') ?: ($raw['city'] ?? '')));

        if ((bool) ($firm->is_noise ?? false) || $firmName === '' || mb_strlen($firmName) < 2) {
            return ['action' => 'skip_invalid', 'reason' => 'blank_or_noise_firm'];
        }

        // Address-only / building-as-CA: clear CA for planning.
        $addressAsCa = $caName !== '' && ($this->entities->isAddress($caName) || $this->entities->isAddressShape($caName));
        $addressText = null;
        if ($addressAsCa) {
            $addressText = $caName;
            $caName = '';
        }

        // Minimum data: firm + (ca OR city)
        if ($caName === '' && $city === '') {
            return ['action' => 'skip_invalid', 'reason' => 'missing_ca_and_city'];
        }

        // Already imported from this OCR row?
        if (Schema::hasColumn('ca_masters', 'source_ocr_row_id')) {
            $bySource = CaMaster::query()->where('source_ocr_row_id', $firm->id)->first();
            if ($bySource) {
                return [
                    'action' => 'link',
                    'ca_id' => (int) $bySource->ca_id,
                    'reason' => 'existing_source_ocr_row',
                    'bucket' => $bySource->verification_status === self::VERIFICATION_NEEDS ? 'needs_verification' : 'verified',
                ];
            }
        }

        $candidates = $this->findMasterCandidates($firmName, $caName, $city);
        if (count($candidates) > 1) {
            return [
                'action' => 'ambiguous',
                'reason' => 'multiple_master_candidates',
                'candidate_ids' => $candidates,
                'firm_name' => $firmName,
                'ca_name' => $caName,
                'city' => $city,
            ];
        }
        if (count($candidates) === 1) {
            $existing = CaMaster::query()->find($candidates[0]);
            $linkBucket = ($existing && ($existing->verification_status ?? null) === self::VERIFICATION_NEEDS)
                ? self::VERIFICATION_NEEDS
                : self::VERIFICATION_VERIFIED;

            return [
                'action' => 'link',
                'ca_id' => (int) $candidates[0],
                'reason' => 'exact_master_match',
                'bucket' => $linkBucket,
                'firm_name' => $firmName,
                'ca_name' => $caName,
                'city' => $city,
            ];
        }

        $complete = $firmName !== '' && $caName !== '' && $city !== '' && ! $addressAsCa;
        $verifiedEligible = $complete
            && in_array((string) $firm->match_status, [
                MasterCaDirectImportService::MATCH_VERIFIED,
                MasterCaDirectImportService::MATCH_MATCHED,
            ], true);

        $issue = null;
        if ($caName === '') {
            $issue = 'CA Name Missing';
        } elseif ($city === '') {
            $issue = 'City Missing';
        } elseif ($addressAsCa) {
            $issue = 'Address used as CA';
        } else {
            $classified = $this->audit->classifyRow($firm);
            if (in_array('firm_name_person_extraction_conflict', $classified['issue_codes'] ?? [], true)) {
                $issue = 'OCR Conflict';
                $verifiedEligible = false;
            }
        }

        if ($verifiedEligible && $issue === null) {
            return [
                'action' => 'create',
                'bucket' => 'verified',
                'firm_name' => $firmName,
                'ca_name' => $caName,
                'city' => $city,
            ];
        }

        return [
            'action' => 'create',
            'bucket' => 'needs_verification',
            'firm_name' => $firmName,
            'ca_name' => $caName !== '' ? $caName : null,
            'city' => $city !== '' ? $city : null,
            'address_text' => $addressText,
            'data_quality_issue' => $issue ?? 'Incomplete OCR',
            'data_quality_status' => 'incomplete',
        ];
    }

    /**
     * @return list<int>
     */
    private function findMasterCandidates(string $firmName, string $caName, string $city): array
    {
        $normFirm = mb_strtoupper(trim($firmName));
        if ($normFirm === '') {
            return [];
        }
        $q = CaMaster::query();
        if (Schema::hasColumn('ca_masters', 'normalized_firm_name')) {
            $q->whereRaw('UPPER(TRIM(COALESCE(normalized_firm_name, firm_name))) = ?', [$normFirm]);
        } else {
            $q->whereRaw('UPPER(TRIM(firm_name)) = ?', [$normFirm]);
        }
        if ($caName !== '') {
            $normCa = mb_strtoupper(trim($caName));
            if (Schema::hasColumn('ca_masters', 'normalized_ca_name')) {
                $q->whereRaw('UPPER(TRIM(COALESCE(normalized_ca_name, ca_name))) = ?', [$normCa]);
            } else {
                $q->whereRaw('UPPER(TRIM(COALESCE(ca_name, ""))) = ?', [$normCa]);
            }
        }
        // City: when present, require city evidence match (do not match firm-only across cities).
        if ($city !== '') {
            $normCity = mb_strtoupper(trim($city));
            $q->where(function ($inner) use ($normCity) {
                $inner->whereRaw('UPPER(TRIM(COALESCE(ocr_city_text, ""))) = ?', [$normCity]);
                if (Schema::hasColumn('ca_masters', 'city_id')) {
                    $inner->orWhereHas('city', fn ($cq) => $cq->whereRaw('UPPER(TRIM(city_name)) = ?', [$normCity]));
                }
            });
        } elseif ($caName === '') {
            // Firm-only with no CA/city is too weak for auto-link.
            return [];
        }

        return $q->limit(5)->pluck('ca_id')->map(fn ($id) => (int) $id)->all();
    }

    private function importVerified(OcrParsedFirm $firm, ?int $actorId): void
    {
        $document = OcrDocument::query()->findOrFail($firm->ocr_document_id);
        // Safe path: forceCreate create-or-fill-missing only (never overwrite populated fields).
        $this->importer->approveFirm($document, $firm, $actorId);
        $firm->refresh();
        if ($firm->crm_ca_id && Schema::hasColumn('ca_masters', 'verification_status')) {
            CaMaster::query()->where('ca_id', $firm->crm_ca_id)->update([
                'verification_status' => self::VERIFICATION_VERIFIED,
                'is_verified' => true,
                'source_type' => 'ocr',
                'source_ocr_document_id' => $firm->ocr_document_id,
                'source_ocr_row_id' => $firm->id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function importNeedsVerification(OcrParsedFirm $firm, ?int $actorId, array $plan): void
    {
        $firmName = (string) $plan['firm_name'];
        $caName = $plan['ca_name'] !== null && $plan['ca_name'] !== '' ? (string) $plan['ca_name'] : null;
        $city = $plan['city'] !== null && $plan['city'] !== '' ? (string) $plan['city'] : null;
        $address = trim((string) ($firm->address ?? ''));
        if (! empty($plan['address_text'])) {
            $address = $address === '' ? (string) $plan['address_text'] : ($address.', '.$plan['address_text']);
        }

        $attrs = $this->mapping->toCaMasterAttributes([
            'firm_name' => $firmName,
            'ca_name' => $caName ?: $firmName, // temporary for resolver; overridden below
            'city' => $city,
            'address' => $address !== '' ? $address : null,
            'source_name' => 'OCR Needs Verification',
            'field_meta' => $firm->field_meta,
            'overall_confidence' => $firm->overall_confidence,
        ]);
        // Never invent person CA from firm for Needs Verification.
        // SQLite may still enforce NOT NULL on ca_name — use empty string when null unsupported.
        $attrs['ca_name'] = $caName !== null && $caName !== '' ? $caName : '';
        $attrs['normalized_ca_name'] = $caName !== null && $caName !== '' ? mb_strtoupper($caName) : null;
        $attrs['is_verified'] = false;
        $attrs['status'] = 'New';
        $attrs['rating'] = 1;
        if (Schema::hasColumn('ca_masters', 'verification_status')) {
            $attrs['verification_status'] = self::VERIFICATION_NEEDS;
            $attrs['data_quality_status'] = $plan['data_quality_status'] ?? 'incomplete';
            $attrs['data_quality_issue'] = $plan['data_quality_issue'] ?? 'Incomplete OCR';
            $attrs['source_type'] = 'ocr';
            $attrs['source_ocr_document_id'] = $firm->ocr_document_id;
            $attrs['source_ocr_row_id'] = $firm->id;
            $attrs['ocr_match_status'] = $firm->match_status;
            $attrs['ocr_review_status'] = $firm->review_status;
            $attrs['ocr_match_reason'] = $firm->match_reason;
            $attrs['ocr_validation_errors'] = $firm->validation_errors;
            $attrs['ocr_city_text'] = $city;
        }
        unset($attrs['mobile_no'], $attrs['alternate_mobile_no'], $attrs['gst_no'], $attrs['pan_no'], $attrs['frn'], $attrs['membership_no']);

        $lead = DB::transaction(function () use ($attrs, $firm, $actorId) {
            $lead = $this->mapping->createMaster($attrs);
            $firm->update([
                'crm_ca_id' => $lead->ca_id,
                'matched_ca_id' => $lead->ca_id,
                'match_status' => MasterCaDirectImportService::MATCH_IMPORTED,
                'review_status' => OcrParsedFirm::REVIEW_APPROVED,
                'match_reason' => 'imported_needs_verification',
                'mapped_at' => now(),
            ]);
            if (Schema::hasTable('activity_logs')) {
                \App\Models\ActivityLog::query()->create([
                    'performed_by' => $actorId ? ('User #'.$actorId) : 'System',
                    'module_name' => 'CA_MASTER',
                    'record_id' => (string) $lead->ca_id,
                    'action' => 'OCR Needs Verification Import',
                    'description' => 'OCR row #'.$firm->id.' imported as Needs Verification ('.$attrs['data_quality_issue'].')',
                    'after_value' => json_encode([
                        'source_ocr_row_id' => $firm->id,
                        'verification_status' => self::VERIFICATION_NEEDS,
                        'data_quality_issue' => $attrs['data_quality_issue'] ?? null,
                    ]),
                ]);
            }

            return $lead;
        });

        unset($lead);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function linkExisting(OcrParsedFirm $firm, int $caId, ?int $actorId, array $plan): void
    {
        $master = CaMaster::query()->find($caId);
        if (! $master) {
            throw new \RuntimeException('Master not found for link: '.$caId);
        }
        // Never overwrite verified Master fields — only attach OCR provenance if empty.
        $updates = [];
        if (Schema::hasColumn('ca_masters', 'source_ocr_row_id') && empty($master->source_ocr_row_id)) {
            $updates['source_ocr_row_id'] = $firm->id;
            $updates['source_ocr_document_id'] = $firm->ocr_document_id;
            $updates['source_type'] = $master->source_type ?: 'ocr';
        }
        if ($updates !== []) {
            $master->update($updates);
        }

        $firm->update([
            'crm_ca_id' => $caId,
            'matched_ca_id' => $caId,
            'match_status' => MasterCaDirectImportService::MATCH_DUPLICATE,
            'review_status' => OcrParsedFirm::REVIEW_APPROVED,
            'match_reason' => $plan['reason'] ?? 'linked_existing_master',
            'mapped_at' => now(),
        ]);
    }
}
