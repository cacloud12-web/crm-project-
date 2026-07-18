<?php

namespace App\Services\Ocr;

use App\Support\DocumentAi\LayoutGeometryHelper;

/**
 * Parses one isolated visual record block into a structured firm staging row.
 * Used by layout and text parsers — single production extraction path.
 */
class OcrDirectoryRecordParser
{
    public function __construct(
        private readonly ?OcrEntityClassificationService $classifier = null,
        private readonly ?OcrIdentifierExtractorService $identifierExtractor = null,
        private readonly ?OcrFirmRoleResolverService $roles = null,
        private readonly ?OcrAddressAssemblerService $addressAssembler = null,
        private readonly ?OcrFirmCaCityExtractorService $threeFieldExtractor = null,
    ) {}

    /**
     * @param  list<array{text: string, page?: int, column?: int, ocr_confidence?: float, x_center?: float, y_center?: float, x_min?: float, x_max?: float, y_min?: float, y_max?: float}>  $tokens
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function parseBlock(array $tokens, array $context = []): ?array
    {
        $three = $this->threeFieldExtractor ?? new OcrFirmCaCityExtractorService($this->classifier);
        if ($three->isEnabled()) {
            return $three->extract($tokens, $context);
        }

        return $this->parseBlockFull($tokens, $context);
    }

    /**
     * Legacy multi-field parse (OCR_WORKFLOW_MODE=full only).
     *
     * @param  list<array{text: string, page?: int, column?: int, ocr_confidence?: float, x_center?: float, y_center?: float, x_min?: float, x_max?: float, y_min?: float, y_max?: float}>  $tokens
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function parseBlockFull(array $tokens, array $context = []): ?array
    {
        if ($tokens === []) {
            return null;
        }

        $entities = $this->entities();
        $ids = $this->ids();
        $sectionCity = $context['section_city'] ?? null;
        $flags = [
            'row_merge_suspected' => (bool) ($context['row_merge_suspected'] ?? false),
            'row_split_suspected' => (bool) ($context['row_split_suspected'] ?? false),
            'ambiguous_layout' => (bool) ($context['ambiguous_record_boundary'] ?? false),
            'cross_column_contamination' => (bool) ($context['cross_column_contamination'] ?? false),
            'orphan_token' => (bool) ($context['orphan_token'] ?? false),
            'numeric_field_ambiguous' => false,
        ];

        $columnMid = $this->contentMidpoint($tokens);
        // Pass 1 — record-level inventory: find address-attached PIN before assigning fields.
        $confirmedPin = $this->prescanConfirmedPin($tokens, $ids);

        $firmName = null;
        $firmMeta = null;
        $explicitPartners = false;
        $persons = [];
        $entityClassifications = [];
        $fields = [
            'frn' => null, 'gst_no' => null, 'pan_no' => null, 'phone' => null,
            'email' => null, 'membership_no' => null, 'pincode' => $confirmedPin, 'state' => null,
        ];
        $fieldMeta = [];
        if ($confirmedPin !== null) {
            $fieldMeta['pincode'] = $this->meta($confirmedPin, $tokens[0], 0.94, 'pincode', [], 'address_line_pin');
        }
        $addressLines = [];
        $rightIdLines = [];
        $personRowY = null;
        $unknownTokens = [];
        $idCtx = [
            'confirmed_pin' => $confirmedPin,
            'prefer_membership' => $confirmedPin !== null,
        ];

        foreach ($tokens as $idx => $token) {
            $text = trim((string) ($token['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $isRight = ((float) ($token['x_center'] ?? 0)) >= $columnMid;
            $classified = $entities->classifyWithContext($text, [
                'is_right_aligned' => $isRight,
                'in_address_context' => $addressLines !== [],
                'section_city' => $sectionCity,
            ]);
            $classified['line_index'] = $idx;
            $classified['reason'] = null;
            $classified['assigned_field'] = null;

            if (preg_match('/\bpartners?\b/i', $text) || preg_match('/^[•·●▪◦\*]\s*\p{L}/u', $text)) {
                $explicitPartners = true;
            }

            if ($firmName === null && ($classified['entity_type'] === OcrEntityClassificationService::FIRM_NAME
                || $entities->isFirmName($this->stripFirmLineIdentifier($text)))) {
                $firmName = $this->stripFirmLineIdentifier($text);
                $firmMeta = $this->meta($firmName, $token, 0.92, 'firm_name', $classified);
                $classified['entity_type'] = OcrEntityClassificationService::FIRM_NAME;
                $classified['crm_field'] = 'firm_name';
                $classified['assigned_field'] = 'firm_name';
                $classified['reason'] = 'firm_name_pattern';
                // FRN trailing on firm line (… - 019083N).
                if ($hit = $ids->extractFrn($text, array_merge($idCtx, ['is_right_aligned' => $isRight]))) {
                    $fields['frn'] ??= $hit['value'];
                    $fieldMeta['frn'] ??= $this->meta($hit['raw'], $token, $hit['confidence'], 'frn', $classified, $hit['evidence']);
                } elseif ($hit = $ids->extractMembership($text, array_merge($idCtx, ['is_right_aligned' => $isRight, 'in_address_context' => false]))) {
                    $fields['membership_no'] ??= $hit['value'];
                    $fieldMeta['membership_no'] ??= $this->meta($hit['raw'], $token, $hit['confidence'], 'membership_no', $classified, $hit['evidence']);
                }
                $entityClassifications[] = $classified;
                continue;
            }

            if ($this->absorbCompoundLine($text, $token, $entities, $ids, $isRight, $persons, $addressLines, $fields, $fieldMeta)) {
                $classified['assigned_field'] = 'compound_line';
                $classified['reason'] = 'split_person_and_address';
                $entityClassifications[] = $classified;
                continue;
            }

            // FRN before membership — 019083N must never become membership/PIN.
            if ($hit = $ids->extractFrn($text, array_merge($idCtx, ['is_right_aligned' => $isRight]))) {
                $fields['frn'] ??= $hit['value'];
                $fieldMeta['frn'] ??= $this->meta($hit['raw'], $token, $hit['confidence'], 'frn', $classified, $hit['evidence']);
                $classified['entity_type'] = OcrEntityClassificationService::FRN;
                $classified['crm_field'] = 'frn';
                $classified['assigned_field'] = 'frn';
                $classified['reason'] = $hit['evidence'];
                $classified['normalized'] = $hit['value'];
                $entityClassifications[] = $classified;
                continue;
            }

            if ($hit = $ids->extractGst($text)) {
                $fields['gst_no'] ??= $hit['value'];
                $fieldMeta['gst_no'] ??= $this->meta($hit['raw'], $token, $hit['confidence'], 'gst_no', $classified, $hit['evidence']);
                $classified['entity_type'] = OcrEntityClassificationService::GST;
                $classified['crm_field'] = 'gst_no';
                $classified['assigned_field'] = 'gst_no';
                $classified['reason'] = $hit['evidence'];
                $entityClassifications[] = $classified;
                continue;
            }
            if ($hit = $ids->extractPan($text)) {
                $fields['pan_no'] ??= $hit['value'];
                $fieldMeta['pan_no'] ??= $this->meta($hit['raw'], $token, $hit['confidence'], 'pan_no', $classified, $hit['evidence']);
                $classified['entity_type'] = OcrEntityClassificationService::PAN;
                $classified['crm_field'] = 'pan_no';
                $classified['assigned_field'] = 'pan_no';
                $classified['reason'] = $hit['evidence'];
                $entityClassifications[] = $classified;
                continue;
            }
            if ($hit = $ids->extractPhone($text)) {
                $fields['phone'] ??= $hit['value'];
                $fieldMeta['phone'] ??= $this->meta($hit['raw'], $token, $hit['confidence'], 'phone', $classified, $hit['evidence']);
                $classified['entity_type'] = OcrEntityClassificationService::PHONE;
                $classified['crm_field'] = 'phone';
                $classified['assigned_field'] = 'phone';
                $classified['reason'] = $hit['evidence'];
                $entityClassifications[] = $classified;
                continue;
            }
            if ($hit = $ids->extractEmail($text)) {
                $fields['email'] ??= $hit['value'];
                $fieldMeta['email'] ??= $this->meta($hit['raw'], $token, $hit['confidence'], 'email', $classified, $hit['evidence']);
                $classified['entity_type'] = OcrEntityClassificationService::EMAIL;
                $classified['crm_field'] = 'email';
                $classified['assigned_field'] = 'email';
                $classified['reason'] = $hit['evidence'];
                $entityClassifications[] = $classified;
                continue;
            }

            if ($hit = $ids->extractMembership($text, array_merge($idCtx, [
                'is_right_aligned' => $isRight || $confirmedPin !== null,
                'in_address_context' => $addressLines !== [],
            ]))) {
                $fields['membership_no'] ??= $hit['value'];
                $fieldMeta['membership_no'] ??= $this->meta($hit['raw'], $token, $hit['confidence'], 'membership_no', $classified, $hit['evidence']);
                $classified['entity_type'] = OcrEntityClassificationService::MEMBERSHIP_NUMBER;
                $classified['crm_field'] = 'membership_no';
                $classified['assigned_field'] = 'membership_no';
                $classified['reason'] = $hit['evidence'];
                $classified['normalized'] = $hit['value'];
                if (! empty($hit['locality'])) {
                    $addressLines[] = [
                        'text' => $hit['locality'],
                        'token' => $token,
                        'classified' => ['entity_type' => OcrEntityClassificationService::CITY, 'crm_field' => 'city'],
                    ];
                }
                $entityClassifications[] = $classified;
                continue;
            }

            // Trailing 6-digit after city/state/address, when membership already resolved → PIN.
            if (preg_match('/^[1-9]\d{5}$/', $text)
                && $fields['pincode'] === null
                && $fields['membership_no'] !== null
                && $text !== $fields['membership_no']
                && ($addressLines !== [] || $sectionCity || $fields['state'] !== null)) {
                $fields['pincode'] = $text;
                $fieldMeta['pincode'] = $this->meta($text, $token, 0.86, 'pincode', $classified, 'trailing_pin_after_locality');
                $classified['entity_type'] = OcrEntityClassificationService::PINCODE;
                $classified['crm_field'] = 'pincode';
                $classified['assigned_field'] = 'pincode';
                $classified['reason'] = 'trailing_pin_after_locality';
                $entityClassifications[] = $classified;
                continue;
            }

            // Address-attached PIN (LAJPAT NAGAR-152116) — locality joins address block.
            if ($hit = $ids->extractPincode($text, [
                'in_address_context' => $addressLines !== [] || preg_match('/[A-Za-z]/', $text),
                'is_right_aligned' => $isRight,
            ])) {
                if ($fields['pincode'] === null || $fields['pincode'] === $hit['value']) {
                    $fields['pincode'] = $hit['value'];
                    $fieldMeta['pincode'] = $this->meta($hit['raw'], $token, $hit['confidence'], 'pincode', $classified, $hit['evidence']);
                } elseif ($hit['value'] !== $fields['pincode'] && empty($hit['locality'])) {
                    $flags['numeric_field_ambiguous'] = true;
                }
                if (! empty($hit['locality'])) {
                    $addressLines[] = [
                        'text' => $hit['locality'],
                        'token' => $token,
                        'classified' => ['entity_type' => OcrEntityClassificationService::ADDRESS, 'crm_field' => 'address'],
                    ];
                }
                $classified['entity_type'] = OcrEntityClassificationService::PINCODE;
                $classified['crm_field'] = 'pincode';
                $classified['assigned_field'] = 'pincode';
                $classified['reason'] = $hit['evidence'];
                $classified['normalized'] = $hit['value'];
                $entityClassifications[] = $classified;
                continue;
            }

            if ($isRight && $this->isStandaloneNumeric($text)) {
                $rightIdLines[] = ['text' => $text, 'token' => $token];
                $classified['reason'] = 'deferred_right_identifier';
                $entityClassifications[] = $classified;
                continue;
            }

            // Address before person — NAI SADAK / roads stay in address block.
            if ($classified['entity_type'] === OcrEntityClassificationService::ADDRESS
                || $classified['entity_type'] === OcrEntityClassificationService::CITY
                || $entities->isAddress($text)) {
                $addressLines[] = ['text' => $text, 'token' => $token, 'classified' => $classified];
                $classified['entity_type'] = OcrEntityClassificationService::ADDRESS;
                $classified['crm_field'] = 'address';
                $classified['assigned_field'] = 'address';
                $classified['reason'] = 'address_or_locality_pattern';
                $entityClassifications[] = $classified;
                continue;
            }

            if ($classified['entity_type'] === OcrEntityClassificationService::PERSON || $entities->isPerson($text)) {
                $personText = preg_replace('/^[•·●▪◦\*\-]\s*/u', '', $text) ?? $text;
                if ($entities->isAddress($personText)) {
                    $addressLines[] = ['text' => $personText, 'token' => $token, 'classified' => $classified];
                    $classified['entity_type'] = OcrEntityClassificationService::ADDRESS;
                    $classified['crm_field'] = 'address';
                    $classified['assigned_field'] = 'address';
                    $classified['reason'] = 'person_shape_but_address_vocabulary';
                    $entityClassifications[] = $classified;
                    continue;
                }
                $personRowY ??= (float) ($token['y_center'] ?? 0);
                $this->pushPerson($persons, $entities->stripPersonDecorations($personText), $token, $personText);
                $classified['entity_type'] = OcrEntityClassificationService::PERSON;
                $classified['crm_field'] = 'ca_name';
                $classified['assigned_field'] = 'ca_name';
                $classified['reason'] = 'verified_person_name';
                $entityClassifications[] = $classified;
                continue;
            }

            if ($classified['entity_type'] === OcrEntityClassificationService::STATE) {
                $fields['state'] = $text;
                $fieldMeta['state'] = $this->meta($text, $token, 0.85, 'state', $classified);
                $classified['assigned_field'] = 'state';
                $classified['reason'] = 'state_name';
                $entityClassifications[] = $classified;
                continue;
            }

            // Inside address block: absorb short unknown locality lines (never drop silently).
            if ($addressLines !== [] && ! $entities->isFirmName($text) && ! $entities->isPerson($text)) {
                $addressLines[] = ['text' => $text, 'token' => $token, 'classified' => $classified];
                $classified['entity_type'] = OcrEntityClassificationService::ADDRESS;
                $classified['crm_field'] = 'address';
                $classified['assigned_field'] = 'address';
                $classified['reason'] = 'inside_address_block';
                $entityClassifications[] = $classified;
                continue;
            }

            $classified['reason'] = 'no_validated_pattern';
            $unknownTokens[] = $text;
            $entityClassifications[] = $classified;
        }

        usort($rightIdLines, static fn (array $a, array $b) => ($a['token']['y_center'] ?? 0) <=> ($b['token']['y_center'] ?? 0));
        foreach ($rightIdLines as $entry) {
            $this->assignRightIdentifier($entry, $fields, $fieldMeta, $flags, $personRowY, $confirmedPin);
        }

        $assembled = $this->addressAssembler()->assemble(
            array_map(fn (array $l) => $l, $addressLines),
            0,
            $sectionCity,
        );
        $address = $assembled['address'] ?? null;
        if ($assembled['pincode'] !== null) {
            $fields['pincode'] ??= $assembled['pincode'];
        }
        if ($assembled['state'] !== null) {
            $fields['state'] ??= $assembled['state'];
        }
        $city = $assembled['city'] ?? $sectionCity;

        if ($address === null && $addressLines !== []) {
            $address = implode(', ', array_map(static fn (array $l) => $l['text'], $addressLines));
        }

        $roleResult = $this->roles()->resolve($firmName, $persons, $explicitPartners, $entities);
        foreach ($roleResult['rejected_as_address'] ?? [] as $rejected) {
            $addressLines[] = [
                'text' => $rejected,
                'token' => $tokens[0],
                'classified' => ['entity_type' => OcrEntityClassificationService::ADDRESS],
            ];
            $unknownTokens = array_values(array_filter($unknownTokens, static fn (string $u) => mb_strtolower($u) !== mb_strtolower($rejected)));
        }
        if (($roleResult['rejected_as_address'] ?? []) !== []) {
            $assembled = $this->addressAssembler()->assemble($addressLines, 0, $sectionCity);
            $address = $assembled['address'] ?? $address;
            if ($assembled['pincode'] !== null) {
                $fields['pincode'] ??= $assembled['pincode'];
            }
            if ($assembled['state'] !== null) {
                $fields['state'] ??= $assembled['state'];
            }
            $city = $assembled['city'] ?? $city;
        }
        $address = $this->stripPersonFromAddress($address, $roleResult['ca_name']);
        $members = $this->roles()->enforceProprietorNoPartners(
            $roleResult['firm_type'],
            $roleResult['ca_role'],
            $roleResult['members'],
        );

        if ($firmName === null) {
            if ($roleResult['ca_name'] === null && $address === null) {
                return null;
            }
            $firmName = $roleResult['ca_name'] ?? 'Unknown Firm';
            $firmMeta = $this->meta($firmName, $tokens[0], 0.4, 'firm_name', []);
            $flags['ambiguous_layout'] = true;
        }

        if ($roleResult['role_violation'] || ! empty($flags['numeric_field_ambiguous'])) {
            $flags['ambiguous_layout'] = true;
        }

        $fieldMeta = array_filter(array_merge($fieldMeta, [
            'firm_name' => $firmMeta,
            'ca_name' => $roleResult['ca_name'] !== null
                ? $this->meta($roleResult['ca_name'], $persons[0]['token'] ?? $tokens[0], 0.86, 'ca_name', [])
                : null,
            'address' => $address !== null
                ? $this->meta($address, $addressLines[0]['token'] ?? $tokens[0], 0.88, 'address', [], 'multiline_merge')
                : null,
            'city' => $city ? $this->meta((string) $city, $tokens[0], 0.8, 'city', []) : null,
        ]));

        $structuralConf = ($flags['row_merge_suspected'] || $flags['ambiguous_layout'] || $flags['cross_column_contamination']) ? 0.55 : 0.94;
        $bbox = LayoutGeometryHelper::mergeBboxes($tokens);

        return [
            'sequence_no' => $context['sequence_no'] ?? 1,
            'row_number' => $context['sequence_no'] ?? 1,
            'firm_name' => $firmName,
            'ca_name' => $roleResult['ca_name'],
            'ca_role' => $roleResult['ca_role'],
            'firm_type' => $roleResult['firm_type'],
            'frn' => $fields['frn'],
            'gst_no' => $fields['gst_no'],
            'pan_no' => $fields['pan_no'],
            'address' => $address,
            'city' => $city,
            'state' => $fields['state'],
            'pincode' => $fields['pincode'],
            'phone' => $fields['phone'],
            'email' => $fields['email'],
            'membership_no' => $fields['membership_no'],
            'review_status' => 'pending',
            'overall_confidence' => $this->overallConfidence($fieldMeta),
            'structural_confidence' => $structuralConf,
            'parser_confidence' => $this->overallConfidence($fieldMeta),
            'page_number' => $context['page'] ?? ($tokens[0]['page'] ?? null),
            'column_number' => $context['column'] ?? ($tokens[0]['column'] ?? null),
            'field_meta' => $fieldMeta,
            'field_confidences' => $this->confidenceMap($fieldMeta),
            'members' => $members,
            'entity_classifications' => $entityClassifications,
            'unknown_tokens' => $unknownTokens,
            'extraction_source' => $context['extraction_source'] ?? 'directory_record',
            'bounding_boxes' => $bbox,
            'row_merge_suspected' => $flags['row_merge_suspected'],
            'row_split_suspected' => $flags['row_split_suspected'],
            'ambiguous_layout' => $flags['ambiguous_layout'],
            'cross_column_contamination' => $flags['cross_column_contamination'],
            'reconstructed_text' => implode("\n", array_map(static fn (array $t) => $t['text'] ?? '', $tokens)),
            'source_lines' => array_map(static fn (array $t) => [
                'text' => $t['text'] ?? '',
                'page' => $t['page'] ?? null,
                'column' => $t['column'] ?? null,
                'x_center' => $t['x_center'] ?? null,
                'y_center' => $t['y_center'] ?? null,
            ], $tokens),
        ];
    }

    /**
     * Find PIN only when attached to locality/address text inside this record.
     *
     * @param  list<array<string, mixed>>  $tokens
     */
    private function prescanConfirmedPin(array $tokens, OcrIdentifierExtractorService $ids): ?string
    {
        foreach ($tokens as $token) {
            $text = trim((string) ($token['text'] ?? ''));
            if ($text === '' || $ids->isIcaiFrnPattern($text)) {
                continue;
            }
            $hit = $ids->extractPincode($text, ['in_address_context' => true]);
            if ($hit !== null && ! empty($hit['locality'])) {
                return $hit['value'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $fieldMeta
     * @param  array<string, bool>  $flags
     */
    private function assignRightIdentifier(array $entry, array &$fields, array &$fieldMeta, array &$flags, ?float $personRowY = null, ?string $confirmedPin = null): void
    {
        $text = $entry['text'];
        $token = $entry['token'];
        $ids = $this->ids();
        $tokenY = (float) ($token['y_center'] ?? 0);
        $isAddressRow = $personRowY !== null && $tokenY > ($personRowY + 0.012);
        $idCtx = ['confirmed_pin' => $confirmedPin, 'prefer_membership' => $confirmedPin !== null, 'is_right_aligned' => true];

        if ($hit = $ids->extractFrn($text, $idCtx)) {
            $fields['frn'] ??= $hit['value'];
            $fieldMeta['frn'] ??= $this->meta($hit['raw'], $token, $hit['confidence'], 'frn', [], $hit['evidence']);
            return;
        }
        if ($isAddressRow && ($hit = $ids->extractPincode($text, ['is_right_aligned' => true, 'in_address_context' => true]))) {
            $fields['pincode'] ??= $hit['value'];
            $fieldMeta['pincode'] ??= $this->meta($hit['raw'], $token, $hit['confidence'], 'pincode', [], $hit['evidence']);
            return;
        }
        if ($hit = $ids->extractMembership($text, $idCtx)) {
            $fields['membership_no'] ??= $hit['value'];
            $fieldMeta['membership_no'] ??= $this->meta($hit['raw'], $token, $hit['confidence'], 'membership_no', [], $hit['evidence']);
            return;
        }
        if ($ids->extractPincode($text, ['is_right_aligned' => true])) {
            $flags['numeric_field_ambiguous'] = true;
        }
    }

    private function entities(): OcrEntityClassificationService
    {
        return $this->classifier ?? new OcrEntityClassificationService;
    }

    private function ids(): OcrIdentifierExtractorService
    {
        return $this->identifierExtractor ?? new OcrIdentifierExtractorService;
    }

    private function roles(): OcrFirmRoleResolverService
    {
        return $this->roles ?? new OcrFirmRoleResolverService;
    }

    private function addressAssembler(): OcrAddressAssemblerService
    {
        return $this->addressAssembler ?? new OcrAddressAssemblerService($this->entities());
    }

    /**
     * @param  list<array<string, mixed>>  $tokens
     */
    private function contentMidpoint(array $tokens): float
    {
        $centers = array_map(static fn (array $t) => (float) ($t['x_center'] ?? 0), $tokens);
        $min = min($centers);
        $max = max($centers);
        if (($max - $min) < 0.05) {
            return 1.0;
        }

        return $min + (($max - $min) * 0.65);
    }

    private function isStandaloneNumeric(string $text): bool
    {
        $t = trim($text);

        return (bool) preg_match('/^(?:\d{5,6}\s*[A-Z]|\d{5,6}\s+\d|\d[\d\s\-\/]{3,})$/i', $t);
    }

    private function stripFirmLineIdentifier(string $text): string
    {
        if (preg_match('/^(.+?)\s*[-–]\s*\d{5,6}[A-Z]?\s*$/i', $text, $m)) {
            return trim($m[1]);
        }

        return $text;
    }

    private function stripPersonFromAddress(?string $address, ?string $caName): ?string
    {
        if ($address === null || $caName === null || trim($caName) === '') {
            return $address;
        }
        $normCa = mb_strtolower(trim($caName));
        $parts = array_map('trim', explode(',', $address));
        $filtered = array_values(array_filter($parts, static function (string $part) use ($normCa) {
            return mb_strtolower($part) !== $normCa;
        }));

        return $filtered !== [] ? implode(', ', $filtered) : $address;
    }

    /**
     * Split comma-joined lines that mix person names with address fragments.
     *
     * @param  list<array<string, mixed>>  $persons
     * @param  list<array<string, mixed>>  $addressLines
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $fieldMeta
     */
    private function absorbCompoundLine(
        string $text,
        array $token,
        OcrEntityClassificationService $entities,
        OcrIdentifierExtractorService $ids,
        bool $isRight,
        array &$persons,
        array &$addressLines,
        array &$fields,
        array &$fieldMeta,
    ): bool {
        if (! str_contains($text, ',') || mb_strlen($text) < 20) {
            return false;
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $text))));
        if (count($parts) < 2) {
            return false;
        }
        $hasAddress = false;
        $hasPerson = false;
        foreach ($parts as $part) {
            if ($entities->isAddress($part)) {
                $hasAddress = true;
            }
            if ($entities->isPerson($part)) {
                $hasPerson = true;
            }
        }
        if (! $hasAddress || ! $hasPerson) {
            return false;
        }
        foreach ($parts as $part) {
            if ($hit = $ids->extractPincode($part, ['in_address_context' => $addressLines !== []])) {
                $fields['pincode'] ??= $hit['value'];
                $fieldMeta['pincode'] ??= $this->meta($hit['raw'], $token, $hit['confidence'], 'pincode', [], $hit['evidence']);
                if (! empty($hit['locality'])) {
                    $addressLines[] = ['text' => $hit['locality'], 'token' => $token, 'classified' => []];
                }
                continue;
            }
            if ($entities->isPerson($part)) {
                $this->pushPerson($persons, $entities->stripPersonDecorations($part), $token, $part);
                continue;
            }
            if ($entities->isAddress($part) || $addressLines !== []) {
                $addressLines[] = ['text' => $part, 'token' => $token, 'classified' => $entities->classify($part)];
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $persons
     */
    private function pushPerson(array &$persons, string $name, array $token, string $text): void
    {
        $key = mb_strtolower(trim($name));
        if ($key === '') {
            return;
        }
        foreach ($persons as $existing) {
            if (mb_strtolower((string) ($existing['name'] ?? '')) === $key) {
                return;
            }
        }
        $persons[] = ['name' => $name, 'token' => $token, 'text' => $text];
    }

    /**
     * @param  array<string, mixed>  $classified
     * @return array<string, mixed>
     */
    private function meta(string $value, array $token, float $parserConf, string $field, array $classified = [], ?string $evidence = null): array
    {
        $ocr = isset($token['ocr_confidence']) ? (float) $token['ocr_confidence'] : null;
        $blended = $ocr !== null ? round(($parserConf * 0.6) + ($ocr * 0.4), 4) : $parserConf;

        return [
            'value' => $value,
            'source_text' => $value,
            'field' => $field,
            'confidence' => $blended,
            'parser_confidence' => $parserConf,
            'ocr_confidence' => $ocr,
            'evidence' => $evidence ?? ($classified['entity_type'] ?? null),
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
     * @param  array<string, array<string, mixed>|null>  $fieldMeta
     * @return array<string, float|null>
     */
    private function confidenceMap(array $fieldMeta): array
    {
        $map = [];
        foreach ($fieldMeta as $field => $meta) {
            if (is_array($meta)) {
                $map[$field] = isset($meta['confidence']) ? (float) $meta['confidence'] : null;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $fieldMeta
     */
    private function overallConfidence(array $fieldMeta): float
    {
        $scores = [];
        foreach ($fieldMeta as $meta) {
            if (is_array($meta) && isset($meta['confidence'])) {
                $scores[] = (float) $meta['confidence'];
            }
        }

        return $scores !== [] ? round(array_sum($scores) / count($scores), 4) : 0.5;
    }
}
