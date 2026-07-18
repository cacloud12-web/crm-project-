<?php

namespace App\Services\Ocr;

/**
 * Classifies raw OCR text into entity types before CRM field mapping.
 * Never assigns fields by line order alone — content determines type.
 */
class OcrEntityClassificationService
{
    public const PERSON = 'PERSON';

    public const FIRM_NAME = 'FIRM_NAME';

    public const ADDRESS = 'ADDRESS';

    public const CITY = 'CITY';

    public const STATE = 'STATE';

    public const PINCODE = 'PINCODE';

    public const PHONE = 'PHONE';

    public const EMAIL = 'EMAIL';

    public const GST = 'GST';

    public const PAN = 'PAN';

    public const FRN = 'FRN';

    public const MEMBERSHIP_NUMBER = 'MEMBERSHIP_NUMBER';

    public const SECTION_HEADING = 'SECTION_HEADING';

    public const UNKNOWN = 'UNKNOWN';

    public function __construct(
        private readonly ?OcrUnicodeNormalizationService $unicode = null,
    ) {}

    private function unicode(): OcrUnicodeNormalizationService
    {
        return $this->unicode ?? new OcrUnicodeNormalizationService;
    }
  /** @var list<string> */
    private const ADDRESS_KEYWORDS = [
        'road', 'street', 'sadak', 'lane', 'market', 'nagar', 'colony', 'sector', 'phase',
        'floor', 'shop', 'sco', 'plot', 'ward', 'block', 'crossing', 'chowk',
        'district', 'tehsil', 'cantt', 'mohalla', 'mandi', 'estate', 'urban',
        'complex', 'building', 'tower', 'plaza', 'industrial', 'area', 'near',
        'opp', 'opposite', 'behind', 'backside', 'wing', 'village', 'gaon', 'majri',
        'dist', 'p.o', 'po ', 'post', 'office', 'hospital', 'clinic', 'school', 'temple',
        'bank', 'stand', 'jail', 'land', 'bypass', 'path', 'park', 'garden',
        'huda', 'vihar', 'bagh', 'enclave', 'extension', 'layout', 'society',
        'apartment', 'flat', 'house', 'bazaar', 'bazar', 'gali', 'marg', 'city',
    ];

  /** @var list<string> */
    private const FIRM_MARKERS = [
        '& associates', 'and associates', '& co', '& co.', 'and co', 'and co.',
        'llp', 'pvt ltd', 'pvt. ltd', 'private limited', 'chartered accountant',
        'chartered accountants', '& company', 'and company', 'associates',
        'consultants', 'advisors', 'advisory', '& sons', 'and sons', ' & ',
    ];

  /** @var list<string> */
    private const PERSON_PREFIXES = [
        's/o', 'sio', 's.o', 'w/o', 'd/o', 'c/o', 'ca ', 'ca.', 'shri ', 'smt ',
        'mr ', 'mrs ', 'ms ', 'prop ', 'prop.', 'proprietor ',
    ];

  /** @var list<string> */
    private const LOCALITY_WORDS = [
        'estate', 'urban', 'mandi', 'nagar', 'colony', 'sector', 'phase', 'cantt',
        'mohalla', 'vihar', 'enclave', 'layout', 'huda', 'industrial', 'market',
        'bazaar', 'bazar', 'chowk', 'crossing', 'suraj', 'new', 'road', 'street', 'sadak',
        'lane', 'hospital', 'backside', 'office', 'post', 'stand', 'jail', 'land',
        'bank', 'city', 'village', 'gaon', 'majri', 'tehsil', 'ward', 'near',
        'behind', 'opposite', 'opp', 'complex', 'plaza', 'tower', 'building',
        'shop', 'floor', 'plot', 'house', 'flat', 'sco', 'park', 'garden', 'bypass',
        'temple', 'school', 'clinic',
    ];

    /**
     * @return array{
     *     entity_type: string,
     *     crm_field: string|null,
     *     confidence: float,
     *     raw: string,
     *     raw_value: string,
     *     classification_value: string,
     *     normalized: string|null,
     *     invalid_as_person: bool,
     *     invalid_as_partner: bool,
     *     unicode_normalized: bool,
     *     confusable_replaced: bool,
     *     unicode_confidence: float,
     *     unicode_reason: string|null,
     *     confusable_replacements: list<array{from: string, to: string}>
     * }
     */
    public function classify(string $text): array
    {
        $raw = $text;
        $unicode = $this->unicode()->normalizeForClassification($text);
        $classificationValue = $unicode['classification_value'];
        if ($classificationValue === '') {
            return $this->result(self::UNKNOWN, null, 0.0, $raw, $classificationValue, null, true, true, $unicode);
        }

        if ($value = $this->extractGst($classificationValue)) {
            return $this->result(self::GST, 'gst_no', 0.95, $raw, $classificationValue, $value, true, true, $unicode);
        }
        if ($value = $this->extractPan($classificationValue)) {
            return $this->result(self::PAN, 'pan_no', 0.93, $raw, $classificationValue, $value, true, true, $unicode);
        }
        // ICAI FRN before generic membership / bare PIN.
        if (preg_match('/^\d{5,6}\s*[NCWES]$/i', $classificationValue)) {
            return $this->result(self::FRN, 'frn', 0.91, $raw, $classificationValue, strtoupper(preg_replace('/\s+/', '', $classificationValue) ?? $classificationValue), true, true, $unicode);
        }
        if ($value = $this->extractFrn($classificationValue)) {
            return $this->result(self::FRN, 'frn', 0.88, $raw, $classificationValue, $value, true, true, $unicode);
        }
        if ($value = $this->extractEmail($classificationValue)) {
            return $this->result(self::EMAIL, 'email', 0.96, $raw, $classificationValue, $value, true, true, $unicode);
        }
        if ($value = $this->extractPhone($classificationValue)) {
            return $this->result(self::PHONE, 'phone', 0.9, $raw, $classificationValue, $value, true, true, $unicode);
        }
        if ($value = $this->extractMembership($classificationValue)) {
            $namePart = trim(preg_replace('/\b(?:m\.?\s*no\.?|mem(?:bership)?\.?\s*no\.?)\s*[:\-]?\s*[A-Z0-9\/\-]+/i', '', $classificationValue) ?? $classificationValue);
            if ($namePart === '' || (! $this->looksLikePersonShape($namePart) && ! $this->isFirmName($namePart))) {
                return $this->result(self::MEMBERSHIP_NUMBER, 'membership_no', 0.9, $raw, $classificationValue, $value, true, true, $unicode);
            }
        }
        if ($value = $this->extractPincode($classificationValue)) {
            // Bare 6-digit alone is ambiguous (PIN vs membership) — leave UNKNOWN for record context.
            if (preg_match('/^\d{6}$/', trim($classificationValue))) {
                return $this->result(self::UNKNOWN, null, 0.45, $raw, $classificationValue, null, true, true, $unicode);
            }
            if (! preg_match('/\b(?:m\.?\s*no\.?|mem(?:bership)?\.?\s*no\.?|membership)\b/i', $classificationValue)) {
                return $this->result(self::PINCODE, 'pincode', 0.94, $raw, $classificationValue, $value, true, true, $unicode);
            }
        }
        if ($this->isState($classificationValue)) {
            return $this->result(self::STATE, 'state', 0.85, $raw, $classificationValue, $classificationValue, true, true, $unicode);
        }
        if ($this->isFirmName($classificationValue)) {
            $conf = 0.9 * ($unicode['confusable_replaced'] ? $unicode['unicode_confidence'] : 1.0);

            return $this->result(self::FIRM_NAME, 'firm_name', round($conf, 4), $raw, $classificationValue, $classificationValue, true, true, $unicode);
        }
        // Address before person — critical: URBAN ESTATE HUDA, ANAJ MANDI, NEW SURAJ NAGAR.
        if ($this->isAddress($classificationValue)) {
            return $this->result(self::ADDRESS, 'address', 0.88, $raw, $classificationValue, $classificationValue, true, true, $unicode);
        }
        if ($this->isCity($classificationValue)) {
            return $this->result(self::CITY, 'city', 0.75, $raw, $classificationValue, $classificationValue, true, true, $unicode);
        }
        if ($this->isPerson($classificationValue)) {
            $person = $this->stripPersonDecorations($classificationValue);
            $conf = 0.82 * ($unicode['confusable_replaced'] ? $unicode['unicode_confidence'] : 1.0);

            return $this->result(self::PERSON, 'ca_name', round($conf, 4), $raw, $classificationValue, $person, false, false, $unicode);
        }

        return $this->result(self::UNKNOWN, null, 0.4, $raw, $classificationValue, null, true, true, $unicode);
    }

    /**
     * Classify with layout/position context (right column, section heading, address context).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function classifyWithContext(string $text, array $context = []): array
    {
        $raw = $text;
        $unicode = $this->unicode()->normalizeForClassification($text);
        $classificationValue = $unicode['classification_value'];
        if ($classificationValue === '') {
            return $this->result(self::UNKNOWN, null, 0.0, $raw, $classificationValue, null, true, true, $unicode);
        }

        $isRight = (bool) ($context['is_right_aligned'] ?? false);
        $inAddress = (bool) ($context['in_address_context'] ?? false);
        $sectionCandidate = (bool) ($context['is_section_heading_candidate'] ?? false);

        if ($sectionCandidate && $this->isSectionHeading($classificationValue)) {
            return $this->result(self::SECTION_HEADING, 'city', 0.9, $raw, $classificationValue, $classificationValue, true, true, $unicode);
        }

        // Right-aligned standalone numerics: FRN / membership, not PIN (unless in address line).
        if ($isRight && ! $inAddress && preg_match('/^\d[\d\s\-\/]{3,}$/', $classificationValue)) {
            $digits = preg_replace('/\D+/', '', $classificationValue) ?? '';
            if (preg_match('/^[1-9]\d{5}$/', $digits)) {
                return $this->result(self::UNKNOWN, null, 0.45, $raw, $classificationValue, null, true, true, $unicode);
            }
            if (strlen($digits) >= 5 && strlen($digits) <= 8) {
                return $this->result(self::MEMBERSHIP_NUMBER, 'membership_no', 0.8, $raw, $classificationValue, strtoupper($digits), true, true, $unicode);
            }
        }

        $base = $this->classify($raw);
        if ($isRight && in_array($base['entity_type'], [self::PINCODE, self::PHONE], true)) {
            $base['entity_type'] = self::UNKNOWN;
            $base['crm_field'] = null;
            $base['confidence'] = 0.4;
        }

        return $base;
    }

    public function isSectionHeading(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }
        if ($this->isFirmName($text) || $this->isPerson($text) || $this->isAddress($text)) {
            return false;
        }
        $words = preg_split('/\s+/', trim($text)) ?: [];

        return count($words) <= 2 && (bool) preg_match('/^[A-Z][A-Z\s\-]{1,30}$/', trim($text));
    }

    /**
     * Firm type only when explicit — never guess Partnership/Proprietorship from name shape alone.
     *
     * @param  list<array<string, mixed>>  $members
     */
    public function resolveFirmType(?string $firmName, ?string $caName, array $members, bool $explicitPartnershipEvidence): ?string
    {
        $lower = mb_strtolower((string) $firmName);
        if (str_contains($lower, 'llp')) {
            return 'LLP';
        }
        if (str_contains($lower, 'pvt') || str_contains($lower, 'private limited')) {
            return 'Private Limited';
        }
        if ($explicitPartnershipEvidence && count($members) > 1) {
            return 'Partnership';
        }

        return null;
    }

    public function stripPersonDecorations(string $text): string
    {
        $text = $this->unicode()->classificationValue($text);

        return $this->stripPersonDecorationsInternal($text);
    }

    /**
     * Map classified lines into firm fields (content-based, not line-order).
     *
     * @param  list<string>  $lines
     * @return array{
     *     firm_name: ?string,
     *     ca_name: ?string,
     *     address: ?string,
     *     city: ?string,
     *     state: ?string,
     *     pincode: ?string,
     *     phone: ?string,
     *     email: ?string,
     *     frn: ?string,
     *     gst_no: ?string,
     *     pan_no: ?string,
     *     membership_no: ?string,
     *     partners: list<array{ca_name: string, membership_no: ?string}>,
     *     classifications: list<array<string, mixed>>
     * }
     */
    public function mapLinesToFields(array $lines): array
    {
        $out = [
            'firm_name' => null,
            'ca_name' => null,
            'address' => null,
            'city' => null,
            'state' => null,
            'pincode' => null,
            'phone' => null,
            'email' => null,
            'frn' => null,
            'gst_no' => null,
            'pan_no' => null,
            'membership_no' => null,
            'partners' => [],
            'classifications' => [],
        ];
        $addressParts = [];

        foreach ($lines as $i => $line) {
            $text = trim((string) $line);
            if ($text === '') {
                continue;
            }
            $c = $this->classify($text);
            $c['line_index'] = $i;
            $out['classifications'][] = $c;
            $type = $c['entity_type'];
            $value = $c['normalized'] ?? $text;

            match ($type) {
                self::FIRM_NAME => $out['firm_name'] ??= $value,
                self::PERSON => $this->assignPerson($out, $value, null),
                self::SECTION_HEADING => $out['city'] ??= $value,
                self::ADDRESS => $addressParts[] = $text,
                self::CITY => $out['city'] ??= $value,
                self::STATE => $out['state'] ??= $value,
                self::PINCODE => $out['pincode'] ??= $value,
                self::PHONE => $out['phone'] ??= $value,
                self::EMAIL => $out['email'] ??= $value,
                self::FRN => $out['frn'] ??= $value,
                self::GST => $out['gst_no'] ??= $value,
                self::PAN => $out['pan_no'] ??= $value,
                self::MEMBERSHIP_NUMBER => $out['membership_no'] ??= $value,
                default => null,
            };

            // City-PIN lines like "AMBALA CANTT-133001"
            if ($type === self::UNKNOWN && preg_match('/^(.+?)[\s\-]+([1-9]\d{5})\s*$/u', $text, $m)) {
                $locality = trim($m[1]);
                $pin = $m[2];
                if ($this->isAddress($locality) || $this->isCity($locality)) {
                    $addressParts[] = $locality;
                    $out['pincode'] ??= $pin;
                    $out['classifications'][count($out['classifications']) - 1]['entity_type'] = self::ADDRESS;
                    $out['classifications'][count($out['classifications']) - 1]['crm_field'] = 'address';
                }
            }
        }

        if ($addressParts !== []) {
            $out['address'] = $this->joinAddress($addressParts);
        }
        if ($out['ca_name'] === null && $out['partners'] !== []) {
            $out['ca_name'] = $out['partners'][0]['ca_name'];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $out
     */
    private function assignPerson(array &$out, string $caName, ?string $membership): void
    {
        if ($out['ca_name'] === null) {
            $out['ca_name'] = $caName;
            return;
        }
        foreach ($out['partners'] as $existing) {
            if (mb_strtolower($existing['ca_name']) === mb_strtolower($caName)) {
                return;
            }
        }
        $out['partners'][] = ['ca_name' => $caName, 'membership_no' => $membership];
    }

    public function extractPincode(string $text): ?string
    {
        return $this->extractPincodeValue($text);
    }

    public function isAddress(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }
        $text = $this->unicode()->classificationValue($text);
        $lower = mb_strtolower(trim($text));
        if ($this->isFirmName($text)) {
            return false;
        }

        // House / door / SCO patterns (including OCR greek lookalikes Η ΝΟ).
        if (preg_match('/\b(?:h\.?\s*no\.?|house\s*no\.?|door\s*no\.?|d\.?\s*no\.?|plot\s*no\.?|shop\s*no\.?)\b/iu', $text)) {
            return true;
        }
        if (preg_match('/(?:^|[\s,])(?:Η|H)\s*(?:ΝΟ|NO|N0)\s*[\.\-]?\s*[A-Z0-9]/iu', $text)) {
            return true;
        }
        if (preg_match('/\bsco[\-\s]?\d+/i', $text) || preg_match('/\b\d+(?:st|nd|rd|th)\s+floor\b/i', $text)) {
            return true;
        }
        if (preg_match('/\b(?:shop|plot|flat|house|sco|door)\s*(?:no\.?|number)?\s*[#:]?\s*\d+/i', $text)) {
            return true;
        }
        // Street / locality suffixes — always address inside a firm block (not person names).
        if (preg_match('/\b(?:road|street|sadak|lane|nagar|nagri|colony|sector|mohalla|mandi|estate|cantt|vihar|enclave|chowk|crossing|bypass|marg|gali|hospital|clinic|bank|stand|jail|land|gaon|majri|village|tehsil|ward|backside|post\s*office|temple|school|market)\b/iu', $text)) {
            return true;
        }
        if (preg_match('/\b(?:near|opp\.?|opposite|behind|backside)\b/iu', $text)) {
            return true;
        }

        foreach (self::ADDRESS_KEYWORDS as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/u', $lower)) {
                return true;
            }
        }

        $words = preg_split('/\s+/', $lower) ?: [];
        $localityHits = 0;
        foreach ($words as $word) {
            if (in_array($word, self::LOCALITY_WORDS, true)) {
                $localityHits++;
            }
        }
        if ($localityHits >= 1 && count($words) >= 2) {
            return true;
        }

        return false;
    }

    public function isPerson(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }
        $text = $this->unicode()->classificationValue($text);
        if ($this->isFirmName($text) || $this->isAddress($text)) {
            return false;
        }
        if (preg_match('/^chartered\s+accountants?\.?$/iu', trim($text))) {
            return false;
        }
        if ($this->isCity($text)) {
            return false;
        }
        if (($this->extractPincode($text) || $this->extractGst($text) || $this->extractPan($text))
            && ! preg_match('/\b(?:m\.?\s*no\.?|mem(?:bership)?)\b/i', $text)) {
            return false;
        }

        $clean = preg_replace('/\b(?:m\.?\s*no\.?|mem(?:bership)?\.?\s*no\.?)\s*[:\-]?\s*[A-Z0-9\/\-]+/i', '', $text) ?? $text;
        $clean = preg_replace('/\b[6-9]\d{9}\b/', '', $clean) ?? $clean;
        $clean = preg_replace('/^[\*\•\·\-\–\—\d\.\)]+\s*/u', '', trim($clean)) ?? $clean;
        $clean = trim($clean);
        if ($clean === '') {
            return false;
        }

        $lower = mb_strtolower($clean);
        foreach (self::PERSON_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                $clean = trim(mb_substr($clean, mb_strlen($prefix)));
                break;
            }
        }

        return $clean !== '' && $this->looksLikePersonShape($clean);
    }

    public function isFirmName(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }
        $text = $this->unicode()->classificationValue($text);
        // Care-of / address continuations are not new firm records.
        if ($this->isCareOfLine($text)) {
            return false;
        }
        $lower = mb_strtolower(trim($text));
        // Bare professional designation — never a firm title alone.
        if (preg_match('/^chartered\s+accountants?\.?$/iu', $lower)) {
            return false;
        }
        foreach (self::FIRM_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }

    /** C/O M/S … lines are address/branch pointers, not firm starts. */
    public function isCareOfLine(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }
        $first = trim((preg_split("/\R+/u", (string) $text) ?: [''])[0] ?? '');

        return (bool) preg_match('/^c\/o\b/iu', $first);
    }

    public function isCity(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }
        $text = $this->unicode()->classificationValue($text);
        if ($this->isFirmName($text) || $this->isAddress($text)) {
            return false;
        }
        $words = preg_split('/\s+/', trim($text)) ?: [];
        if (count($words) > 3) {
            return false;
        }
        // Two-word ALL CAPS names are people, not cities.
        if (count($words) === 2 && $this->looksLikePersonShape($text)) {
            return false;
        }
        // Single-token given names (HARSHIL, NEETU) are not cities unless known city master / suffix.
        if (count($words) === 1) {
            if ($this->isKnownCityName($text)) {
                return true;
            }
            $lower = mb_strtolower($text);
            foreach (['road', 'nagar', 'pur', 'bad', 'garh', 'ganj', 'city', 'vihar', 'bagh', 'cantt'] as $token) {
                if (str_ends_with($lower, $token) && mb_strlen($lower) > mb_strlen($token) + 2) {
                    return true;
                }
            }

            return false;
        }
        $lower = mb_strtolower($text);
        foreach (['road', 'nagar', 'pur', 'bad', 'garh', 'ganj', 'city', 'vihar', 'bagh', 'cantt'] as $token) {
            if (str_contains($lower, $token)) {
                return true;
            }
        }

        return (bool) preg_match('/^[A-Z][A-Z\s\-]{2,30}$/', trim($text)) && count($words) <= 2;
    }

    /**
     * Known city master / common directory headings (ABOHAR etc.).
     */
    private function isKnownCityName(string $text): bool
    {
        $needle = mb_strtolower(trim($text));
        if ($needle === '') {
            return false;
        }
        static $fallback = [
            'abohar', 'ambala', 'amritsar', 'ludhiana', 'jalandhar', 'patiala', 'bathinda', 'mohali',
            'pathankot', 'firozpur', 'moga', 'hisar', 'karnal', 'rohtak', 'sonipat', 'panipat',
            'mumbai', 'delhi', 'new delhi', 'chandigarh', 'jaipur', 'ahmedabad', 'bengaluru',
            'chennai', 'kolkata', 'hyderabad', 'pune', 'abhanpur', 'abu road',
        ];
        if (in_array($needle, $fallback, true)) {
            return true;
        }
        try {
            if (function_exists('app') && app()->bound('config')) {
                return \App\Models\City::query()
                    ->whereRaw('LOWER(city_name) = ?', [$needle])
                    ->exists();
            }
        } catch (\Throwable) {
            // Unit tests without DB.
        }

        return false;
    }

    public function crmFieldFor(string $entityType): ?string
    {
        return match ($entityType) {
            self::FIRM_NAME => 'firm_name',
            self::PERSON => 'ca_name',
            self::ADDRESS => 'address',
            self::CITY => 'city',
            self::STATE => 'state',
            self::PINCODE => 'pincode',
            self::PHONE => 'phone',
            self::EMAIL => 'email',
            self::GST => 'gst_no',
            self::PAN => 'pan_no',
            self::FRN => 'frn',
            self::MEMBERSHIP_NUMBER => 'membership_no',
            self::SECTION_HEADING => 'city',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $out
     */
    private function addPartner(array &$out, string $caName, ?string $membership): void
    {
        foreach ($out['partners'] as $existing) {
            if (mb_strtolower($existing['ca_name']) === mb_strtolower($caName)) {
                return;
            }
        }
        $out['partners'][] = ['ca_name' => $caName, 'membership_no' => $membership];
        $out['ca_name'] ??= $caName;
    }

    private function looksLikePersonShape(string $text): bool
    {
        $clean = preg_replace('/\b(m\.?\s*no\.?|mem(?:bership)?\.?\s*no\.?|membership)\s*[:\-]?\s*[A-Z0-9\/\-]+/i', '', $text) ?? $text;
        $clean = preg_replace('/\b[6-9]\d{9}\b/', '', $clean) ?? $clean;
        $clean = trim($clean);
        if ($clean === '') {
            return false;
        }
        $words = preg_split('/\s+/', $clean) ?: [];
        // Keep 2–4 word names here. Single given-names (HARSHIL) are accepted only via
        // firm-linked extractor rules so states/cities are not misclassified as people.
        if (count($words) < 2 || count($words) > 4) {
            return false;
        }
        if (! preg_match('/^[A-Za-z .\'\-]+$/', $clean)) {
            return false;
        }
        foreach ($words as $word) {
            if (in_array(mb_strtolower($word), self::LOCALITY_WORDS, true)) {
                return false;
            }
        }

        return true;
    }

    private function joinAddress(array $parts): string
    {
        return trim(implode(', ', array_unique(array_map('trim', $parts))));
    }

    private function stripPersonDecorationsInternal(string $text): string
    {
        $t = trim($text);
        $t = preg_replace('/^[\*\•\·\-\–\—\d\.\)]+\s*/u', '', $t) ?? $t;
        foreach (self::PERSON_PREFIXES as $prefix) {
            if (str_starts_with(mb_strtolower($t), $prefix)) {
                $t = trim(mb_substr($t, mb_strlen($prefix)));
            }
        }

        return trim($t);
    }

    private function isState(string $text): bool
    {
        $lower = mb_strtolower(trim(preg_replace('/^(state)\s*[:\-]\s*/i', '', $text) ?? $text));
        $states = [
            'andhra pradesh', 'arunachal pradesh', 'assam', 'bihar', 'chhattisgarh', 'goa', 'gujarat',
            'haryana', 'himachal pradesh', 'jharkhand', 'karnataka', 'kerala', 'madhya pradesh',
            'maharashtra', 'manipur', 'meghalaya', 'mizoram', 'nagaland', 'odisha', 'orissa', 'punjab',
            'rajasthan', 'sikkim', 'tamil nadu', 'telangana', 'tripura', 'uttar pradesh', 'uttarakhand',
            'west bengal', 'delhi', 'jammu and kashmir', 'ladakh', 'puducherry', 'chandigarh',
        ];
        foreach ($states as $state) {
            if ($lower === $state || str_starts_with($lower, $state.' ')) {
                return true;
            }
        }

        return false;
    }

    private function extractGst(string $text): ?string
    {
        if (preg_match('/\b([0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z])\b/i', $text, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    private function extractPan(string $text): ?string
    {
        if (preg_match('/\b([A-Z]{5}[0-9]{4}[A-Z])\b/', strtoupper($text), $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractFrn(string $text): ?string
    {
        if (preg_match('/\bfrn\s*[:\-]?\s*([A-Z0-9\/\-]{4,20})\b/i', $text, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    private function extractMembership(string $text): ?string
    {
        if (preg_match('/\b(?:m\.?\s*no\.?|mem(?:bership)?\.?\s*no\.?)\s*[:\-]?\s*([A-Z0-9\/\-]{4,20})\b/i', $text, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    private function extractPincodeValue(string $text): ?string
    {
        if (preg_match('/\b([1-9][0-9]{5})\b/', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractPhone(string $text): ?string
    {
        if (preg_match('/(?<!\d)(?:\+91[\-\s]?|0)?([6-9](?:[\s\-]?\d){9})(?!\d)/u', $text, $m)) {
            $digits = preg_replace('/\D+/', '', $m[1]) ?? '';

            return strlen($digits) === 10 ? $digits : null;
        }

        return null;
    }

    private function extractEmail(string $text): ?string
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m)) {
            return strtolower($m[0]);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function result(
        string $type,
        ?string $crmField,
        float $confidence,
        string $raw,
        string $classificationValue,
        ?string $normalized,
        bool $invalidAsPerson,
        bool $invalidAsPartner,
        ?array $unicode = null,
    ): array {
        $unicode ??= [
            'unicode_normalized' => false,
            'confusable_replaced' => false,
            'unicode_confidence' => 1.0,
            'reason' => null,
            'replacements' => [],
        ];

        return [
            'entity_type' => $type,
            'crm_field' => $crmField,
            'confidence' => $confidence,
            'raw' => $raw,
            'raw_value' => $raw,
            'classification_value' => $classificationValue,
            'normalized' => $normalized,
            'invalid_as_person' => $invalidAsPerson,
            'invalid_as_partner' => $invalidAsPartner,
            'unicode_normalized' => (bool) ($unicode['unicode_normalized'] ?? false),
            'confusable_replaced' => (bool) ($unicode['confusable_replaced'] ?? false),
            'unicode_confidence' => (float) ($unicode['unicode_confidence'] ?? 1.0),
            'unicode_reason' => $unicode['reason'] ?? null,
            'confusable_replacements' => is_array($unicode['replacements'] ?? null) ? $unicode['replacements'] : [],
        ];
    }
}
