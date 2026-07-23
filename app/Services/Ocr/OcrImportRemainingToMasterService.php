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
     *   limit?: int,
     *   show_errors?: bool,
     *   error_limit?: int
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
        $showErrors = (bool) ($options['show_errors'] ?? false);
        $errorLimit = max(1, min(500, (int) ($options['error_limit'] ?? 50)));

        $hasOcrCityText = Schema::hasColumn('ca_masters', 'ocr_city_text');
        $hasSourceOcrRowId = Schema::hasColumn('ca_masters', 'source_ocr_row_id');
        $hasVerificationStatus = Schema::hasColumn('ca_masters', 'verification_status');

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
            'error_categories' => [],
            'schema' => [
                'ocr_city_text' => $hasOcrCityText,
                'source_ocr_row_id' => $hasSourceOcrRowId,
                'verification_status' => $hasVerificationStatus,
            ],
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
            $showErrors,
            $errorLimit,
            $hasOcrCityText,
            $hasSourceOcrRowId,
            $hasVerificationStatus,
        ) {
            foreach ($rows as $firm) {
                /** @var OcrParsedFirm $firm */
                $counts['scanned']++;
                $plan = null;
                $countsTowardLimit = true;

                try {
                    if ($firm->crm_ca_id) {
                        $counts['already_linked_skipped']++;
                    } elseif ((bool) ($firm->is_noise ?? false)) {
                        $counts['noise_rows_skipped']++;
                    } elseif ($needsOnly && $this->looksCompleteForVerifiedBucket($firm)) {
                        // Skip complete rows before expensive revalidation when NV-only.
                        $counts['eligible_verified_rows']++;
                        $countsTowardLimit = false;
                    } else {
                        $reval = $this->revalidateStaging($firm, $dryRun);
                        if (! empty($reval['ok'])) {
                            $counts['revalidated_verified']++;
                        } else {
                            $counts['revalidated_needs_review']++;
                        }
                        if ($dryRun) {
                            foreach ($reval['updates'] ?? [] as $key => $value) {
                                $firm->setAttribute($key, $value);
                            }
                        } else {
                            $firm->refresh();
                        }

                        $plan = $this->planImport($firm, $hasOcrCityText, $hasSourceOcrRowId, $hasVerificationStatus);
                        if ($plan['action'] === 'skip_invalid') {
                            $counts['invalid_rows_skipped']++;
                        } elseif ($plan['action'] === 'ambiguous') {
                            $counts['ambiguous_rows']++;
                        } elseif ($plan['action'] === 'link') {
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
                        } elseif (($plan['bucket'] ?? '') === 'verified') {
                            $counts['eligible_verified_rows']++;
                            $counts['would_create_verified_master']++;
                            if ($needsOnly) {
                                $countsTowardLimit = false;
                            } elseif ($apply && ! $dryRun) {
                                $this->importVerified($firm, $actorId);
                                $counts['created_verified']++;
                            }
                        } else {
                            $counts['needs_verification_rows']++;
                            $counts['would_create_needs_verification_master']++;
                            if ($verifiedOnly) {
                                $countsTowardLimit = false;
                            } elseif ($apply && ! $dryRun) {
                                $this->importNeedsVerification($firm, $actorId, $plan);
                                $counts['created_needs_verification']++;
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $counts['errors']++;
                    $this->recordError($counts, $firm, $e, $showErrors, $errorLimit, is_array($plan) ? $plan : null);
                }

                if ($countsTowardLimit) {
                    $processed++;
                    if ($limit > 0 && $processed >= $limit) {
                        return false;
                    }
                }
            }

            return true;
        });

        return $counts;
    }

    private function looksCompleteForVerifiedBucket(OcrParsedFirm $firm): bool
    {
        $src = is_array($firm->source_data) ? $firm->source_data : [];
        $raw = is_array($src['raw'] ?? null) ? $src['raw'] : [];
        $parsed = is_array($src['parsed'] ?? null) ? $src['parsed'] : [];
        $firmName = trim((string) ($firm->firm_name ?: ($parsed['firm_name'] ?? '') ?: ($raw['firm_name'] ?? '')));
        $caName = trim((string) (($parsed['ca_name'] ?? '') ?: ($raw['ca_name'] ?? '')));
        $city = trim((string) ($firm->city ?: ($parsed['city'] ?? '') ?: ($raw['city'] ?? '')));

        return $firmName !== '' && $caName !== '' && $city !== '';
    }

    /**
     * Fix stale Phase-3 / parser status on staging (no Master write).
     * Never throws for incomplete CA/city — incomplete rows stay needs_review.
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

        $validation = ['ok' => false, 'errors' => [], 'warnings' => [], 'collision_codes' => [], 'auto_apply_ok' => false];
        try {
            $validation = $this->verifier->verify($payload);
        } catch (Throwable $e) {
            $validation['errors'] = ['verification_exception: '.$e->getMessage()];
        }

        $ok = ! empty($validation['ok']) && $firmName !== '' && $caName !== '' && $city !== '';

        $classified = ['issue_codes' => [], 'match_reason' => null];
        try {
            $classified = $this->audit->classifyRow($firm);
        } catch (Throwable $e) {
            $classified['issue_codes'] = ['classify_exception'];
            $classified['match_reason'] = $e->getMessage();
        }

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
            if ($caName === '' || $city === '') {
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
            'review_status' => $ok ? OcrParsedFirm::REVIEW_VERIFIED : OcrParsedFirm::REVIEW_PENDING,
            'match_reason' => $ok
                ? 'revalidated_complete_firm_ca_city'
                : ($validation['errors'][0] ?? ($classified['match_reason'] ?? 'needs_review_after_revalidation')),
        ];

        $source['parsed'] = $payload['parsed'];
        $source['raw'] = $raw;
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
    public function planImport(
        OcrParsedFirm $firm,
        ?bool $hasOcrCityText = null,
        ?bool $hasSourceOcrRowId = null,
        ?bool $hasVerificationStatus = null,
    ): array {
        $hasOcrCityText ??= Schema::hasColumn('ca_masters', 'ocr_city_text');
        $hasSourceOcrRowId ??= Schema::hasColumn('ca_masters', 'source_ocr_row_id');
        $hasVerificationStatus ??= Schema::hasColumn('ca_masters', 'verification_status');

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

        $hasMeaningfulSource = $this->hasMeaningfulSourceData($firm, $raw, $parsed, $addressText);

        // Minimum: firm + (ca OR city). Firm + neither → NV only with meaningful source, else skip.
        if ($caName === '' && $city === '') {
            if (! $hasMeaningfulSource) {
                return ['action' => 'skip_invalid', 'reason' => 'missing_ca_and_city'];
            }
            // Usable incomplete row (e.g. address-only after CA clear, or rich raw OCR).
        }

        // Already imported from this OCR row?
        if ($hasSourceOcrRowId) {
            $bySource = CaMaster::query()->where('source_ocr_row_id', $firm->id)->first();
            if ($bySource) {
                $bucket = ($hasVerificationStatus && $bySource->verification_status === self::VERIFICATION_NEEDS)
                    ? 'needs_verification'
                    : 'verified';

                return [
                    'action' => 'link',
                    'ca_id' => (int) $bySource->ca_id,
                    'reason' => 'existing_source_ocr_row',
                    'bucket' => $bucket,
                ];
            }
        }

        // Fingerprint link (OCR staging fingerprints mirrored onto Master when present).
        $fpLink = $this->findByFingerprint($firm);
        if ($fpLink !== null) {
            return $fpLink;
        }

        $candidates = $this->findMasterCandidates($firmName, $caName, $city, $hasOcrCityText);
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
            $linkBucket = ($hasVerificationStatus && $existing && ($existing->verification_status ?? null) === self::VERIFICATION_NEEDS)
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
        if ($caName === '' && $city === '') {
            $issue = $addressAsCa ? 'Address used as CA' : 'Incomplete OCR';
        } elseif ($caName === '') {
            $issue = 'CA Name Missing';
        } elseif ($city === '') {
            $issue = 'City Missing';
        } elseif ($addressAsCa) {
            $issue = 'Address used as CA';
        } else {
            try {
                $classified = $this->audit->classifyRow($firm);
                if (in_array('firm_name_person_extraction_conflict', $classified['issue_codes'] ?? [], true)) {
                    $issue = 'OCR Conflict';
                    $verifiedEligible = false;
                }
            } catch (Throwable) {
                $issue = 'Incomplete OCR';
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
    private function findMasterCandidates(string $firmName, string $caName, string $city, bool $hasOcrCityText): array
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
                // COALESCE(ca_name, '') is portable; avoid ""-only MySQL quirks.
                $q->whereRaw('UPPER(TRIM(COALESCE(ca_name, ?))) = ?', ['', $normCa]);
            }
        }

        // City filter — never reference ocr_city_text unless the column exists (prod may lack migration).
        if ($city !== '') {
            $normCity = mb_strtoupper(trim($city));
            $q->where(function ($inner) use ($normCity, $hasOcrCityText) {
                $matched = false;
                if ($hasOcrCityText) {
                    $inner->whereRaw('UPPER(TRIM(COALESCE(ocr_city_text, ?))) = ?', ['', $normCity]);
                    $matched = true;
                }
                if (Schema::hasColumn('ca_masters', 'city_id')) {
                    $method = $matched ? 'orWhereHas' : 'whereHas';
                    $inner->{$method}('city', fn ($cq) => $cq->whereRaw('UPPER(TRIM(city_name)) = ?', [$normCity]));
                    $matched = true;
                }
                // No city evidence columns: do not invent a match constraint that can't be evaluated.
                // Firm(+CA) match without city still proceeds; city mismatch risk is accepted only for NV link review.
                if (! $matched) {
                    $inner->whereRaw('1 = 1');
                }
            });
        } elseif ($caName === '') {
            // Firm-only with no CA/city is too weak for auto-link.
            return [];
        }

        return $q->limit(5)->pluck('ca_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByFingerprint(OcrParsedFirm $firm): ?array
    {
        $sourceFp = trim((string) ($firm->source_fingerprint ?? ''));
        $bizFp = trim((string) ($firm->business_fingerprint ?? ''));
        if ($sourceFp === '' && $bizFp === '') {
            return null;
        }

        // Only query Master fingerprint columns when present. Avoid per-row staging scans.
        if (! Schema::hasColumn('ca_masters', 'source_fingerprint')
            && ! Schema::hasColumn('ca_masters', 'business_fingerprint')) {
            return null;
        }

        $q = CaMaster::query()->where(function ($inner) use ($sourceFp, $bizFp) {
            if ($sourceFp !== '' && Schema::hasColumn('ca_masters', 'source_fingerprint')) {
                $inner->orWhere('source_fingerprint', $sourceFp);
            }
            if ($bizFp !== '' && Schema::hasColumn('ca_masters', 'business_fingerprint')) {
                $inner->orWhere('business_fingerprint', $bizFp);
            }
        });
        $ids = $q->limit(5)->pluck('ca_id')->map(fn ($id) => (int) $id)->all();
        if (count($ids) === 1) {
            return [
                'action' => 'link',
                'ca_id' => $ids[0],
                'reason' => 'master_fingerprint_match',
                'bucket' => self::VERIFICATION_VERIFIED,
            ];
        }
        if (count($ids) > 1) {
            return [
                'action' => 'ambiguous',
                'reason' => 'multiple_fingerprint_candidates',
                'candidate_ids' => $ids,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $parsed
     */
    private function hasMeaningfulSourceData(OcrParsedFirm $firm, array $raw, array $parsed, ?string $addressText): bool
    {
        $address = trim((string) ($firm->address ?? ($parsed['address'] ?? '') ?: ($raw['address'] ?? '') ?: ($addressText ?? '')));
        if ($address !== '' && mb_strlen($address) >= 4) {
            return true;
        }
        foreach (['phone', 'email', 'gst_no', 'pan_no', 'frn', 'pincode'] as $key) {
            $v = trim((string) ($firm->{$key} ?? ($parsed[$key] ?? '') ?: ($raw[$key] ?? '')));
            if ($v !== '') {
                return true;
            }
        }
        $rawBlob = trim(implode(' ', array_filter([
            (string) ($raw['line'] ?? ''),
            (string) ($raw['text'] ?? ''),
            (string) ($raw['raw_line'] ?? ''),
            (string) ($raw['firm_name'] ?? ''),
        ])));

        return mb_strlen($rawBlob) >= 8;
    }

    private function importVerified(OcrParsedFirm $firm, ?int $actorId): void
    {
        $document = OcrDocument::query()->findOrFail($firm->ocr_document_id);
        $this->importer->approveFirm($document, $firm, $actorId);
        $firm->refresh();
        if ($firm->crm_ca_id && Schema::hasColumn('ca_masters', 'verification_status')) {
            $updates = [
                'verification_status' => self::VERIFICATION_VERIFIED,
                'is_verified' => true,
            ];
            if (Schema::hasColumn('ca_masters', 'source_type')) {
                $updates['source_type'] = 'ocr';
            }
            if (Schema::hasColumn('ca_masters', 'source_ocr_document_id')) {
                $updates['source_ocr_document_id'] = $firm->ocr_document_id;
            }
            if (Schema::hasColumn('ca_masters', 'source_ocr_row_id')) {
                $updates['source_ocr_row_id'] = $firm->id;
            }
            CaMaster::query()->where('ca_id', $firm->crm_ca_id)->update($this->onlyExistingCaMasterColumns($updates));
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
        $qualityIssue = (string) ($plan['data_quality_issue'] ?? 'Incomplete OCR');
        $qualityStatus = (string) ($plan['data_quality_status'] ?? 'incomplete');
        $address = trim((string) ($firm->address ?? ''));
        if (! empty($plan['address_text'])) {
            $address = $address === '' ? (string) $plan['address_text'] : ($address.', '.$plan['address_text']);
        }

        // Never pass address/building text as ca_name into the mapper.
        $attrs = $this->mapping->toCaMasterAttributes([
            'firm_name' => $firmName,
            'ca_name' => $caName !== null && $caName !== '' ? $caName : $firmName,
            'city' => $city, // null city → null city_id (do not invent)
            'address' => $address !== '' ? $address : null,
            'source_name' => 'OCR Needs Verification',
            'field_meta' => is_array($firm->field_meta) ? $firm->field_meta : [],
            'overall_confidence' => $firm->overall_confidence,
        ]);

        // Override mapper defaults: blank CA stays blank (UI shows "CA Name Missing").
        $attrs['ca_name'] = $caName !== null && $caName !== '' ? $caName : '';
        if (array_key_exists('normalized_ca_name', $attrs) || Schema::hasColumn('ca_masters', 'normalized_ca_name')) {
            $attrs['normalized_ca_name'] = $caName !== null && $caName !== '' ? mb_strtoupper($caName) : null;
        }
        $attrs['city_id'] = $city !== null && $city !== '' ? ($attrs['city_id'] ?? null) : null;
        $attrs['is_verified'] = false;
        $attrs['status'] = $attrs['status'] ?? 'New';
        $attrs['rating'] = $attrs['rating'] ?? 1;
        $attrs['priority'] = $attrs['priority'] ?? 'Medium';

        // Provenance / quality fields — only when columns exist (prod may lack migration).
        $optional = [
            'verification_status' => self::VERIFICATION_NEEDS,
            'data_quality_status' => $qualityStatus,
            'data_quality_issue' => $qualityIssue,
            'source_type' => 'ocr',
            'source_ocr_document_id' => $firm->ocr_document_id,
            'source_ocr_row_id' => $firm->id,
            'ocr_match_status' => $firm->match_status,
            'ocr_review_status' => $firm->review_status,
            'ocr_match_reason' => $firm->match_reason,
            'ocr_validation_errors' => $firm->validation_errors,
            'ocr_city_text' => $city,
        ];
        foreach ($optional as $col => $value) {
            if (Schema::hasColumn('ca_masters', $col)) {
                $attrs[$col] = $value;
            }
        }

        // When verification columns are absent, keep the issue visible on lead_tags.
        if (! Schema::hasColumn('ca_masters', 'data_quality_issue')) {
            $tags = is_array($attrs['lead_tags'] ?? null) ? $attrs['lead_tags'] : [];
            $tags[] = 'Needs Verification';
            $tags[] = $qualityIssue;
            $attrs['lead_tags'] = array_values(array_unique(array_filter($tags)));
        }

        unset(
            $attrs['mobile_no'],
            $attrs['alternate_mobile_no'],
            $attrs['gst_no'],
            $attrs['pan_no'],
            $attrs['frn'],
            $attrs['membership_no'],
        );

        $attrs = $this->onlyExistingCaMasterColumns($attrs);

        // Master + OCR link must succeed together; audit is best-effort AFTER commit.
        $lead = DB::transaction(function () use ($attrs, $firm, $qualityIssue) {
            $lead = $this->mapping->createMaster($attrs);
            $firm->update([
                'crm_ca_id' => $lead->ca_id,
                'matched_ca_id' => $lead->ca_id,
                'match_status' => MasterCaDirectImportService::MATCH_IMPORTED,
                'review_status' => OcrParsedFirm::REVIEW_APPROVED,
                'match_reason' => 'imported_needs_verification:'.$qualityIssue,
                'mapped_at' => now(),
            ]);

            return $lead;
        });

        $this->safeAuditNeedsVerification($lead, $firm, $actorId, $qualityIssue);
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private function onlyExistingCaMasterColumns(array $attrs): array
    {
        static $columns = null;
        $columns ??= array_flip(Schema::getColumnListing('ca_masters'));
        $out = [];
        foreach ($attrs as $key => $value) {
            if (isset($columns[$key])) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function safeAuditNeedsVerification(CaMaster $lead, OcrParsedFirm $firm, ?int $actorId, string $qualityIssue): void
    {
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        try {
            $row = [
                'performed_by' => $actorId ? ('User #'.$actorId) : 'System',
                'module_name' => 'CA_MASTER',
                'record_id' => (string) $lead->ca_id,
                'action' => 'OCR Needs Verification Import',
                'description' => 'OCR row #'.$firm->id.' imported as Needs Verification ('.$qualityIssue.')',
            ];
            if (Schema::hasColumn('activity_logs', 'after_value')) {
                $row['after_value'] = json_encode([
                    'source_ocr_row_id' => $firm->id,
                    'verification_status' => self::VERIFICATION_NEEDS,
                    'data_quality_issue' => $qualityIssue,
                ]);
            }
            // Drop keys for legacy activity_logs schemas.
            $cols = array_flip(Schema::getColumnListing('activity_logs'));
            $row = array_filter($row, static fn ($_, $k) => isset($cols[$k]), ARRAY_FILTER_USE_BOTH);
            \App\Models\ActivityLog::query()->create($row);
        } catch (Throwable) {
            // Audit must not roll back a successful Master insert.
        }
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
        $updates = [];
        if (Schema::hasColumn('ca_masters', 'source_ocr_row_id') && empty($master->source_ocr_row_id)) {
            $updates['source_ocr_row_id'] = $firm->id;
            if (Schema::hasColumn('ca_masters', 'source_ocr_document_id')) {
                $updates['source_ocr_document_id'] = $firm->ocr_document_id;
            }
            if (Schema::hasColumn('ca_masters', 'source_type')) {
                $updates['source_type'] = $master->source_type ?: 'ocr';
            }
        }
        if ($updates !== []) {
            $master->update($this->onlyExistingCaMasterColumns($updates));
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

    /**
     * @param  array<string, mixed>  $counts
     * @param  array<string, mixed>|null  $plan
     */
    private function recordError(array &$counts, OcrParsedFirm $firm, Throwable $e, bool $showErrors, int $errorLimit, ?array $plan = null): void
    {
        $message = $e->getMessage();
        $sqlState = null;
        if (preg_match('/SQLSTATE\[([^\]]+)\]/', $message, $m)) {
            $sqlState = $m[1];
        }
        $category = $this->categorizeError($message, $e);
        $file = $e->getFile();
        $line = $e->getLine();

        if (! isset($counts['error_categories'][$category])) {
            $counts['error_categories'][$category] = [
                'category' => $category,
                'count' => 0,
                'sample_row_ids' => [],
                'samples' => [],
                'origin' => $file.':'.$line,
                'exception_class' => $e::class,
                'message' => $message,
                'sqlstate' => $sqlState,
            ];
        }
        $counts['error_categories'][$category]['count']++;
        if (count($counts['error_categories'][$category]['sample_row_ids']) < 5) {
            $counts['error_categories'][$category]['sample_row_ids'][] = (int) $firm->id;
        }

        $source = is_array($firm->source_data) ? $firm->source_data : [];
        $raw = is_array($source['raw'] ?? null) ? $source['raw'] : [];
        $parsed = is_array($source['parsed'] ?? null) ? $source['parsed'] : [];
        $sample = [
            'id' => (int) $firm->id,
            'firm' => (string) ($firm->firm_name ?: ($parsed['firm_name'] ?? '') ?: ($raw['firm_name'] ?? '')),
            'ca' => (string) (($parsed['ca_name'] ?? '') ?: ($raw['ca_name'] ?? '')),
            'city' => (string) ($firm->city ?: ($parsed['city'] ?? '') ?: ($raw['city'] ?? '')),
            'validation_reason' => (string) ($firm->match_reason ?? ''),
            'exception' => $message,
            'sqlstate' => $sqlState,
            'category' => $category,
            'origin' => $file.':'.$line,
            'payload_keys' => $plan !== null ? array_keys($plan) : [],
            'plan_bucket' => $plan['bucket'] ?? null,
            'plan_action' => $plan['action'] ?? null,
            'data_quality_issue' => $plan['data_quality_issue'] ?? null,
        ];

        if (count($counts['error_samples']) < 50) {
            $counts['error_samples'][] = $sample;
        }
        $perCat = max(1, (int) ceil($errorLimit / max(1, count($counts['error_categories']))));
        if ($showErrors && count($counts['error_categories'][$category]['samples']) < $perCat) {
            $counts['error_categories'][$category]['samples'][] = $sample;
        }
    }

    private function categorizeError(string $message, Throwable $e): string
    {
        if (preg_match('/Undefined array key ["\']?data_quality_issue/i', $message)) {
            return 'undefined_data_quality_issue_key';
        }
        if (preg_match('/Unknown column [\'`]?ocr_city_text/i', $message)) {
            return 'schema_missing_ocr_city_text';
        }
        if (preg_match('/Unknown column [\'`]?source_ocr_row_id/i', $message)) {
            return 'schema_missing_source_ocr_row_id';
        }
        if (preg_match('/Unknown column [\'`]?verification_status/i', $message)) {
            return 'schema_missing_verification_status';
        }
        if (preg_match('/Unknown column [\'`]?after_value/i', $message)) {
            return 'schema_missing_activity_after_value';
        }
        if (preg_match('/Unknown column [\'`]?([^\'`\s]+)/i', $message, $m)) {
            return 'schema_unknown_column:'.$m[1];
        }
        if (preg_match('/SQLSTATE\[23000\].*Duplicate/i', $message)) {
            return 'duplicate_key';
        }
        if (preg_match('/SQLSTATE\[23000\]/i', $message)) {
            return 'integrity_constraint';
        }
        if (preg_match('/SQLSTATE\[22001\]/i', $message)) {
            return 'data_too_long';
        }
        if (preg_match('/SQLSTATE/i', $message)) {
            return 'sql_error';
        }
        if (str_contains($message, 'Master not found')) {
            return 'master_link_missing';
        }
        if ($e instanceof \InvalidArgumentException) {
            return 'invalid_argument';
        }

        return 'unexpected_'.$e::class;
    }
}
