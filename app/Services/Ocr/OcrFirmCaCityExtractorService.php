<?php

namespace App\Services\Ocr;

use App\Support\DocumentAi\LayoutGeometryHelper;

/**
 * Production OCR extractor — Firm Name, CA Name, City only.
 * Ignores address, partners, identifiers, and all other OCR tokens for structured output.
 */
class OcrFirmCaCityExtractorService
{
    public function __construct(
        private readonly ?OcrEntityClassificationService $classifier = null,
        private readonly ?OcrRecordSegmentationService $segmentation = null,
    ) {}

    public function isEnabled(): bool
    {
        return $this->workflowMode() === 'firm_ca_city';
    }

    private function workflowMode(): string
    {
        try {
            if (function_exists('app') && app()->bound('config')) {
                return (string) config('ocr_workflow.mode', 'firm_ca_city');
            }
        } catch (\Throwable) {
            // Pure PHPUnit without Laravel container.
        }

        return (string) (getenv('OCR_WORKFLOW_MODE') ?: 'firm_ca_city');
    }

    /**
     * @param  list<array<string, mixed>>  $tokens
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function extract(array $tokens, array $context = []): ?array
    {
        if ($tokens === []) {
            return null;
        }

        $tokens = $this->expandMultilineTokens($tokens);
        $entities = $this->classifier ?? new OcrEntityClassificationService;
        $sectionCity = $this->normalizeSectionCity($context['section_city'] ?? null, $entities);
        $firmName = null;
        $firmToken = null;
        $caName = null;
        $caToken = null;
        $city = $sectionCity;
        $cityToken = null;
        $cityFromSection = $sectionCity !== null;
        $fieldMeta = [];
        $classifications = [];
        $ignored = [];
        $rowMergeEvidence = is_array($context['row_merge_evidence'] ?? null) ? $context['row_merge_evidence'] : [];
        $flags = [
            // Only accept merge when scoped evidence exists — never from dense packing alone.
            'row_merge_suspected' => false,
            'row_split_suspected' => (bool) ($context['row_split_suspected'] ?? false),
            'firm_name_boundary_uncertain' => (bool) ($context['firm_name_boundary_uncertain'] ?? false),
            'ca_name_boundary_uncertain' => (bool) ($context['ca_name_boundary_uncertain'] ?? false),
            'city_boundary_uncertain' => (bool) ($context['city_boundary_uncertain'] ?? false),
        ];

        $rawFirmName = null;
        $rawCaName = null;
        $rawCity = $sectionCity;
        $caClassificationReason = null;
        $firmCandidateCount = 0;

        foreach ($tokens as $idx => $token) {
            $rawText = (string) ($token['text'] ?? '');
            $text = trim($rawText);
            if ($text === '') {
                continue;
            }
            $classified = $entities->classify($text);
            $classificationValue = (string) ($classified['classification_value'] ?? $text);
            $classified['line_index'] = $idx;
            $classified['assigned_field'] = null;
            $classified['reason'] = $classified['unicode_reason'] ?? null;

            // 1) Firm name — content markers only, never line position.
            $firmCandidateRaw = $this->stripInlineCode($classificationValue);
            if ($entities->isFirmName($firmCandidateRaw) && $this->isValidFirmName($firmCandidateRaw, $entities)) {
                $firmCandidateCount++;
                if ($firmName === null) {
                    $firmName = $this->stripFirmDecorations($firmCandidateRaw);
                    $rawFirmName = $this->stripFirmDecorations($this->stripInlineCode($text));
                    $firmToken = $token;
                    $classified['entity_type'] = OcrEntityClassificationService::FIRM_NAME;
                    $classified['crm_field'] = 'firm_name';
                    $classified['assigned_field'] = 'firm_name';
                    $classified['reason'] = ! empty($classified['confusable_replaced'])
                        ? 'unicode_confusable_normalized'
                        : 'firm_name_pattern';
                    $classifications[] = $classified;
                    continue;
                }
                // Second firm token in the same visual record → scoped merge evidence.
                $rowMergeEvidence[] = [
                    'affected_field' => 'firm_name',
                    'token' => $firmCandidateRaw,
                    'source_row' => null,
                    'assigned_row' => $context['sequence_no'] ?? null,
                    'bounding_box' => [
                        'x_min' => $token['x_min'] ?? null,
                        'x_max' => $token['x_max'] ?? null,
                        'y_min' => $token['y_min'] ?? null,
                        'y_max' => $token['y_max'] ?? null,
                    ],
                    'reason' => 'second_firm_name_token_in_same_record',
                ];
                $classified['reason'] = 'scoped_row_merge_extra_firm';
                $ignored[] = $text;
                $classifications[] = $classified;
                continue;
            }

            // 2) City — section heading or approved city-shaped token (not address).
            $cityCandidate = $this->normalizeCityCandidate($classificationValue, $entities);
            if ($cityCandidate !== null && ! $cityFromSection) {
                if ($city === null && ! $entities->isFirmName($classificationValue) && ! $entities->isPerson($classificationValue)) {
                    $city = $cityCandidate;
                    $rawCity = $text;
                    $cityToken = $token;
                    $classified['entity_type'] = OcrEntityClassificationService::CITY;
                    $classified['crm_field'] = 'city';
                    $classified['assigned_field'] = 'city';
                    $classified['reason'] = ! empty($classified['confusable_replaced'])
                        ? 'unicode_confusable_normalized'
                        : 'city_or_section_heading';
                    $classifications[] = $classified;
                    continue;
                }
            }

            // 3) CA name — verified person only; never address / locality / firm.
            if ($caName === null && $entities->isPerson($classificationValue) && ! $entities->isAddress($classificationValue) && ! $entities->isFirmName($classificationValue)) {
                $person = $entities->stripPersonDecorations((string) ($classified['normalized'] ?? $classificationValue));
                if ($this->isValidCaName($person, $firmName, $entities)) {
                    $caName = $person;
                    $rawCaName = $text;
                    $caToken = $token;
                    $caClassificationReason = ! empty($classified['confusable_replaced'])
                        ? 'unicode_confusable_normalized'
                        : 'verified_person_name';
                    $classified['entity_type'] = OcrEntityClassificationService::PERSON;
                    $classified['crm_field'] = 'ca_name';
                    $classified['assigned_field'] = 'ca_name';
                    $classified['reason'] = $caClassificationReason;
                    $classifications[] = $classified;
                    continue;
                }
            }

            // 3b) Proprietary single given-name under matching firm (HARSHIL under HARSHIL & ASSOCIATES).
            if ($caName === null && $firmName !== null && $this->isFirmLinkedGivenName($classificationValue, $firmName, $entities)) {
                $person = $entities->stripPersonDecorations($classificationValue);
                $caName = $person;
                $rawCaName = $text;
                $caToken = $token;
                $caClassificationReason = 'firm_linked_given_name';
                $classified['entity_type'] = OcrEntityClassificationService::PERSON;
                $classified['crm_field'] = 'ca_name';
                $classified['assigned_field'] = 'ca_name';
                $classified['reason'] = $caClassificationReason;
                $classifications[] = $classified;
                continue;
            }

            $classified['reason'] = 'ignored_outside_firm_ca_city_scope';
            $ignored[] = $text;
            $classifications[] = $classified;
        }

        // Peel person line that Document AI glued into the firm paragraph.
        [$firmName, $rawFirmName, $caName, $rawCaName, $caClassificationReason] = $this->peelEmbeddedCaFromFirm(
            $firmName,
            $rawFirmName,
            $caName,
            $rawCaName,
            $caClassificationReason,
            $entities,
        );

        // Peel person characters that appear inside the firm title token itself
        // (proprietorship "LOVISH GARG AND ASSOCIATES") — only multi-word, never single-token invent.
        // Does not invent CA when the firm title has no person-shaped prefix.
        if ($caName === null && $firmName !== null) {
            $derived = $this->deriveCaFromFirmName($firmName, $entities);
            if ($derived !== null) {
                $caName = $derived;
                $rawCaName = $derived;
                $caClassificationReason = 'peeled_person_prefix_from_firm_token';
            }
        } elseif ($caName !== null && $firmName !== null) {
            $derived = $this->deriveCaFromFirmName($firmName, $entities);
            if ($derived !== null && $this->shouldPreferDerivedCa($caName, $derived)) {
                $caName = $derived;
                // Keep original OCR raw when expanding a given-name token using firm evidence.
                $caClassificationReason = 'expanded_from_firm_with_person_token';
            }
        }

        // Section city always wins when present (directory column heading).
        if ($sectionCity !== null) {
            $city = $sectionCity;
            $rawCity = $sectionCity;
            $cityFromSection = true;
        }

        // Drop bogus cities that are really person/firm fragments (e.g. ANMOL).
        if ($city !== null && ! $cityFromSection && $firmName !== null) {
            if (! $entities->isCity($city) || $this->cityLooksLikeFirmFragment($city, $firmName, $caName)) {
                $city = null;
                $rawCity = null;
                $cityToken = null;
            }
        }

        if ($firmName === null && $caName === null && $city === null) {
            return null;
        }

        $missing = [];
        if ($firmName === null || trim($firmName) === '') {
            $missing[] = 'firm_name';
        }
        if ($caName === null || trim($caName) === '') {
            $missing[] = 'ca_name';
        }
        if ($city === null || trim($city) === '') {
            $missing[] = 'city';
        }
        if (in_array('firm_name', $missing, true)) {
            $flags['firm_name_boundary_uncertain'] = true;
        }
        if (in_array('ca_name', $missing, true)) {
            $flags['ca_name_boundary_uncertain'] = true;
        }
        if (in_array('city', $missing, true)) {
            $flags['city_boundary_uncertain'] = true;
        }

        if ($rowMergeEvidence !== [] || $firmCandidateCount > 1) {
            $flags['row_merge_suspected'] = true;
            if ($rowMergeEvidence === [] && $firmCandidateCount > 1) {
                $rowMergeEvidence[] = [
                    'affected_field' => 'firm_name',
                    'token' => $firmName,
                    'source_row' => null,
                    'assigned_row' => $context['sequence_no'] ?? null,
                    'bounding_box' => null,
                    'reason' => 'multiple_firm_name_candidates_in_record',
                ];
            }
        }

        $fieldMeta['firm_name'] = $firmName !== null
            ? $this->meta($firmName, $firmToken ?? $tokens[0], 0.92, 'firm_name', 'firm_name_pattern', $rawFirmName ?? $firmName)
            : null;
        $fieldMeta['ca_name'] = $caName !== null
            ? $this->meta(
                $caName,
                $caToken ?? $tokens[0],
                $caClassificationReason === 'unicode_confusable_normalized' ? 0.84 : 0.86,
                'ca_name',
                $caClassificationReason ?? 'verified_person_name',
                $rawCaName ?? $caName,
            )
            : null;
        $fieldMeta['city'] = $city !== null
            ? $this->meta($city, $cityToken ?? $tokens[0], $cityFromSection ? 0.9 : 0.8, 'city', $cityFromSection ? 'section_heading' : 'city_token', $rawCity ?? $city)
            : null;
        $fieldMeta = array_filter($fieldMeta);

        $thresholds = [
            'firm_name' => (float) config('ocr_workflow.min_firm_name_confidence', config('ocr_workflow.min_field_confidence', 0.55)),
            'ca_name' => (float) config('ocr_workflow.min_ca_name_confidence', config('ocr_workflow.min_field_confidence', 0.55)),
            'city' => (float) config('ocr_workflow.min_city_confidence', config('ocr_workflow.min_field_confidence', 0.55)),
        ];
        $lowFields = [];
        foreach ($fieldMeta as $field => $meta) {
            $minConf = $thresholds[$field] ?? (float) config('ocr_workflow.min_field_confidence', 0.55);
            if (($meta['confidence'] ?? 1) < $minConf) {
                $lowFields[] = $field;
            }
        }

        $scopedBoundaryIssue = $flags['row_merge_suspected']
            || $flags['row_split_suspected']
            || $flags['firm_name_boundary_uncertain']
            || $flags['ca_name_boundary_uncertain']
            || $flags['city_boundary_uncertain'];
        $structural = $scopedBoundaryIssue ? 0.55 : 0.96;
        $parserConf = $this->avgConfidence($fieldMeta);

        return [
            'sequence_no' => $context['sequence_no'] ?? 1,
            'row_number' => $context['sequence_no'] ?? 1,
            'firm_name' => $firmName,
            'ca_name' => $caName,
            'city' => $city,
            'ca_role' => null,
            'firm_type' => null,
            'frn' => null,
            'gst_no' => null,
            'pan_no' => null,
            'address' => null,
            'state' => null,
            'pincode' => null,
            'phone' => null,
            'email' => null,
            'membership_no' => null,
            'website' => null,
            'members' => [],
            'review_status' => 'pending',
            'overall_confidence' => $parserConf,
            'structural_confidence' => $structural,
            'parser_confidence' => $parserConf,
            'firm_name_ocr_confidence' => $fieldMeta['firm_name']['ocr_confidence'] ?? null,
            'ca_name_ocr_confidence' => $fieldMeta['ca_name']['ocr_confidence'] ?? null,
            'city_ocr_confidence' => $fieldMeta['city']['ocr_confidence'] ?? null,
            'page_number' => $context['page'] ?? ($tokens[0]['page'] ?? null),
            'column_number' => $context['column'] ?? ($tokens[0]['column'] ?? null),
            'field_meta' => $fieldMeta,
            'field_confidences' => array_map(static fn (array $m) => $m['confidence'] ?? null, $fieldMeta),
            'low_confidence_fields' => $lowFields,
            'missing_required_fields' => $missing,
            'entity_classifications' => $classifications,
            'ignored_tokens' => $ignored,
            'unknown_tokens' => [],
            'extraction_source' => $context['extraction_source'] ?? 'firm_ca_city',
            'bounding_boxes' => LayoutGeometryHelper::mergeBboxes($tokens),
            'row_merge_suspected' => $flags['row_merge_suspected'],
            'row_merge_evidence' => $rowMergeEvidence,
            'row_split_suspected' => $flags['row_split_suspected'],
            'firm_name_boundary_uncertain' => $flags['firm_name_boundary_uncertain'],
            'ca_name_boundary_uncertain' => $flags['ca_name_boundary_uncertain'],
            'city_boundary_uncertain' => $flags['city_boundary_uncertain'],
            'ambiguous_layout' => $scopedBoundaryIssue,
            'cross_column_contamination' => false,
            'reconstructed_text' => implode("\n", array_map(static fn (array $t) => $t['text'] ?? '', $tokens)),
            'source_lines' => array_map(static fn (array $t) => [
                'text' => $t['text'] ?? '',
                'page' => $t['page'] ?? null,
                'column' => $t['column'] ?? null,
                'x_center' => $t['x_center'] ?? null,
                'y_center' => $t['y_center'] ?? null,
            ], $tokens),
            'raw_firm_name' => $rawFirmName ?? $firmName,
            'raw_ca_name' => $rawCaName ?? $caName,
            'raw_city' => $rawCity ?? $city,
            'classification_reason' => $caClassificationReason,
        ];
    }

    /**
     * Document AI sometimes returns firm + person as one paragraph.
     *
     * @param  list<array<string, mixed>>  $tokens
     * @return list<array<string, mixed>>
     */
    private function expandMultilineTokens(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $token) {
            $text = (string) ($token['text'] ?? '');
            $parts = preg_split("/\R+/u", $text) ?: [$text];
            $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $p) => $p !== ''));
            if (count($parts) <= 1) {
                $out[] = $token;
                continue;
            }
            $yMin = isset($token['y_min']) ? (float) $token['y_min'] : 0.0;
            $yMax = isset($token['y_max']) ? (float) $token['y_max'] : ($yMin + 0.02);
            $span = max(0.004, ($yMax - $yMin) / count($parts));
            foreach ($parts as $i => $part) {
                $clone = $token;
                $clone['text'] = $part;
                $clone['y_min'] = $yMin + ($i * $span);
                $clone['y_max'] = $yMin + (($i + 1) * $span);
                $clone['y_center'] = ($clone['y_min'] + $clone['y_max']) / 2;
                $out[] = $clone;
            }
        }

        return $out;
    }

    /**
     * @return array{0:?string,1:?string,2:?string,3:?string,4:?string}
     */
    private function peelEmbeddedCaFromFirm(
        ?string $firmName,
        ?string $rawFirmName,
        ?string $caName,
        ?string $rawCaName,
        ?string $caReason,
        OcrEntityClassificationService $entities,
    ): array {
        if ($firmName === null) {
            return [$firmName, $rawFirmName, $caName, $rawCaName, $caReason];
        }

        $lines = preg_split("/\R+/u", $firmName) ?: [$firmName];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn (string $l) => $l !== ''));
        if (count($lines) > 1) {
            $firmLine = null;
            $personLine = null;
            foreach ($lines as $line) {
                if ($firmLine === null && $entities->isFirmName($line) && $this->isValidFirmName($line, $entities)) {
                    $firmLine = $line;
                    continue;
                }
                $personCandidate = $entities->stripPersonDecorations($line);
                if ($personLine === null && $firmLine !== null) {
                    if ($this->isValidCaName($personCandidate, $firmLine, $entities)
                        || $this->isFirmLinkedGivenName($personCandidate, $firmLine, $entities)) {
                        $personLine = $personCandidate;
                    }
                }
            }
            if ($firmLine !== null) {
                $firmName = $firmLine;
                $rawFirmName = $firmLine;
            }
            if ($caName === null && $personLine !== null) {
                $caName = $personLine;
                $rawCaName = $personLine;
                $caReason = 'peeled_from_multiline_firm_token';
            }
        }

        // Single-line glue: "NEETU BHATIA & ASSOCIATES NEETU BHATIA"
        if ($caName === null && preg_match(
            '/^(.+?\b(?:associates|llp|&\s*co\.?|and\s+co\.?|and\s+associates|company|chartered\s+accountants))\s+([A-Z][A-Z.\s]{2,60})$/iu',
            $firmName,
            $m,
        )) {
            $maybeFirm = trim($m[1]);
            $maybePerson = $entities->stripPersonDecorations(trim($m[2]));
            if ($this->isValidFirmName($maybeFirm, $entities) && $this->isValidCaName($maybePerson, $maybeFirm, $entities)) {
                $firmName = $maybeFirm;
                $rawFirmName = $maybeFirm;
                $caName = $maybePerson;
                $rawCaName = $maybePerson;
                $caReason = 'peeled_trailing_person_from_firm_token';
            }
        }

        return [$firmName, $rawFirmName, $caName, $rawCaName, $caReason];
    }

    /**
     * Derive CA from proprietorship firm titles: "NAME [&|AND] ASSOCIATES/CO".
     */
    private function deriveCaFromFirmName(string $firmName, OcrEntityClassificationService $entities): ?string
    {
        $base = trim((string) preg_replace(
            '/\s+(?:&\s*|AND\s+)?(?:ASSOCIATES|CO\.?|COMPANY|LLP|CHARTERED\s+ACCOUNTANTS)\s*$/iu',
            '',
            $firmName,
        ));
        $base = trim((string) preg_replace('/\s*(?:&|AND)\s*$/iu', '', $base));
        $base = trim(preg_replace('/\s+/', ' ', $base) ?? '');
        if ($base === '' || mb_strtolower($base) === mb_strtolower($firmName)) {
            return null;
        }
        $words = preg_split('/\s+/', $base) ?: [];
        // Multi-word proprietor names only (LOVISH GARG). Never invent CA from "SHAH & ASSOCIATES" → SHAH alone.
        if (count($words) < 2 || count($words) > 4) {
            return null;
        }
        if ($this->isValidCaName($base, $firmName, $entities)) {
            return $base;
        }
        if (! $entities->isAddress($base) && ! $entities->isFirmName($base)
            && ! $entities->isCity($base) && ! preg_match('/\d/', $base)
            && preg_match('/^[A-Za-z .\'\-]+$/', $base)) {
            return $entities->stripPersonDecorations($base);
        }

        return null;
    }

    /** Prefer "ANMOL ARJUN" from firm over a lone given-name token "ANMOL". */
    private function shouldPreferDerivedCa(string $current, string $derived): bool
    {
        $curWords = preg_split('/\s+/', trim($current)) ?: [];
        $derWords = preg_split('/\s+/', trim($derived)) ?: [];
        if (count($derWords) <= count($curWords)) {
            return false;
        }

        return mb_strtolower($curWords[0] ?? '') === mb_strtolower($derWords[0] ?? '');
    }

    private function cityLooksLikeFirmFragment(string $city, string $firmName, ?string $caName): bool
    {
        $c = mb_strtolower(trim($city));
        $f = mb_strtolower(trim($firmName));
        if ($c === '' || $f === '') {
            return false;
        }
        if ($caName !== null && $c === mb_strtolower(trim($caName))) {
            return true;
        }
        $first = mb_strtolower((preg_split('/\s+/', $firmName) ?: [''])[0] ?? '');

        return $c === $first || str_starts_with($f, $c.' ');
    }

    private function isValidFirmName(string $name, OcrEntityClassificationService $entities): bool
    {
        if (trim($name) === '' || mb_strlen($name) < 3) {
            return false;
        }
        if ($entities->isCareOfLine($name)) {
            return false;
        }
        if (preg_match('/^chartered\s+accountants?\.?$/iu', trim($name))) {
            return false;
        }
        if ($entities->isAddress($name) && ! $entities->isFirmName($name)) {
            return false;
        }
        if (preg_match('/\b[6-9]\d{9}\b/', $name) || preg_match('/\b[1-9]\d{5}\b/', $name)) {
            return false;
        }

        return $entities->isFirmName($name);
    }

    private function stripFirmDecorations(string $name): string
    {
        $name = trim((string) preg_replace('/^PROP(?:RIETOR)?\.?\s+/iu', '', $name));

        return trim($name);
    }

    private function isValidCaName(string $name, ?string $firmName, OcrEntityClassificationService $entities): bool
    {
        $name = trim($name);
        if ($name === '' || ! $entities->isPerson($name) || $entities->isAddress($name)) {
            return false;
        }
        if ($entities->isCity($name) || $entities->isFirmName($name)) {
            return false;
        }
        if (preg_match('/\d/', $name)) {
            return false;
        }
        if ($firmName !== null && mb_strtolower($name) === mb_strtolower($firmName)) {
            return false;
        }
        // Reject locality leaks: ANAJ MANDI, URBAN ESTATE HUDA, etc.
        if (preg_match('/\b(?:mandi|nagar|nagri|estate|huda|sadak|road|street|colony|sector|hospital|market|chowk|mohalla|cantt|urban|anaj|chartered|accountants?)\b/iu', $name)) {
            return false;
        }

        return true;
    }

    /**
     * Single given-name token that matches the leading name of the firm (proprietor style).
     */
    private function isFirmLinkedGivenName(string $candidate, string $firmName, OcrEntityClassificationService $entities): bool
    {
        $candidate = trim($candidate);
        if ($candidate === '' || $entities->isAddress($candidate) || $entities->isFirmName($candidate) || $entities->isCity($candidate)) {
            return false;
        }
        if (preg_match('/\d/', $candidate) || preg_match('/\s/', $candidate)) {
            return false;
        }
        if (mb_strlen($candidate) < 3 || mb_strlen($candidate) > 24) {
            return false;
        }
        if (! preg_match('/^[A-Za-z.\'\-]+$/', $candidate)) {
            return false;
        }
        if (preg_match('/\b(?:mandi|nagar|estate|huda|sadak|road|street|colony|sector|hospital|market|chowk|mohalla|cantt|urban|anaj|associates|company|llp)\b/iu', $candidate)) {
            return false;
        }
        $firmFirst = preg_split('/\s+/', trim($firmName)) ?: [];
        $first = $firmFirst[0] ?? '';
        if ($first === '' || mb_strtolower($first) !== mb_strtolower($candidate)) {
            return false;
        }
        if (mb_strtolower($candidate) === mb_strtolower($firmName)) {
            return false;
        }

        return true;
    }

    /**
     * Layout section headings (ABOHAR) — trust isCity; never person/firm fragments.
     */
    private function normalizeSectionCity(mixed $text, OcrEntityClassificationService $entities): ?string
    {
        $raw = trim((string) $text);
        if ($raw === '') {
            return null;
        }
        if ($entities->isFirmName($raw) || $entities->isAddress($raw) || $entities->isPerson($raw)) {
            return null;
        }
        if ($entities->isCity($raw)) {
            return $raw;
        }
        // Segmenter already gated directory headings; keep ROAD/CITY/CANTT style headers.
        $words = preg_split('/\s+/', $raw) ?: [];
        if (count($words) <= 2 && preg_match('/^[A-Z][A-Z\s\-]{1,30}$/', $raw)
            && (count($words) === 2 || preg_match('/\b(?:ROAD|CITY|CANTT)$/i', $raw))) {
            return $raw;
        }

        return null;
    }

    /** Body tokens only — never ALL-CAPS / section-heading guesswork (fixes ANMOL → city). */
    private function normalizeCityCandidate(mixed $text, OcrEntityClassificationService $entities): ?string
    {
        $raw = trim((string) $text);
        if ($raw === '') {
            return null;
        }
        if ($entities->isFirmName($raw) || $entities->isAddress($raw) || $entities->isPerson($raw)) {
            return null;
        }
        if ($entities->isCity($raw)) {
            return $raw;
        }

        return null;
    }

    private function stripInlineCode(string $text): string
    {
        if (preg_match('/^(.+?)\s*[-–]\s*\d{5,6}[A-Z]?\s*$/i', $text, $m)) {
            return trim($m[1]);
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(string $value, array $token, float $parserConf, string $field, string $evidence, ?string $rawValue = null): array
    {
        $ocr = isset($token['ocr_confidence']) ? (float) $token['ocr_confidence'] : null;
        $blended = $ocr !== null ? round(($parserConf * 0.6) + ($ocr * 0.4), 4) : $parserConf;

        return [
            'value' => $value,
            'raw_value' => $rawValue ?? $value,
            'classification_value' => $value,
            'parsed_value' => $value,
            'source_text' => $rawValue ?? $value,
            'field' => $field,
            'confidence' => $blended,
            'parser_confidence' => $parserConf,
            'ocr_confidence' => $ocr,
            'evidence' => $evidence,
            'page_number' => $token['page'] ?? null,
            'column_number' => $token['column'] ?? null,
            'bounding_box' => [
                'x_min' => $token['x_min'] ?? null,
                'x_max' => $token['x_max'] ?? null,
                'y_min' => $token['y_min'] ?? null,
                'y_max' => $token['y_max'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $fieldMeta
     */
    private function avgConfidence(array $fieldMeta): float
    {
        $scores = [];
        foreach ($fieldMeta as $meta) {
            if (isset($meta['confidence'])) {
                $scores[] = (float) $meta['confidence'];
            }
        }

        return $scores !== [] ? round(array_sum($scores) / count($scores), 4) : 0.5;
    }
}
