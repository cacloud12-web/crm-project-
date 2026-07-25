<?php

namespace App\Services\Ocr;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deep read-only audit: Master missing city_id → full OCR pipeline evidence.
 * Never writes. Never peels CA/firm. Never invents cities.
 */
class OcrMissingCityAuditService
{
    public const DECISION_APPLY = 'apply_exact_unique';

    public const DECISION_ALIAS = 'apply_locality_alias';

    public const DECISION_APPLY_ADDRESS = 'apply_from_address_context';

    public const DECISION_APPLY_HEADING = 'apply_from_page_heading';

    public const DECISION_APPLY_SECTION = 'apply_from_section_sibling';

    public const DECISION_SKIP_AMBIGUOUS = 'skip_ambiguous';

    public const DECISION_SKIP_LOCALITY = 'skip_locality_only';

    public const DECISION_SKIP_NO_OCR = 'skip_no_city_information';

    public const DECISION_SKIP_HAS_CITY = 'skip_already_has_city';

    public const DECISION_SKIP_UNCERTAIN = 'skip_uncertain';

    public const DECISION_SKIP_CITY_TABLE_GAP = 'skip_city_not_in_cities_table';

    /** A–E exclusive audit classes (OCR-linked missing city). */
    public const CLASS_A = 'A'; // in OCR + uniquely mappable now

    public const CLASS_B = 'B'; // in OCR but normalization/disambiguation rejected

    public const CLASS_C = 'C'; // in OCR evidence but parser ignored / stored locality only

    public const CLASS_D = 'D'; // in staging city text but mapping/lookup dropped it

    public const CLASS_E = 'E'; // truly absent from stored OCR

    /** @var array<int, list<string>> */
    private array $extractedLinesCache = [];

    /** @var array<string, list<array{firm: string, city: string, page: int, col: int}>> */
    private array $docFirmCityCache = [];

    /** @var array<int, string> city_id => city_name */
    private array $cityIdToName = [];

    /**
     * @param  array{
     *   export?: string|null,
     *   limit?: int,
     *   include_deleted?: bool,
     *   ocr_linked_only?: bool
     * }  $options
     * @return array<string, mixed>
     */
    public function audit(array $options = []): array
    {
        $export = $options['export'] ?? storage_path('app/audits/missing-cities-pipeline-audit.csv');
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $includeDeleted = (bool) ($options['include_deleted'] ?? false);
        $ocrLinkedOnly = (bool) ($options['ocr_linked_only'] ?? true);

        $cityIndex = $this->buildCityNameIndex();
        $aliases = $this->localityAliases();

        $totals = [
            'missing_cities' => 0,
            'ocr_linked_missing' => 0,
            'recoverable_automatic' => 0,
            'manual_review' => 0,
            'absolutely_no_city_in_ocr' => 0,
            'by_failure_stage' => [],
            'by_decision' => [],
            'by_class' => [
                self::CLASS_A => 0,
                self::CLASS_B => 0,
                self::CLASS_C => 0,
                self::CLASS_D => 0,
                self::CLASS_E => 0,
            ],
        ];

        $dir = dirname((string) $export);
        if ($dir !== '' && $dir !== '.' && ! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $fh = fopen((string) $export, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Unable to open CSV: '.$export);
        }

        fputcsv($fh, [
            'Master CA ID',
            'Firm Name',
            'OCR Document',
            'Page Number',
            'Raw OCR City',
            'Address Text',
            'Heading City',
            'Resolved City',
            'Resolved City ID',
            'Parser Stage',
            'Failure Reason',
            'Required Fix',
            'Decision',
            'AE Class',
            'Confidence',
            'OCR Locality',
            'Evidence Sources',
            'source_ocr_row_id',
        ]);

        $scanned = 0;
        $query = DB::table('ca_masters')
            ->where(function ($q) {
                $q->whereNull('city_id')->orWhere('city_id', 0);
            })
            ->orderBy('ca_id');
        if (! $includeDeleted && Schema::hasColumn('ca_masters', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        if ($ocrLinkedOnly && Schema::hasColumn('ca_masters', 'source_ocr_row_id')) {
            $query->whereNotNull('source_ocr_row_id');
        }

        $query->select(['ca_id', 'firm_name', 'city_id', 'ocr_city_text', 'source_ocr_row_id', 'source_ocr_document_id'])
            ->chunkById(300, function ($masters) use (
                &$scanned, &$totals, $limit, $cityIndex, $aliases, $fh, $ocrLinkedOnly
            ) {
                foreach ($masters as $master) {
                    if ($limit > 0 && $scanned >= $limit) {
                        return false;
                    }
                    $scanned++;
                    $totals['missing_cities']++;
                    if (! empty($master->source_ocr_row_id)) {
                        $totals['ocr_linked_missing']++;
                    }

                    $row = $this->classifyMaster($master, $cityIndex, $aliases);
                    $ae = (string) ($row['ae_class'] ?? self::CLASS_E);

                    $stage = (string) ($row['parser_stage'] ?? 'unknown');
                    $totals['by_failure_stage'][$stage] = ($totals['by_failure_stage'][$stage] ?? 0) + 1;
                    $dec = (string) ($row['decision'] ?? '');
                    $totals['by_decision'][$dec] = ($totals['by_decision'][$dec] ?? 0) + 1;
                    $totals['by_class'][$ae] = ($totals['by_class'][$ae] ?? 0) + 1;

                    if ($this->isRecoverableDecision($dec)) {
                        $totals['recoverable_automatic']++;
                    } else {
                        $totals['manual_review']++;
                    }
                    if ($dec === self::DECISION_SKIP_NO_OCR && $ae === self::CLASS_E) {
                        $totals['absolutely_no_city_in_ocr']++;
                    } elseif ($ae === self::CLASS_E) {
                        $totals['absolutely_no_city_in_ocr']++;
                    }

                    fputcsv($fh, [
                        $row['ca_id'],
                        $row['firm_name'],
                        $row['ocr_document'],
                        $row['page_number'],
                        $row['raw_ocr_city'],
                        $row['address_text'],
                        $row['heading_city'],
                        $row['resolved_city'],
                        $row['resolved_city_id'],
                        $row['parser_stage'],
                        $row['failure_reason'],
                        $row['required_fix'],
                        $row['decision'],
                        $ae,
                        $row['confidence'],
                        $row['ocr_locality'],
                        $row['evidence_sources'],
                        $master->source_ocr_row_id ?? '',
                    ]);
                }

                return $limit <= 0 || $scanned < $limit;
            }, 'ca_id');

        fclose($fh);

        $totals['ocr_linked_only'] = $ocrLinkedOnly;
        $totals['success_pct_of_ocr_linked'] = $totals['missing_cities'] > 0
            ? round(100 * $totals['recoverable_automatic'] / $totals['missing_cities'], 2)
            : 0.0;

        return [
            'totals' => $totals,
            'export_path' => $export,
            'cities_indexed' => count($cityIndex),
            'aliases_configured' => count($aliases),
        ];
    }

    public function isRecoverableDecision(string $decision): bool
    {
        return in_array($decision, [
            self::DECISION_APPLY,
            self::DECISION_ALIAS,
            self::DECISION_APPLY_ADDRESS,
            self::DECISION_APPLY_HEADING,
            self::DECISION_APPLY_SECTION,
        ], true);
    }

    /**
     * @param  array<string, int|null>  $cityIndex
     * @param  array<string, string|int>  $aliases
     * @return array<string, mixed>
     */
    public function classifyMaster(object $master, array $cityIndex, array $aliases): array
    {
        $caId = (int) $master->ca_id;
        $firmName = trim((string) ($master->firm_name ?? ''));
        $currentCityId = $this->validCityId($master->city_id ?? null);

        $ev = $this->gatherPipelineEvidence($master, $cityIndex, $aliases);

        $base = [
            'ca_id' => $caId,
            'firm_name' => $firmName,
            'current_city_id' => $currentCityId,
            'ocr_document' => $ev['document_label'],
            'page_number' => $ev['page_number'],
            'raw_ocr_city' => $ev['raw_ocr_city'],
            'address_text' => $ev['address_text'],
            'heading_city' => $ev['heading_city'],
            'ocr_locality' => $ev['ocr_locality'],
            'evidence_sources' => implode('|', $ev['evidence_sources']),
            'bucket' => 'none',
            'has_any_place_text' => (bool) ($ev['has_any_place_text'] ?? false),
            'staging_class' => null,
        ];

        if ($currentCityId !== null) {
            return $this->withAeClass($base + [
                'resolved_city' => null,
                'resolved_city_id' => null,
                'parser_stage' => 'master_already_has_city',
                'failure_reason' => 'city_id already set',
                'required_fix' => 'none',
                'decision' => self::DECISION_SKIP_HAS_CITY,
                'confidence' => 1.0,
                'proposed_city' => null,
                'proposed_city_id' => null,
            ], $ev, $master);
        }

        // Try candidates in priority order (deterministic, no guessing).
        // CRITICAL: never replace a concrete OCR city label with a different
        // sibling/heading city just because it is missing from the cities table
        // (e.g. "ABU ROAD" must not become "Jaipur").
        // Localities (VASTRAPUR / *NAGAR / place_suffix-only) MAY take a parent
        // city from heading/sibling/address when that parent uniquely maps.
        $resolver = new OcrCityResolverService;
        $stagingPrimary = $this->blank($ev['raw_ocr_city'] ?? null)
            ?? $this->blank($master->ocr_city_text ?? null);
        $stagingClass = $this->classifyStagingPlace($stagingPrimary, $cityIndex, $aliases, $resolver);
        $base['staging_class'] = $stagingClass;
        $stagingIsLocality = $stagingClass === 'locality';
        $stagingIsConcreteCity = $stagingClass === 'concrete';

        foreach ($ev['candidates'] as $candidate) {
            $stage = (string) $candidate['stage'];
            $isContextStage = in_array($stage, [
                'page_heading_forward_fill',
                'nearby_extracted_text_city',
                'section_sibling_forward_fill',
            ], true);

            // Context stages fill missing city or locality parent only.
            if ($isContextStage && $stagingIsConcreteCity) {
                continue;
            }
            if ($isContextStage && $stagingPrimary !== null && ! $stagingIsLocality) {
                continue;
            }

            $hit = $this->tryResolveToCityId($candidate['text'], $cityIndex, $aliases);
            if ($hit['status'] === 'unique') {
                if ($stagingIsConcreteCity
                    && $this->normKey($candidate['text']) !== $this->normKey((string) $stagingPrimary)
                    && ! str_starts_with($stage, 'address_')) {
                    continue;
                }

                $decision = $candidate['decision'];
                if (in_array($hit['via'] ?? '', ['alias_name', 'alias_id'], true)) {
                    $decision = self::DECISION_ALIAS;
                }

                return $this->withAeClass($base + [
                    'resolved_city' => $hit['display'],
                    'resolved_city_id' => $hit['city_id'],
                    'proposed_city' => $hit['display'],
                    'proposed_city_id' => $hit['city_id'],
                    'parser_stage' => $candidate['stage'],
                    'failure_reason' => 'city_lost_at_master_mapping_city_id_null',
                    'required_fix' => 'map_resolved_city_to_city_id',
                    'decision' => $decision,
                    'confidence' => $candidate['confidence'],
                    'bucket' => 'exact',
                ], $ev, $master);
            }
            if ($hit['status'] === 'ambiguous') {
                return $this->withAeClass($base + [
                    'resolved_city' => null,
                    'resolved_city_id' => null,
                    'proposed_city' => null,
                    'proposed_city_id' => null,
                    'parser_stage' => $candidate['stage'],
                    'failure_reason' => 'ambiguous_city_name:'.($hit['detail'] ?? ''),
                    'required_fix' => 'disambiguate_cities_table_or_state',
                    'decision' => self::DECISION_SKIP_AMBIGUOUS,
                    'confidence' => 0.35,
                    'bucket' => 'ambiguous',
                ], $ev, $master);
            }
        }

        // Evidence exists but nothing maps to cities.city_id
        if ($ev['has_any_place_text'] || $stagingPrimary !== null) {
            $stage = $stagingIsConcreteCity
                ? 'lost_at_cities_table_lookup'
                : ($stagingIsLocality
                    ? 'lost_at_locality_without_parent_city'
                    : ($ev['raw_ocr_city'] !== null
                        ? 'lost_at_cities_table_lookup'
                        : ($ev['address_text'] !== null
                            ? 'lost_at_address_city_unresolvable'
                            : 'lost_at_locality_without_parent_city')));

            return $this->withAeClass($base + [
                'resolved_city' => null,
                'resolved_city_id' => null,
                'proposed_city' => null,
                'proposed_city_id' => null,
                'parser_stage' => $stage,
                'failure_reason' => $ev['unresolved_reason'] ?? (
                    $stagingPrimary !== null
                        ? 'ocr_place_text_not_in_cities_master:'.$stagingPrimary
                        : 'ocr_place_text_not_in_cities_master'
                ),
                'required_fix' => $ev['required_fix'] ?? 'seed_cities_table_or_add_reviewed_locality_alias',
                'decision' => $stagingIsLocality
                    ? self::DECISION_SKIP_LOCALITY
                    : self::DECISION_SKIP_CITY_TABLE_GAP,
                'confidence' => 0.2,
                'bucket' => 'locality',
            ], $ev, $master);
        }

        return $this->withAeClass($base + [
            'resolved_city' => null,
            'resolved_city_id' => null,
            'proposed_city' => null,
            'proposed_city_id' => null,
            'parser_stage' => $ev['empty_stage'] ?? 'lost_before_or_at_ocr_persist',
            'failure_reason' => 'no_city_heading_address_or_staging_city_in_stored_ocr',
            'required_fix' => 'inspect_pdf_page_and_reparse_or_manual_city',
            'decision' => self::DECISION_SKIP_NO_OCR,
            'confidence' => 0.0,
            'bucket' => 'none',
        ], $ev, $master);
    }

    /**
     * Exclusive A–E class for OCR-linked missing-city audit.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $ev
     * @return array<string, mixed>
     */
    private function withAeClass(array $row, array $ev, object $master): array
    {
        $row['ae_class'] = $this->assignAeClass($row, $ev, $master);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $ev
     */
    public function assignAeClass(array $row, array $ev, object $master): string
    {
        $decision = (string) ($row['decision'] ?? '');
        if ($this->isRecoverableDecision($decision)) {
            return self::CLASS_A;
        }
        if ($decision === self::DECISION_SKIP_HAS_CITY) {
            return self::CLASS_A;
        }
        if ($decision === self::DECISION_SKIP_AMBIGUOUS) {
            return self::CLASS_B;
        }

        $staging = $this->blank($ev['raw_ocr_city'] ?? null)
            ?? $this->blank($master->ocr_city_text ?? null);
        $stagingClass = (string) ($row['staging_class'] ?? '');

        // Concrete OCR city string present but cities lookup failed → mapping drop.
        if ($decision === self::DECISION_SKIP_CITY_TABLE_GAP && $staging !== null) {
            return self::CLASS_D;
        }

        // Locality / place_suffix stored as city; parent never extracted → parser ignored section city.
        if ($decision === self::DECISION_SKIP_LOCALITY) {
            return self::CLASS_C;
        }

        // Place evidence only in address / extracted_text / heading — not mapped to firm.city.
        if (! empty($ev['has_any_place_text']) && $staging === null) {
            return self::CLASS_C;
        }

        // Normalization rejected (forbidden locality cleared from staging path).
        if ($staging !== null && $stagingClass === 'locality' && $decision === self::DECISION_SKIP_NO_OCR) {
            return self::CLASS_B;
        }

        if ($decision === self::DECISION_SKIP_NO_OCR || empty($ev['has_any_place_text'])) {
            return self::CLASS_E;
        }

        return self::CLASS_C;
    }

    /**
     * @param  array<string, int|null>  $cityIndex
     * @param  array<string, string|int>  $aliases
     * @return array<string, mixed>
     */
    private function gatherPipelineEvidence(object $master, array $cityIndex, array $aliases): array
    {
        $resolver = new OcrCityResolverService;
        $sources = [];
        $candidates = [];

        $firmName = trim((string) ($master->firm_name ?? ''));
        $firm = $this->loadOcrFirm($master);
        $docId = $firm ? (int) $firm->ocr_document_id : (int) ($master->source_ocr_document_id ?? 0);
        $page = $firm ? (int) ($firm->page_number ?? 0) : 0;
        $col = $firm ? (int) ($firm->column_number ?? -1) : -1;
        $docLabel = $docId > 0 ? (string) $docId : '';

        if ($docId > 0) {
            $fn = DB::table('ocr_documents')->where('id', $docId)->value('original_filename');
            if ($fn) {
                $docLabel = $docId.'|'.$fn;
            }
        }

        $rawOcrCity = null;
        $addressText = null;
        $headingCity = null;
        $ocrLocality = null;
        $hasAnyPlace = false;

        if ($firm) {
            $rawOcrCity = $this->blank($firm->city ?? null);
            $sd = is_string($firm->source_data ?? null)
                ? (json_decode((string) $firm->source_data, true) ?: [])
                : (array) ($firm->source_data ?? []);
            $sdCity = $this->blank($sd['parsed']['city'] ?? ($sd['raw']['city'] ?? null));
            if ($rawOcrCity === null && $sdCity !== null) {
                $rawOcrCity = $sdCity;
                $sources[] = 'source_data.city';
            } elseif ($rawOcrCity !== null) {
                $sources[] = 'ocr_parsed_firms.city';
            }

            $addressText = $this->blank($firm->address ?? ($sd['parsed']['address'] ?? ($sd['raw']['address'] ?? null)));
            // Reconstruct address-like lines from source unclassified / ignored if needed.
            if ($addressText === null) {
                $ignored = $sd['ignored_tokens'] ?? ($sd['unclassified_lines'] ?? []);
                if (is_array($ignored) && $ignored !== []) {
                    $addressText = $this->blank(implode(' | ', array_slice(array_map('strval', $ignored), 0, 8)));
                    if ($addressText !== null) {
                        $sources[] = 'source_data.ignored_or_unclassified';
                    }
                }
            } else {
                $sources[] = 'ocr_parsed_firms.address';
            }

            if ($rawOcrCity !== null) {
                $hasAnyPlace = true;
                if ($resolver->isForbiddenLocalityShape($rawOcrCity) || $this->looksLikeLocality($rawOcrCity)) {
                    $ocrLocality = $rawOcrCity;
                    // Still try — maybe it is also a cities.city_name (e.g. Ahmednagar).
                    $candidates[] = [
                        'text' => $rawOcrCity,
                        'stage' => 'staging_city_field',
                        'decision' => self::DECISION_APPLY,
                        'confidence' => 0.92,
                    ];
                } else {
                    $candidates[] = [
                        'text' => $rawOcrCity,
                        'stage' => 'staging_city_field',
                        'decision' => self::DECISION_APPLY,
                        'confidence' => 0.95,
                    ];
                }
            }

            if ($addressText !== null) {
                $hasAnyPlace = true;
                $fromAddr = $resolver->extractCityFromAddressLine($addressText);
                if ($fromAddr !== null) {
                    $candidates[] = [
                        'text' => $fromAddr['canonical_city'],
                        'stage' => 'address_city_before_pin',
                        'decision' => self::DECISION_APPLY_ADDRESS,
                        'confidence' => (float) $fromAddr['city_confidence'],
                    ];
                    $sources[] = 'address_extract:'.$fromAddr['city_match_type'];
                }
                // Also try every comma segment / token against cities index (exact only).
                foreach ($this->splitPlaceCandidates($addressText) as $piece) {
                    $candidates[] = [
                        'text' => $piece,
                        'stage' => 'address_token_exact',
                        'decision' => self::DECISION_APPLY_ADDRESS,
                        'confidence' => 0.88,
                    ];
                }
            }
        }

        $masterOcrText = $this->blank($master->ocr_city_text ?? null);
        if ($masterOcrText !== null) {
            $hasAnyPlace = true;
            $sources[] = 'ca_masters.ocr_city_text';
            $candidates[] = [
                'text' => $masterOcrText,
                'stage' => 'master_ocr_city_text',
                'decision' => self::DECISION_APPLY,
                'confidence' => 0.9,
            ];
            if ($ocrLocality === null && ($resolver->isForbiddenLocalityShape($masterOcrText) || $this->looksLikeLocality($masterOcrText))) {
                $ocrLocality = $masterOcrText;
            }
        }

        // Page extracted_text: headings and nearby cities that exist in cities master.
        if ($docId > 0 && $firmName !== '') {
            $lines = $this->documentLines($docId);
            if ($lines !== []) {
                $sources[] = 'ocr_documents.extracted_text';
                $heading = $this->findPrecedingResolvableHeading($lines, $firmName, $cityIndex, $aliases);
                if ($heading !== null) {
                    $hasAnyPlace = true;
                    $headingCity = $heading;
                    $candidates[] = [
                        'text' => $heading,
                        'stage' => 'page_heading_forward_fill',
                        'decision' => self::DECISION_APPLY_HEADING,
                        'confidence' => 0.9,
                    ];
                }
                // Tokens near firm that exactly match cities table.
                foreach ($this->findNearbyCityTokens($lines, $firmName, $cityIndex) as $tok) {
                    $hasAnyPlace = true;
                    $candidates[] = [
                        'text' => $tok,
                        'stage' => 'nearby_extracted_text_city',
                        'decision' => self::DECISION_APPLY_HEADING,
                        'confidence' => 0.85,
                    ];
                }
            } else {
                $sources[] = 'ocr_documents.extracted_text_empty';
            }
        }

        // Sibling firms on same page+column with a cities-resolvable city (stored OCR only).
        if ($docId > 0 && $page > 0) {
            $sibling = $this->findSectionSiblingCity($docId, $page, $col, $firmName, $cityIndex, $aliases);
            if ($sibling !== null) {
                $hasAnyPlace = true;
                $sources[] = 'sibling_ocr_parsed_firms.section_city';
                if ($headingCity === null) {
                    $headingCity = $sibling;
                }
                $candidates[] = [
                    'text' => $sibling,
                    'stage' => 'section_sibling_forward_fill',
                    'decision' => self::DECISION_APPLY_SECTION,
                    'confidence' => 0.86,
                ];
            }
        }

        // Deduplicate candidates by normalized text, keep first (highest priority).
        $seen = [];
        $unique = [];
        foreach ($candidates as $c) {
            $k = $this->normKey($c['text']);
            if ($k === '' || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $unique[] = $c;
        }

        $unresolvedReason = null;
        $requiredFix = null;
        $emptyStage = null;
        if (! $firm) {
            $emptyStage = 'lost_at_ocr_firm_link';
            $unresolvedReason = 'no_source_ocr_row_id_or_crm_ca_id_firm';
            $requiredFix = 'relink_master_to_ocr_parsed_firms';
        } elseif ($rawOcrCity === null && $addressText === null && $headingCity === null && $masterOcrText === null) {
            $emptyStage = 'lost_at_parser_or_document_ai';
            $unresolvedReason = 'stored_ocr_has_no_city_heading_or_address_city';
            $requiredFix = 'reparse_from_structured_data_or_manual';
        } elseif ($rawOcrCity !== null && $this->tryResolveToCityId($rawOcrCity, $cityIndex, $aliases)['status'] === 'none') {
            $unresolvedReason = 'staging_city_not_in_cities_table:'.$rawOcrCity;
            $requiredFix = 'add_city_to_cities_master_or_reviewed_alias';
        }

        return [
            'document_label' => $docLabel,
            'page_number' => $page > 0 ? $page : '',
            'raw_ocr_city' => $rawOcrCity,
            'address_text' => $addressText !== null ? mb_substr($addressText, 0, 240) : null,
            'heading_city' => $headingCity,
            'ocr_locality' => $ocrLocality,
            'evidence_sources' => $sources,
            'candidates' => $unique,
            'has_any_place_text' => $hasAnyPlace,
            'unresolved_reason' => $unresolvedReason,
            'required_fix' => $requiredFix,
            'empty_stage' => $emptyStage,
        ];
    }

    private function loadOcrFirm(object $master): ?object
    {
        if (! Schema::hasTable('ocr_parsed_firms')) {
            return null;
        }
        $rowId = isset($master->source_ocr_row_id) && $master->source_ocr_row_id !== null
            ? (int) $master->source_ocr_row_id
            : null;
        if ($rowId) {
            $firm = DB::table('ocr_parsed_firms')->where('id', $rowId)->first();
            if ($firm) {
                return $firm;
            }
        }
        if (Schema::hasColumn('ocr_parsed_firms', 'crm_ca_id')) {
            return DB::table('ocr_parsed_firms')
                ->where('crm_ca_id', (int) $master->ca_id)
                ->orderByDesc('id')
                ->first();
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function documentLines(int $documentId): array
    {
        if (isset($this->extractedLinesCache[$documentId])) {
            return $this->extractedLinesCache[$documentId];
        }
        $text = (string) (DB::table('ocr_documents')->where('id', $documentId)->value('extracted_text') ?? '');
        if ($text === '') {
            return $this->extractedLinesCache[$documentId] = [];
        }
        $lines = preg_split('/\R+/u', $text) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $clean[] = $line;
            }
        }

        return $this->extractedLinesCache[$documentId] = $clean;
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, int|null>  $cityIndex
     * @param  array<string, string|int>  $aliases
     */
    private function findPrecedingResolvableHeading(array $lines, string $firmName, array $cityIndex, array $aliases): ?string
    {
        $fu = mb_strtoupper(trim($firmName));
        $idx = null;
        foreach ($lines as $i => $line) {
            $u = mb_strtoupper(trim($line));
            if ($fu !== '' && ($u === $fu || str_contains($u, $fu))) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            return null;
        }
        $heading = null;
        $start = max(0, $idx - 80);
        for ($i = $start; $i < $idx; $i++) {
            $line = trim($lines[$i]);
            if ($line === '' || preg_match('/\d/', $line)) {
                continue;
            }
            if (preg_match('/(?:ASSOCIATES|COMPANY|\bCO\b|LLP|&)/iu', $line)) {
                continue;
            }
            $hit = $this->tryResolveToCityId($line, $cityIndex, $aliases);
            if ($hit['status'] === 'unique') {
                $heading = $hit['display'];
            }
        }

        return $heading;
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, int|null>  $cityIndex
     * @return list<string>
     */
    private function findNearbyCityTokens(array $lines, string $firmName, array $cityIndex): array
    {
        $fu = mb_strtoupper(trim($firmName));
        $idx = null;
        foreach ($lines as $i => $line) {
            $u = mb_strtoupper(trim($line));
            if ($fu !== '' && ($u === $fu || str_contains($u, $fu))) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            return [];
        }
        $out = [];
        $slice = array_slice($lines, max(0, $idx - 5), 15);
        foreach ($slice as $line) {
            foreach ($this->splitPlaceCandidates($line) as $piece) {
                $k = $this->normKey($piece);
                if ($k !== '' && array_key_exists($k, $cityIndex) && $cityIndex[$k] !== null) {
                    $out[] = $piece;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string, int|null>  $cityIndex
     * @param  array<string, string|int>  $aliases
     */
    private function findSectionSiblingCity(
        int $docId,
        int $page,
        int $col,
        string $firmName,
        array $cityIndex,
        array $aliases,
    ): ?string {
        $key = $docId.'|'.$page.'|'.$col;
        if (! isset($this->docFirmCityCache[$key])) {
            $q = DB::table('ocr_parsed_firms')
                ->where('ocr_document_id', $docId)
                ->where('page_number', $page)
                ->whereNotNull('city')
                ->where('city', '!=', '')
                ->orderBy('id')
                ->select(['firm_name', 'city', 'column_number']);
            $rows = [];
            foreach ($q->cursor() as $r) {
                $c = (int) ($r->column_number ?? -1);
                if ($col >= 0 && $c >= 0 && $c !== $col) {
                    continue;
                }
                $rows[] = [
                    'firm' => (string) $r->firm_name,
                    'city' => (string) $r->city,
                    'page' => $page,
                    'col' => $c,
                ];
            }
            $this->docFirmCityCache[$key] = $rows;
        }

        $last = null;
        $fu = mb_strtoupper(trim($firmName));
        foreach ($this->docFirmCityCache[$key] as $row) {
            if (mb_strtoupper(trim($row['firm'])) === $fu) {
                break;
            }
            $hit = $this->tryResolveToCityId($row['city'], $cityIndex, $aliases);
            if ($hit['status'] === 'unique') {
                $last = $hit['display'];
            }
        }

        return $last;
    }

    /**
     * @return list<string>
     */
    private function splitPlaceCandidates(string $text): array
    {
        $parts = preg_split('/[,|;\/]+/u', $text) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            // Strip trailing PIN
            $p = preg_replace('/\s*[-–]?\s*\d{5,6}[A-Z]?\s*$/u', '', $p) ?? $p;
            $p = trim($p);
            if ($p !== '' && mb_strlen($p) >= 3 && ! preg_match('/\d/', $p)) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, int|null>  $cityIndex
     * @param  array<string, string|int>  $aliases
     * @return array{status: string, city_id?: int, display?: string, detail?: string, via?: string}
     */
    public function tryResolveToCityId(string $text, array $cityIndex, array $aliases): array
    {
        $exact = $this->lookupExactCity($text, $cityIndex);
        if ($exact['status'] === 'unique' || $exact['status'] === 'ambiguous') {
            return $exact + ['via' => 'exact'];
        }

        $aliasKey = $this->normKey($text);
        if (! isset($aliases[$aliasKey])) {
            return ['status' => 'none'];
        }
        $target = $aliases[$aliasKey];
        if (is_int($target) || (is_string($target) && ctype_digit($target))) {
            $id = (int) $target;
            if ($this->validCityId($id) !== null) {
                return [
                    'status' => 'unique',
                    'city_id' => $id,
                    'display' => $this->cityIdToName[$id] ?? (string) $id,
                    'via' => 'alias_id',
                ];
            }

            return ['status' => 'none'];
        }

        $mapped = $this->lookupExactCity((string) $target, $cityIndex);
        if ($mapped['status'] === 'unique') {
            $mapped['via'] = 'alias_name';
        }

        return $mapped;
    }

    /**
     * @param  array<string, int|null>  $cityIndex
     * @return array{status: string, city_id?: int, display?: string, detail?: string}
     */
    public function lookupExactCity(string $name, array $cityIndex): array
    {
        $key = $this->normKey($name);
        if ($key === '' || ! array_key_exists($key, $cityIndex)) {
            return ['status' => 'none'];
        }
        $id = $cityIndex[$key];
        if ($id === null) {
            return ['status' => 'ambiguous', 'detail' => 'multiple_city_ids_same_normalized_name'];
        }

        return [
            'status' => 'unique',
            'city_id' => $id,
            'display' => $this->cityIdToName[$id] ?? $name,
        ];
    }

    /**
     * @return array<string, int|null>
     */
    public function buildCityNameIndex(): array
    {
        if (! Schema::hasTable('cities')) {
            return [];
        }
        $index = [];
        $this->cityIdToName = [];
        DB::table('cities')->orderBy('city_id')->select(['city_id', 'city_name'])->chunkById(1000, function ($rows) use (&$index) {
            foreach ($rows as $row) {
                $id = (int) $row->city_id;
                $name = (string) $row->city_name;
                $this->cityIdToName[$id] = $name;
                $key = $this->normKey($name);
                if ($key === '') {
                    continue;
                }
                if (! array_key_exists($key, $index)) {
                    $index[$key] = $id;
                } elseif ($index[$key] !== $id) {
                    $index[$key] = null;
                }
            }
        }, 'city_id');

        return $index;
    }

    /**
     * @return array<string, string|int>
     */
    public function localityAliases(): array
    {
        $raw = config('ocr_locality_aliases.aliases', []);
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            $key = $this->normKey((string) $k);
            if ($key !== '') {
                $out[$key] = $v;
            }
        }

        return $out;
    }

    public function normKey(string $text): string
    {
        $text = trim($text);
        $text = str_replace('&', ' AND ', $text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_strtolower(trim($text));
    }

    /**
     * Classify OCR staging place text for safe recovery.
     *
     * @param  array<string, int|null>  $cityIndex
     * @param  array<string, string|int>  $aliases
     * @return 'none'|'concrete'|'locality'
     */
    private function classifyStagingPlace(
        ?string $staging,
        array $cityIndex,
        array $aliases,
        OcrCityResolverService $resolver,
    ): string {
        if ($staging === null || $staging === '') {
            return 'none';
        }
        // Already a cities-table name — treated as concrete (direct map preferred).
        if ($this->tryResolveToCityId($staging, $cityIndex, $aliases)['status'] === 'unique') {
            return 'concrete';
        }
        if ($this->looksLikeLocality($staging) || $resolver->isForbiddenLocalityShape($staging)) {
            return 'locality';
        }
        $hit = $resolver->resolve($staging);
        $type = is_array($hit) ? (string) ($hit['city_match_type'] ?? '') : '';
        // Weak OCR place-suffix tokens are localities unless they map to cities.
        if ($type === 'place_suffix' || $type === '') {
            return 'locality';
        }
        // Strong OCR city evidence not yet in cities table (ABU ROAD, directory city).
        if (in_array($type, ['approved_road_city', 'city_master', 'directory_list', 'alias', 'alias_joined'], true)) {
            return 'concrete';
        }

        return 'locality';
    }

    private function looksLikeLocality(string $text): bool
    {
        $t = trim($text);
        if ($t === '') {
            return false;
        }

        return (bool) preg_match('/\b(?:nagar|colony|vihar|enclave|mohalla|chowk|park)\b/iu', $t)
            || (bool) preg_match('/(?:nagar|colony|vihar)$/iu', preg_replace('/\s+/u', '', mb_strtolower($t)) ?? '');
    }

    private function validCityId(mixed $id): ?int
    {
        if ($id === null || $id === '' || (int) $id <= 0) {
            return null;
        }
        $id = (int) $id;
        if ($this->cityIdToName !== []) {
            return isset($this->cityIdToName[$id]) ? $id : null;
        }
        if (! Schema::hasTable('cities')) {
            return $id;
        }

        return DB::table('cities')->where('city_id', $id)->exists() ? $id : null;
    }

    private function blank(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = trim((string) $v);

        return $t === '' ? null : $t;
    }
}
