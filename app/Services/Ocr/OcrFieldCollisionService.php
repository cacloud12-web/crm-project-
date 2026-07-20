<?php

namespace App\Services\Ocr;

/**
 * Cross-field collision detection for OCR staging.
 * Fail-closed: any collision quarantines the row — never silently remaps fields.
 */
class OcrFieldCollisionService
{
    public function __construct(
        private readonly ?OcrEntityClassificationService $classifier = null,
    ) {}

    private function entities(): OcrEntityClassificationService
    {
        return $this->classifier ?? new OcrEntityClassificationService;
    }

    /**
     * @param  array<string, mixed>  $firm
     * @return array{
     *     ok: bool,
     *     codes: list<string>,
     *     messages: list<string>
     * }
     */
    public function detect(array $firm): array
    {
        $mode = 'firm_ca_city';
        try {
            if (function_exists('config')) {
                $mode = (string) config('ocr_workflow.mode', 'firm_ca_city');
            }
        } catch (\Throwable) {
        }
        if ($mode === 'firm_ca_city') {
            return $this->detectThreeField($firm);
        }

        $codes = [];
        $messages = [];

        $firmName = $this->s($firm['firm_name'] ?? ($firm['raw_firm_name'] ?? null));
        $address = $this->s($firm['address'] ?? null);
        $pincode = $this->digits($firm['pincode'] ?? null);
        $phone = $this->digits($firm['phone'] ?? null);
        $frn = $this->alnum($firm['frn'] ?? null);
        $members = is_array($firm['members'] ?? null) ? $firm['members'] : [];
        $primary = $members[0] ?? [];
        $caName = $this->s($firm['ca_name'] ?? ($primary['ca_name'] ?? ($primary['raw_ca_name'] ?? null)));
        $membership = $this->alnum($firm['membership_no'] ?? ($primary['membership_no'] ?? null));
        $partnerNames = [];
        foreach ($members as $member) {
            $name = $this->s($member['ca_name'] ?? ($member['raw_ca_name'] ?? null));
            if ($name !== null) {
                $partnerNames[] = $name;
            }
        }

        // Address leaked into partner / CA / firm.
        if ($caName !== null && $this->entities()->isAddress($caName)) {
            $codes[] = 'ADDRESS_IN_CA_NAME_FIELD';
            $codes[] = 'ADDRESS_IN_CA_NAME';
            $messages[] = 'CA name appears to contain address text.';
        } elseif ($caName !== null && ! $this->entities()->isPerson($caName) && ! $this->entities()->isFirmName($caName)) {
            $codes[] = 'INVALID_PERSON_NAME';
            $messages[] = 'CA name does not look like a valid person name.';
        }
        foreach ($partnerNames as $partner) {
            if ($this->entities()->isAddress($partner)) {
                $codes[] = 'ADDRESS_IN_PARTNER_FIELD';
                $messages[] = 'Partner name appears to contain address text.';
                break;
            }
            if (! $this->entities()->isPerson($partner)) {
                $codes[] = 'INVALID_PERSON_NAME';
                $messages[] = 'Partner name does not look like a valid person name.';
                break;
            }
        }
        if ($firmName !== null && $this->entities()->isAddress($firmName) && ! $this->entities()->isFirmName($firmName)) {
            $codes[] = 'ADDRESS_IN_FIRM_NAME';
            $messages[] = 'Firm name appears to be an address, not a firm.';
        } elseif ($firmName !== null && ! $this->entities()->isFirmName($firmName) && mb_strlen($firmName) < 3) {
            $codes[] = 'INVALID_FIRM_NAME';
            $messages[] = 'Firm name is too short or invalid.';
        }

        // Duplicate partner entries without explicit multi-partner source.
        $seenPartners = [];
        foreach ($partnerNames as $partner) {
            $key = mb_strtolower($partner);
            if (isset($seenPartners[$key])) {
                $codes[] = 'DUPLICATE_CA_AS_PARTNER';
                $messages[] = 'CA/partner name duplicated without explicit source evidence.';
                break;
            }
            $seenPartners[$key] = true;
        }

        // PIN in membership — only when membership looks like a PIN without directory evidence.
        if ($membership !== null && preg_match('/^[1-9]\d{5}$/', $membership) && ! $this->membershipPinAllowed($firm)) {
            $codes[] = 'PIN_IN_MEMBERSHIP_FIELD';
            $messages[] = 'Membership number looks like a PIN code.';
        }
        if ($pincode !== null && $membership !== null && $pincode === $membership) {
            $codes[] = 'PIN_IN_MEMBERSHIP_FIELD';
            $messages[] = 'PIN and membership number are identical without evidence they are the same identifier.';
        }
        if ($firmName !== null && $pincode !== null && str_contains($firmName, $pincode)) {
            $codes[] = 'PIN_IN_FIRM_NAME';
            $messages[] = 'Firm name contains a PIN code.';
        }

        // Mobile leaked into names / membership.
        if ($phone !== null && strlen($phone) === 10) {
            if ($firmName !== null && str_contains(preg_replace('/\D+/', '', $firmName) ?? '', $phone)) {
                $codes[] = 'MOBILE_IN_FIRM_NAME';
                $messages[] = 'Firm name contains a mobile number.';
            }
            if ($caName !== null && str_contains(preg_replace('/\D+/', '', $caName) ?? '', $phone)) {
                $codes[] = 'MOBILE_IN_CA_NAME';
                $messages[] = 'CA name contains a mobile number.';
            }
            if ($membership !== null && $membership === $phone) {
                $codes[] = 'MOBILE_IN_MEMBERSHIP_FIELD';
                $messages[] = 'Membership number equals mobile number.';
            }
        }

        // Same text assigned to incompatible fields.
        if ($firmName !== null && $caName !== null && $this->sameText($firmName, $caName) && ! $this->looksLikeFirmName($firmName)) {
            $codes[] = 'INCOMPATIBLE_FIELD_OVERLAP';
            $messages[] = 'Firm name and CA name are identical — possible field mix.';
        }
        if ($address !== null && $caName !== null && $this->sameText($address, $caName)) {
            $codes[] = 'INCOMPATIBLE_FIELD_OVERLAP';
            $messages[] = 'Address and CA name are identical.';
        }
        if ($address !== null && $firmName !== null && $this->sameText($address, $firmName)) {
            $codes[] = 'INCOMPATIBLE_FIELD_OVERLAP';
            $messages[] = 'Address and firm name are identical.';
        }

        // FRN misplaced as membership/PIN-like short numeric without letters when format requires alphanumeric identity.
        if ($frn !== null && preg_match('/^[1-9]\d{5}$/', $frn) && ($pincode === $frn || $membership === $frn)) {
            $codes[] = 'FRN_IN_WRONG_FIELD';
            $messages[] = 'FRN collides with PIN/membership — ambiguous identifier.';
        }

        // Row ambiguity hints from parser.
        if (! empty($firm['row_merge_suspected']) || ! empty($firm['merge_suspected'])) {
            $codes[] = 'ROW_MERGE_SUSPECTED';
            $messages[] = 'Parser suspected two source rows were merged.';
        }
        if (! empty($firm['row_split_suspected']) || ! empty($firm['split_suspected'])) {
            $codes[] = 'ROW_SPLIT_SUSPECTED';
            $messages[] = 'Parser suspected one source row was split into multiple firms.';
        }
        if (! empty($firm['ambiguous_table']) || ! empty($firm['ambiguous_layout'])
            || (($firm['extraction_source'] ?? '') === 'text_parser' && ! empty($firm['table_expected']))) {
            $codes[] = 'AMBIGUOUS_TABLE_STRUCTURE';
            $codes[] = 'AMBIGUOUS_LAYOUT';
            $messages[] = 'Table/layout structure is ambiguous; cell-to-field mapping not confirmed.';
        }
        if (! empty($firm['source_column_mismatch'])) {
            $codes[] = 'SOURCE_COLUMN_MISMATCH';
            $messages[] = 'Source column does not match destination field.';
        }

        // Missing required firm name.
        if ($firmName === null || mb_strlen($firmName) < 2) {
            $codes[] = 'MISSING_REQUIRED_FIELD';
            $messages[] = 'Firm name is missing.';
        }

        if (! empty($firm['cross_column_contamination'])) {
            $codes[] = 'CROSS_COLUMN_CONTAMINATION';
            $messages[] = 'Tokens from multiple columns were mixed into one record.';
        }
        if (! empty($firm['orphan_token'])) {
            $codes[] = 'ORPHAN_TOKEN';
            $messages[] = 'Record block started without a firm-name anchor.';
        }
        if (! empty($firm['ambiguous_record_boundary']) || ! empty($firm['ambiguous_layout'])) {
            $codes[] = 'AMBIGUOUS_RECORD_BOUNDARY';
        }
        if (! empty($firm['numeric_field_ambiguous'])) {
            $codes[] = 'NUMERIC_FIELD_AMBIGUOUS';
            $messages[] = 'Numeric identifier type is uncertain (PIN vs membership vs FRN).';
        }
        if (! empty($firm['frn_position_mismatch'])) {
            $codes[] = 'FRN_POSITION_MISMATCH';
            $messages[] = 'Identifier position does not match expected FRN/membership column.';
        }

        // Low confidence on required fields — only flag as collision when not auto-only mode.
        $minConf = (float) config('ocr_safety.min_required_field_confidence', 0.99);
        $minParser = (float) config('ocr_safety.min_parser_confidence', 0.70);
        $minStructural = (float) config('ocr_safety.min_structural_confidence', 0.80);
        $autoOnly = (bool) config('ocr_safety.low_confidence_blocks_auto_only', true);
        $structuralConf = isset($firm['structural_confidence']) ? (float) $firm['structural_confidence'] : null;
        $structuralOk = $structuralConf === null || $structuralConf >= $minStructural;
        $meta = is_array($firm['field_meta'] ?? null) ? $firm['field_meta'] : [];
        foreach (['firm_name', 'ca_name', 'address', 'phone', 'membership_no', 'frn', 'pincode'] as $field) {
            $entry = is_array($meta[$field] ?? null) ? $meta[$field] : [];
            if (! isset($entry['confidence']) && ! isset($entry['parser_confidence'])) {
                continue;
            }
            $parserConf = (float) ($entry['parser_confidence'] ?? $entry['confidence'] ?? 0);
            $valuePresent = match ($field) {
                'firm_name' => $firmName !== null,
                'ca_name' => $caName !== null,
                'address' => $address !== null,
                'phone' => $phone !== null,
                'membership_no' => $membership !== null,
                'frn' => $frn !== null,
                'pincode' => $pincode !== null,
                default => false,
            };
            if (! $valuePresent || $parserConf >= $minParser) {
                continue;
            }
            if ($autoOnly && $structuralOk) {
                continue;
            }
            $codes[] = 'LOW_FIELD_CONFIDENCE';
            $messages[] = 'Needs review: '.str_replace('_', '-', $field).' confidence is '.round($parserConf * 100).'%.';
        }

        // PIN in membership cross-check with new code.
        if ($pincode !== null && $membership !== null && $pincode === $membership) {
            $codes[] = 'MEMBERSHIP_IN_PIN_FIELD';
        }

        $codes = array_values(array_unique($codes));
        $messages = array_values(array_unique($messages));

        return [
            'ok' => $codes === [],
            'codes' => $codes,
            'messages' => $messages,
        ];
    }

    private function looksLikeAddress(?string $value): bool
    {
        return $this->entities()->isAddress($value);
    }

    private function looksLikeFirmName(?string $value): bool
    {
        return $this->entities()->isFirmName($value);
    }

    private function sameText(string $a, string $b): bool
    {
        $na = mb_strtolower(preg_replace('/\s+/', ' ', trim($a)) ?? '');
        $nb = mb_strtolower(preg_replace('/\s+/', ' ', trim($b)) ?? '');

        return $na !== '' && $na === $nb;
    }

    private function s(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function digits(mixed $value): ?string
    {
        $s = $this->s($value);
        if ($s === null) {
            return null;
        }
        $d = preg_replace('/\D+/', '', $s) ?? '';

        return $d !== '' ? $d : null;
    }

    private function alnum(mixed $value): ?string
    {
        $s = $this->s($value);
        if ($s === null) {
            return null;
        }
        $d = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $s) ?? '');

        return $d !== '' ? $d : null;
    }

    /**
     * @param  array<string, mixed>  $firm
     * @return array{ok: bool, codes: list<string>, messages: list<string>}
     */
    private function detectThreeField(array $firm): array
    {
        $codes = [];
        $messages = [];
        $firmName = $this->s($firm['firm_name'] ?? ($firm['raw_firm_name'] ?? null));
        $caName = $this->s($firm['ca_name'] ?? ($firm['raw_ca_name'] ?? null));
        $city = $this->s($firm['city'] ?? ($firm['raw_city'] ?? null));
        $missing = is_array($firm['missing_required_fields'] ?? null) ? $firm['missing_required_fields'] : [];

        if ($firmName === null || in_array('firm_name', $missing, true)) {
            $codes[] = 'MISSING_FIRM_NAME';
            $messages[] = 'Firm name is required.';
        } elseif ($this->entities()->isAddress($firmName) && ! $this->entities()->isFirmName($firmName)) {
            $codes[] = 'ADDRESS_IN_FIRM_NAME';
            $messages[] = 'Firm name appears to be an address, not a firm.';
        }

        if ($caName === null || in_array('ca_name', $missing, true)) {
            $codes[] = 'MISSING_CA_NAME';
            $messages[] = 'CA name is required.';
        } elseif ($this->entities()->isAddress($caName)) {
            $codes[] = 'ADDRESS_IN_CA_NAME_FIELD';
            $messages[] = 'CA name appears to contain address text.';
        } elseif (! $this->entities()->isPerson($caName) && ! $this->isAcceptableGivenName($caName)) {
            $codes[] = 'INVALID_PERSON_NAME';
            $messages[] = 'CA name does not look like a valid person name.';
        }

        if ($city === null || in_array('city', $missing, true)) {
            $codes[] = 'MISSING_CITY';
            $messages[] = 'City is required.';
        } elseif (preg_match('/\b(?:street|sadak|hospital|floor|shop|ward|backside|colony|market|mandi|plot|building)\b/iu', $city)
            && ! (new OcrCityResolverService)->isResolvableCity($city)) {
            $codes[] = 'ADDRESS_IN_CITY_FIELD';
            $messages[] = 'City appears to contain address text.';
        }

        // City ↔ CA collision: confirmed city heading must never sit in CA Name.
        if ($city !== null && $caName !== null && mb_strtolower($city) === mb_strtolower($caName)) {
            $codes[] = 'CITY_CA_NAME_COLLISION';
            $messages[] = 'City and CA Name cannot be the same value.';
        } elseif ($caName !== null && (new OcrCityHeadingDetector($this->entities()))->isHeading($caName)
            && ! $this->entities()->isPerson($caName)) {
            $codes[] = 'CITY_IN_CA_NAME_FIELD';
            $messages[] = 'CA name appears to be a city heading.';
        } elseif ($city !== null && $this->entities()->isPerson($city)
            && ! (new OcrCityResolverService)->isResolvableCity($city)) {
            $codes[] = 'PERSON_IN_CITY_FIELD';
            $messages[] = 'City appears to be a person name.';
        }

        // Scoped layout only — require evidence that firm/CA/city crossed a record boundary.
        // Ignored FRN/address/PIN/unknown tokens never raise ROW_MERGE_SUSPECTED.
        $mergeEvidence = is_array($firm['row_merge_evidence'] ?? null) ? $firm['row_merge_evidence'] : [];
        if ((! empty($firm['row_merge_suspected']) || ! empty($firm['merge_suspected'])) && $mergeEvidence !== []) {
            $codes[] = 'ROW_MERGE_SUSPECTED';
            $messages[] = 'Neighboring rows may have been merged — firm/CA/city assignment uncertain.';
        } elseif ((! empty($firm['row_merge_suspected']) || ! empty($firm['merge_suspected'])) && $mergeEvidence === []) {
            // Legacy proximity flags without scoped evidence — ignore for three-field decisions.
        }
        if (! empty($firm['row_split_suspected']) || ! empty($firm['split_suspected'])) {
            $codes[] = 'ROW_SPLIT_SUSPECTED';
            $messages[] = 'Record may have been split across rows — firm/CA/city assignment uncertain.';
        }
        if (! empty($firm['firm_name_boundary_uncertain']) && $firmName !== null) {
            $codes[] = 'FIRM_NAME_BOUNDARY_UNCERTAIN';
            $messages[] = 'Firm name record boundary is uncertain.';
        }
        // Soft layout flags — never block three-field Verified status or user-facing errors.
        // City/CA "boundary uncertain" was incorrectly raised for empty fields before forward-fill.

        $codes = array_values(array_unique($codes));

        return ['ok' => $codes === [], 'codes' => $codes, 'messages' => array_values(array_unique($messages))];
    }

    /**
     * Proprietary single given-name (HARSHIL) — not a full 2-word person shape, but valid CA.
     */
    private function isAcceptableGivenName(?string $name): bool
    {
        $name = trim((string) $name);
        if ($name === '' || preg_match('/\d/', $name) || preg_match('/\s/', $name)) {
            return false;
        }
        if (mb_strlen($name) < 3 || mb_strlen($name) > 24 || ! preg_match('/^[A-Za-z.\'\-]+$/', $name)) {
            return false;
        }
        if ($this->entities()->isAddress($name) || $this->entities()->isFirmName($name) || $this->entities()->isCity($name)) {
            return false;
        }

        return ! preg_match('/\b(?:mandi|nagar|estate|associates|company|llp|street|road)\b/iu', $name);
    }

    private function membershipPinAllowed(array $firm): bool
    {
        $meta = is_array($firm['field_meta'] ?? null) ? $firm['field_meta'] : [];
        $entry = is_array($meta['membership_no'] ?? null) ? $meta['membership_no'] : [];
        $evidence = $entry['evidence'] ?? null;

        return in_array($evidence, [
            'right_column_membership', 'city_dash_membership', 'firm_line_identifier',
            'icai_membership_suffix', 'labeled_membership', 'membership_with_confirmed_pin',
            'membership_record_context',
        ], true);
    }
}
