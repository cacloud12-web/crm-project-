<?php

namespace App\Services\Ocr;

use App\Models\OcrParsedFirm;
use App\Models\OcrStagingCorrectionAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Staging-only reprocess for unlinked OCR rows.
 * Never writes ca_masters / crm_ca_id / approval.
 */
class OcrReprocessUnlinkedService
{
    public const SUPPORTED_CATEGORIES = [
        'numeric_prefix_address',
        'building_name_detected_as_ca_name',
        'address_detected_as_ca_name',
        'raw_and_parsed_ca_name_minor_difference',
        'firm_name_person_extraction_conflict',
        'missing_city',
        'missing_ca_name',
    ];

    public function __construct(
        private readonly OcrUnlinkedCaNameAuditService $audit,
        private readonly OcrEntityClassificationService $entities,
        private readonly OcrFirmCaCityExtractorService $extractor,
        private readonly OcrHumanNameClassifier $humanNames,
        private readonly OcrSourceVerificationService $verifier,
    ) {}

    /**
     * @param  array{
     *   all?: bool,
     *   document?: int|null,
     *   category?: string|null,
     *   dry_run?: bool,
     *   apply_safe_only?: bool,
     *   chunk?: int,
     *   limit?: int,
     *   actor?: int|null
     * }  $options
     * @return array<string, mixed>
     */
    public function reprocess(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? true);
        $applySafe = (bool) ($options['apply_safe_only'] ?? false);
        if ($applySafe) {
            $dryRun = false;
        } else {
            $dryRun = true;
        }

        $documentId = isset($options['document']) ? (int) $options['document'] : null;
        $categoryFilter = isset($options['category']) ? trim((string) $options['category']) : '';
        $chunk = max(50, (int) ($options['chunk'] ?? 500));
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $actorId = isset($options['actor']) ? (int) $options['actor'] : null;

        $stats = [];
        foreach (self::SUPPORTED_CATEGORIES as $cat) {
            $stats[$cat] = $this->emptyCategoryBucket();
        }
        $stats['skipped_other'] = $this->emptyCategoryBucket();
        $totals = [
            'analyzed' => 0,
            'would_change' => 0,
            'applied' => 0,
            'errors' => 0,
            'dry_run' => $dryRun,
            'apply_safe_only' => $applySafe && ! $dryRun,
        ];

        $cityContext = [];
        $loadedCityDocs = [];
        $query = OcrParsedFirm::query()
            ->whereNull('crm_ca_id')
            ->where('match_status', 'needs_review')
            ->when($documentId, fn ($q) => $q->where('ocr_document_id', $documentId))
            ->orderBy('ocr_document_id')
            ->orderBy('id');

        $emitted = 0;
        $query->chunkById($chunk, function ($rows) use (
            &$stats,
            &$totals,
            &$cityContext,
            &$loadedCityDocs,
            &$emitted,
            $categoryFilter,
            $limit,
            $dryRun,
            $applySafe,
            $actorId,
        ) {
            foreach ($rows as $firm) {
                /** @var OcrParsedFirm $firm */
                $docId = (int) $firm->ocr_document_id;
                if (! isset($loadedCityDocs[$docId])) {
                    $this->preloadDocumentCityContext($cityContext, $docId);
                    $loadedCityDocs[$docId] = true;
                }

                $classified = $this->audit->classifyRow($firm);
                $primary = (string) ($classified['primary_category'] ?? 'other');

                // --category scopes by Phase-2 primary category only.
                if ($categoryFilter !== '' && $primary !== $categoryFilter) {
                    continue;
                }
                if (! in_array($primary, self::SUPPORTED_CATEGORIES, true)) {
                    $stats['skipped_other']['rows_analyzed']++;
                    $totals['analyzed']++;
                    $emitted++;
                    if ($limit > 0 && $emitted >= $limit) {
                        return false;
                    }

                    continue;
                }

                $bucket = &$stats[$primary];
                $bucket['rows_analyzed']++;
                $totals['analyzed']++;
                $emitted++;

                try {
                    $plan = $this->planCorrection($firm, $classified, $cityContext);
                    $this->accumulatePlan($bucket, $plan);

                    if ($plan['would_change']) {
                        $totals['would_change']++;
                        // Dry-run must not write staging rows or audit rows.
                        if (! $dryRun && $applySafe && $plan['safe_to_apply']) {
                            $this->applyCorrection($firm, $classified, $plan, $actorId, false);
                            $totals['applied']++;
                            $bucket['applied']++;
                        }
                    }
                } catch (Throwable $e) {
                    $bucket['errors']++;
                    $totals['errors']++;
                    $bucket['error_samples'][] = [
                        'id' => $firm->id,
                        'error' => $e->getMessage(),
                    ];
                }

                if ($limit > 0 && $emitted >= $limit) {
                    return false;
                }
            }

            return true;
        });

        return [
            'totals' => $totals,
            'categories' => $stats,
            'supported_categories' => self::SUPPORTED_CATEGORIES,
        ];
    }

    /**
     * UI-ready suggestion payload (does not mutate).
     *
     * @return array<string, mixed>
     */
    public function suggestionPayload(OcrParsedFirm $firm): array
    {
        $classified = $this->audit->classifyRow($firm);
        $source = is_array($firm->source_data) ? $firm->source_data : [];
        $raw = is_array($source['raw'] ?? null) ? $source['raw'] : [];
        $parsed = is_array($source['parsed'] ?? null) ? $source['parsed'] : [];
        $meta = is_array($firm->field_meta) ? $firm->field_meta : [];
        $suggested = is_array($meta['suggested_ca_name'] ?? null) ? $meta['suggested_ca_name'] : [];
        $plan = $this->planCorrection($firm, $classified, []);

        return [
            'issue_category' => $classified['primary_category'] ?? null,
            'issue_codes' => $classified['issue_codes'] ?? [],
            'raw_firm' => $raw['firm_name'] ?? $firm->raw_firm_name,
            'raw_ca' => $raw['ca_name'] ?? null,
            'raw_city' => $raw['city'] ?? null,
            'parsed_firm' => $parsed['firm_name'] ?? $firm->firm_name,
            'parsed_ca' => $parsed['ca_name'] ?? null,
            'parsed_city' => $parsed['city'] ?? $firm->city,
            'suggested_ca' => $suggested['value'] ?? ($plan['suggested_ca'] ?? null),
            'suggested_city' => $plan['suggested_city'] ?? null,
            'suggested_address' => $plan['suggested_address'] ?? null,
            'correction_reason' => $plan['reason'] ?? ($classified['match_reason'] ?? null),
            'confidence' => $plan['confidence'] ?? $firm->overall_confidence,
            'safe_repair_candidate' => (bool) ($plan['safe_to_apply'] ?? $classified['safe_repair_candidate'] ?? false),
            'manual_review_required' => (bool) ($classified['manual_review_required'] ?? true),
            'comparison_class' => $plan['comparison_class'] ?? null,
        ];
    }

    /**
     * @return array<string, int|list<mixed>>
     */
    private function emptyCategoryBucket(): array
    {
        return [
            'rows_analyzed' => 0,
            'would_change_parsed_ca' => 0,
            'would_move_ca_to_address' => 0,
            'would_recover_city' => 0,
            'would_suggest_derived_ca' => 0,
            'would_become_verified' => 0,
            'would_remain_needs_review' => 0,
            'applied' => 0,
            'errors' => 0,
            'error_samples' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $classified
     * @param  array<string, array<string, mixed>>  $cityContext
     * @return array<string, mixed>
     */
    private function planCorrection(OcrParsedFirm $firm, array $classified, array $cityContext): array
    {
        $primary = (string) ($classified['primary_category'] ?? 'other');
        $source = is_array($firm->source_data) ? $firm->source_data : [];
        $raw = is_array($source['raw'] ?? null) ? $source['raw'] : [];
        $parsed = is_array($source['parsed'] ?? null) ? $source['parsed'] : [];
        $rawCa = trim((string) ($raw['ca_name'] ?? $classified['raw_ca_name'] ?? ''));
        $parsedCa = trim((string) ($parsed['ca_name'] ?? $classified['parsed_ca_name'] ?? ''));
        $city = trim((string) ($firm->city ?: ($parsed['city'] ?? '') ?: ($raw['city'] ?? '')));
        $firmName = trim((string) ($firm->firm_name ?: $firm->raw_firm_name ?: ''));

        $plan = [
            'would_change' => false,
            'safe_to_apply' => false,
            'would_change_parsed_ca' => false,
            'would_move_ca_to_address' => false,
            'would_recover_city' => false,
            'would_suggest_derived_ca' => false,
            'would_become_verified' => false,
            'would_remain_needs_review' => true,
            'clear_ca_name' => false,
            'new_parsed_ca' => null,
            'suggested_ca' => null,
            'suggested_city' => null,
            'suggested_address' => null,
            'new_city' => null,
            'city_evidence' => null,
            'reason' => null,
            'confidence' => 0.7,
            'comparison_class' => null,
            'new_match_status' => 'needs_review',
            'new_match_reason' => null,
        ];

        if (in_array($primary, [
            'numeric_prefix_address',
            'building_name_detected_as_ca_name',
            'address_detected_as_ca_name',
        ], true)) {
            $addrText = $rawCa !== '' ? $rawCa : $parsedCa;
            // Trust Phase-2 primary category; also accept live address classifier.
            $isAddr = $addrText !== '' && (
                $primary === 'numeric_prefix_address'
                || $this->entities->isAddress($addrText)
                || $this->entities->isAddressShape($addrText)
            );
            if ($isAddr) {
                $plan['would_change'] = true;
                $plan['safe_to_apply'] = true;
                $plan['would_move_ca_to_address'] = true;
                $plan['would_change_parsed_ca'] = true;
                $plan['clear_ca_name'] = true;
                $plan['suggested_address'] = $addrText;
                $plan['reason'] = 'address_reclassified_from_ca_name';
                $plan['comparison_class'] = 'address_reclassified';
                $plan['new_match_reason'] = 'CA name cleared — address/building text reclassified; needs person CA.';
                $plan['would_remain_needs_review'] = true;
            }

            return $plan;
        }

        if ($primary === 'raw_and_parsed_ca_name_minor_difference' && $rawCa !== '' && $parsedCa !== '') {
            $class = $this->verifier->comparisonClass($rawCa, $parsedCa);
            $plan['comparison_class'] = $class;
            if (in_array($class, ['exact', 'formatting_only', 'safe_decoration_removal'], true)) {
                $plan['would_change'] = true;
                $plan['safe_to_apply'] = true;
                $plan['would_change_parsed_ca'] = true;
                $plan['new_parsed_ca'] = $rawCa;
                $plan['reason'] = 'align_parsed_ca_to_raw_'.$class;
                $plan['new_match_status'] = ($firmName !== '' && $city !== '') ? 'verified' : 'needs_review';
                $plan['would_become_verified'] = $plan['new_match_status'] === 'verified';
                $plan['would_remain_needs_review'] = ! $plan['would_become_verified'];
                $plan['new_match_reason'] = $plan['would_become_verified'] ? null : 'Aligned formatting; still incomplete.';
            }

            return $plan;
        }

        if ($primary === 'firm_name_person_extraction_conflict') {
            $derived = $this->deriveFromFirm($firmName, $city);
            if ($derived !== null) {
                $plan['would_change'] = true;
                $plan['safe_to_apply'] = true; // staging suggestion only
                $plan['would_suggest_derived_ca'] = true;
                $plan['suggested_ca'] = $derived;
                $plan['reason'] = 'firm_derived_suggestion_keep_raw';
                $plan['comparison_class'] = 'firm_derived_suggestion';
                $plan['would_remain_needs_review'] = true;
                $plan['new_match_reason'] = 'Firm-derived CA suggestion available; raw OCR CA preserved for review.';
            }

            return $plan;
        }

        if ($primary === 'missing_ca_name') {
            $derived = $this->deriveFromFirm($firmName, $city);
            if ($derived !== null && $city !== '') {
                $plan['would_change'] = true;
                $plan['safe_to_apply'] = true;
                $plan['would_change_parsed_ca'] = true;
                $plan['would_suggest_derived_ca'] = true;
                $plan['new_parsed_ca'] = $derived;
                $plan['suggested_ca'] = $derived;
                $plan['reason'] = 'firm_derived_missing_raw_ca';
                $plan['comparison_class'] = 'firm_derived_missing_raw';
                // Safe-repair candidate for staging — not Master-accepted / not auto-verified.
                $plan['new_match_status'] = 'needs_review';
                $plan['would_become_verified'] = false;
                $plan['would_remain_needs_review'] = true;
                $plan['new_match_reason'] = 'Firm-derived CA filled into staging; raw OCR CA absent — review before Master.';
                $plan['confidence'] = 0.75;
            }

            return $plan;
        }

        if ($primary === 'missing_city') {
            $recovered = $this->recoverCity($firm, $cityContext);
            if ($recovered !== null) {
                $plan['would_change'] = true;
                $plan['safe_to_apply'] = true;
                $plan['would_recover_city'] = true;
                $plan['new_city'] = $recovered['city'];
                $plan['suggested_city'] = $recovered['city'];
                $plan['city_evidence'] = $recovered['evidence'];
                $plan['reason'] = 'city_recovered_'.$recovered['evidence'];
                $hasCa = $rawCa !== '' || $parsedCa !== '';
                $plan['new_match_status'] = ($firmName !== '' && $hasCa) ? 'verified' : 'needs_review';
                $plan['would_become_verified'] = $plan['new_match_status'] === 'verified';
                $plan['would_remain_needs_review'] = ! $plan['would_become_verified'];
                $plan['new_match_reason'] = $plan['would_become_verified'] ? null : 'City recovered; CA still missing.';
            }

            return $plan;
        }

        return $plan;
    }

    private function deriveFromFirm(string $firmName, ?string $city): ?string
    {
        if ($firmName === '') {
            return null;
        }

        return $this->extractor->suggestCaFromFirmName($firmName, $city);
    }

    /**
     * Load city evidence for a document from all rows (including linked).
     * Read-only — never mutates linked/master rows.
     *
     * @param  array<string, mixed>  $cityContext
     */
    private function preloadDocumentCityContext(array &$cityContext, int $documentId): void
    {
        OcrParsedFirm::query()
            ->where('ocr_document_id', $documentId)
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->orderBy('id')
            ->select([
                'id',
                'ocr_document_id',
                'page_number',
                'column_number',
                'city',
                'field_meta',
                'sequence_no',
            ])
            ->chunkById(1000, function ($rows) use (&$cityContext) {
                foreach ($rows as $row) {
                    $this->rememberCityContext($cityContext, $row);
                }
            });
    }

    /**
     * @param  array<string, array<string, mixed>>  $cityContext
     * @return array{city: string, evidence: string}|null
     */
    private function recoverCity(OcrParsedFirm $firm, array $cityContext): ?array
    {
        $docId = (int) $firm->ocr_document_id;
        $firmId = (int) $firm->id;
        $page = $firm->page_number;
        $column = $firm->column_number;
        $key = $docId.'|'.($page ?? 'x').'|'.($column ?? 'x');

        $headingCity = null;
        foreach ($cityContext[$docId.'|headings'] ?? [] as $heading) {
            if ((int) ($heading['source_firm_id'] ?? 0) >= $firmId) {
                break;
            }
            $headingCity = (string) ($heading['city'] ?? '');
        }
        if ($headingCity === '') {
            $headingCity = null;
        }

        $sectionCity = null;
        foreach ($cityContext[$key.'|timeline'] ?? [] as $entry) {
            if ((int) ($entry['source_firm_id'] ?? 0) >= $firmId) {
                break;
            }
            $sectionCity = (string) ($entry['city'] ?? '');
        }
        if ($sectionCity === '') {
            $sectionCity = null;
        }

        // Ambiguous: section context disagrees with latest prior heading → keep review.
        if ($sectionCity !== null && $headingCity !== null
            && mb_strtoupper($sectionCity) !== mb_strtoupper($headingCity)) {
            return null;
        }

        if ($headingCity !== null) {
            return [
                'city' => $headingCity,
                'evidence' => 'section_heading',
            ];
        }
        if ($sectionCity !== null) {
            return [
                'city' => $sectionCity,
                'evidence' => 'document_section_context',
            ];
        }

        $source = is_array($firm->source_data) ? $firm->source_data : [];
        $address = trim((string) ($firm->address ?: ($source['parsed']['address'] ?? '') ?: ($source['raw']['address'] ?? '')));
        if ($address !== '') {
            $parts = preg_split('/[,\n]+/', $address) ?: [];
            $last = trim((string) end($parts));
            if ($last !== '') {
                $resolver = app(OcrCityResolverService::class);
                $resolved = $resolver->resolve($last);
                if (is_array($resolved) && ($resolved['city_match_type'] ?? null) === 'city_master'
                    && ! empty($resolved['canonical_city'])) {
                    return [
                        'city' => (string) $resolved['canonical_city'],
                        'evidence' => 'address_exact_city',
                    ];
                }
                if ($this->entities->isCity($last)) {
                    return ['city' => mb_strtoupper($last), 'evidence' => 'address_exact_city'];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $cityContext
     */
    private function rememberCityContext(array &$cityContext, OcrParsedFirm $firm): void
    {
        $city = trim((string) ($firm->city ?? ''));
        if ($city === '') {
            return;
        }
        $docId = (int) $firm->ocr_document_id;
        $page = $firm->page_number;
        $column = $firm->column_number;
        $key = $docId.'|'.($page ?? 'x').'|'.($column ?? 'x');
        $cityContext[$key] = [
            'city' => $city,
            'source_firm_id' => $firm->id,
        ];
        $cityContext[$key.'|timeline'] ??= [];
        $cityContext[$key.'|timeline'][] = [
            'city' => $city,
            'source_firm_id' => $firm->id,
        ];
        $meta = is_array($firm->field_meta) ? $firm->field_meta : [];
        $reason = $meta['city']['reason'] ?? ($meta['city']['evidence'] ?? null);
        if (in_array($reason, ['section_heading', 'city_heading', 'directory_heading'], true)) {
            $cityContext[$docId.'|headings'] ??= [];
            $cityContext[$docId.'|headings'][] = [
                'city' => $city,
                'source_firm_id' => $firm->id,
            ];
            $cityContext[$docId.'|heading'] = [
                'city' => $city,
                'source_firm_id' => $firm->id,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $bucket
     * @param  array<string, mixed>  $plan
     */
    private function accumulatePlan(array &$bucket, array $plan): void
    {
        foreach ([
            'would_change_parsed_ca',
            'would_move_ca_to_address',
            'would_recover_city',
            'would_suggest_derived_ca',
            'would_become_verified',
            'would_remain_needs_review',
        ] as $key) {
            if (! empty($plan[$key])) {
                $bucket[$key]++;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $classified
     * @param  array<string, mixed>  $plan
     */
    private function applyCorrection(
        OcrParsedFirm $firm,
        array $classified,
        array $plan,
        ?int $actorId,
        bool $dryRun,
    ): void {
        DB::transaction(function () use ($firm, $classified, $plan, $actorId, $dryRun) {
            $firm->refresh();
            if ($firm->crm_ca_id) {
                return;
            }

            $source = is_array($firm->source_data) ? $firm->source_data : [];
            $raw = is_array($source['raw'] ?? null) ? $source['raw'] : [];
            $parsed = is_array($source['parsed'] ?? null) ? $source['parsed'] : [];
            $normalized = is_array($source['normalized'] ?? null) ? $source['normalized'] : [];
            $meta = is_array($firm->field_meta) ? $firm->field_meta : [];

            $old = [
                'parsed_ca' => $parsed['ca_name'] ?? null,
                'parsed_city' => $parsed['city'] ?? $firm->city,
                'address' => $firm->address,
                'review_status' => $firm->review_status,
                'match_status' => $firm->match_status,
            ];

            if (! empty($plan['clear_ca_name'])) {
                $addr = trim((string) ($plan['suggested_address'] ?? $raw['ca_name'] ?? $parsed['ca_name'] ?? ''));
                if ($addr !== '') {
                    $existingAddr = trim((string) ($firm->address ?? ''));
                    $firm->address = $existingAddr === '' ? $addr : ($existingAddr.', '.$addr);
                    $parsed['address'] = $firm->address;
                }
                $parsed['ca_name'] = null;
                $normalized['ca_name'] = null;
                // Preserve raw.ca_name exactly.
            }

            if (! empty($plan['new_parsed_ca'])) {
                $parsed['ca_name'] = $plan['new_parsed_ca'];
                $normalized['ca_name'] = mb_strtoupper((string) $plan['new_parsed_ca']);
            }

            if (! empty($plan['suggested_ca']) && empty($plan['new_parsed_ca'])) {
                $meta['suggested_ca_name'] = [
                    'value' => $plan['suggested_ca'],
                    'reason' => $plan['reason'],
                    'comparison_class' => $plan['comparison_class'],
                    'safe_repair_candidate' => false,
                    'manual_review_required' => true,
                ];
            }

            if (! empty($plan['new_city'])) {
                $firm->city = $plan['new_city'];
                $parsed['city'] = $plan['new_city'];
                $normalized['city'] = mb_strtoupper((string) $plan['new_city']);
                $meta['city'] = array_merge(is_array($meta['city'] ?? null) ? $meta['city'] : [], [
                    'value' => $plan['new_city'],
                    'evidence' => $plan['city_evidence'] ?? 'document_section_context',
                    'reason' => $plan['city_evidence'] ?? 'document_section_context',
                ]);
            }

            $source['raw'] = $raw;
            $source['parsed'] = $parsed;
            $source['normalized'] = $normalized;
            $source['correction'] = [
                'category' => $classified['primary_category'] ?? null,
                'reason' => $plan['reason'] ?? null,
                'comparison_class' => $plan['comparison_class'] ?? null,
                'at' => now()->toIso8601String(),
            ];

            $updates = [
                'source_data' => $source,
                'field_meta' => $meta,
                'address' => $firm->address,
                'city' => $firm->city,
                'match_status' => $plan['new_match_status'] ?? $firm->match_status,
                'match_reason' => $plan['new_match_reason'] ?? $firm->match_reason,
                'review_status' => OcrParsedFirm::REVIEW_PENDING,
            ];
            // Never set crm_ca_id / never approve.
            $firm->fill($updates);
            $firm->save();

            $this->writeAudit($firm, $classified, array_merge($plan, [
                'old_snapshot' => $old,
                'new_snapshot' => [
                    'parsed_ca' => $parsed['ca_name'] ?? null,
                    'parsed_city' => $parsed['city'] ?? $firm->city,
                    'address' => $firm->address,
                    'review_status' => $firm->review_status,
                    'match_status' => $firm->match_status,
                ],
            ]), $actorId, $dryRun);
        });
    }

    /**
     * @param  array<string, mixed>  $classified
     * @param  array<string, mixed>  $plan
     */
    private function writeAudit(
        OcrParsedFirm $firm,
        array $classified,
        array $plan,
        ?int $actorId,
        bool $dryRun,
    ): void {
        if (! Schema::hasTable('ocr_staging_correction_audits')) {
            return;
        }
        if (empty($plan['would_change']) && empty($plan['old_snapshot'])) {
            return;
        }

        OcrStagingCorrectionAudit::query()->create([
            'ocr_parsed_firm_id' => $firm->id,
            'ocr_document_id' => $firm->ocr_document_id,
            'category' => $classified['primary_category'] ?? 'other',
            'raw_values' => [
                'firm' => $classified['raw_firm_name'] ?? null,
                'ca' => $classified['raw_ca_name'] ?? null,
                'city' => $classified['city'] ?? null,
            ],
            'old_parsed_values' => $plan['old_snapshot'] ?? [
                'ca' => $classified['parsed_ca_name'] ?? null,
                'city' => $classified['city'] ?? null,
            ],
            'new_parsed_values' => $plan['new_snapshot'] ?? [
                'ca' => $plan['new_parsed_ca'] ?? $plan['suggested_ca'] ?? null,
                'city' => $plan['new_city'] ?? $plan['suggested_city'] ?? null,
                'address' => $plan['suggested_address'] ?? null,
            ],
            'old_review_status' => $firm->review_status,
            'new_review_status' => OcrParsedFirm::REVIEW_PENDING,
            'old_match_status' => $firm->match_status,
            'new_match_status' => $plan['new_match_status'] ?? $firm->match_status,
            'correction_reason' => $plan['reason'] ?? null,
            'confidence' => $plan['confidence'] ?? null,
            'actor_id' => $actorId,
            'dry_run' => $dryRun,
            'meta' => [
                'comparison_class' => $plan['comparison_class'] ?? null,
                'safe_to_apply' => $plan['safe_to_apply'] ?? false,
                'issue_codes' => $classified['issue_codes'] ?? [],
            ],
        ]);
    }
}
