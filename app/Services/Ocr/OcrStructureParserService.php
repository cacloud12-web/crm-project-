<?php

namespace App\Services\Ocr;

/**
 * Converts raw Document AI OCR text into structured CA firm / partner records.
 *
 * Pure parsing — no database I/O. Designed for large directory PDFs (hundreds of firms).
 */
class OcrStructureParserService
{
    public const PARSER_VERSION = '1.0.0';

    private const NOISE_EXACT = [
        'preview', 'file', 'edit', 'view', 'go', 'tools', 'window', 'help',
        'home', 'back', 'next', 'search', 'print', 'share', 'download',
        'menu', 'close', 'open', 'save', 'cancel', 'ok', 'yes', 'no',
        'chrome', 'safari', 'firefox', 'edge', 'browser',
    ];

    private const FIRM_MARKERS = [
        '& associates', 'and associates', '& co', '& co.', 'and co', 'and co.',
        'llp', 'pvt ltd', 'pvt. ltd', 'private limited', 'chartered accountant',
        'chartered accountants', '& company', 'and company', 'associates',
        'consultants', 'advisors', 'advisory', '& sons', 'and sons',
    ];

    private const PERSON_PREFIXES = [
        's/o', 'sio', 's.o', 'w/o', 'd/o', 'c/o', 'ca ', 'ca.', 'shri ', 'smt ',
        'mr ', 'mrs ', 'ms ',
    ];

    private const ADDRESS_MARKERS = [
        'shop', 'floor', 'plot', 'sector', 'road', 'street', 'lane', 'nagar',
        'colony', 'complex', 'building', 'tower', 'plaza', 'market', 'industrial',
        'area', 'near', 'opp', 'opposite', 'behind', 'above', 'below', 'wing',
        'block', 'phase', 'village', 'tehsil', 'district', 'dist', 'po ', 'p.o',
    ];

    /** @var list<string> */
    private const INDIAN_STATES = [
        'andhra pradesh', 'arunachal pradesh', 'assam', 'bihar', 'chhattisgarh',
        'goa', 'gujarat', 'haryana', 'himachal pradesh', 'jharkhand', 'karnataka',
        'kerala', 'madhya pradesh', 'maharashtra', 'manipur', 'meghalaya', 'mizoram',
        'nagaland', 'odisha', 'orissa', 'punjab', 'rajasthan', 'sikkim', 'tamil nadu',
        'telangana', 'tripura', 'uttar pradesh', 'uttarakhand', 'west bengal',
        'delhi', 'nct of delhi', 'jammu and kashmir', 'ladakh', 'puducherry',
        'chandigarh', 'andaman and nicobar islands', 'dadra and nagar haveli',
        'daman and diu', 'lakshadweep',
    ];

    /**
     * @return array{
     *   parser_version: string,
     *   firm_count: int,
     *   firms: list<array<string, mixed>>
     * }
     */
    public function parse(string $rawText): array
    {
        $lines = $this->prepareLines($rawText);
        if ($lines === []) {
            return [
                'parser_version' => self::PARSER_VERSION,
                'firm_count' => 0,
                'firms' => [],
            ];
        }

        $blocks = $this->splitIntoFirmBlocks($lines);
        $firms = [];
        $sequence = 1;

        foreach ($blocks as $block) {
            $firm = $this->parseFirmBlock($block, $sequence);
            if ($firm === null) {
                continue;
            }
            $firms[] = $firm;
            $sequence++;
        }

        return [
            'parser_version' => self::PARSER_VERSION,
            'firm_count' => count($firms),
            'firms' => $firms,
        ];
    }

    /**
     * @return list<array{text: string, line_no: int, page: int|null}>
     */
    private function prepareLines(string $rawText): array
    {
        $rawText = str_replace(["\r\n", "\r"], "\n", $rawText);
        $chunks = preg_split("/\n+/", $rawText) ?: [];
        $seen = [];
        $lines = [];
        $lineNo = 0;
        $page = 1;

        foreach ($chunks as $chunk) {
            $text = trim(preg_replace('/\s+/u', ' ', $chunk) ?? '');
            if ($text === '') {
                continue;
            }

            // Form-feed or explicit page markers from some OCR dumps.
            if (preg_match('/^---+ ?page\s*(\d+)\s*---+$/i', $text, $m)) {
                $page = (int) $m[1];
                continue;
            }
            if ($text === "\f" || strtolower($text) === 'page break') {
                $page++;
                continue;
            }

            if ($this->isNoiseLine($text)) {
                continue;
            }

            $key = mb_strtolower($text);
            // Drop immediate duplicate consecutive lines; keep non-consecutive duplicates
            // only when they look like firm names (directories often repeat city headers).
            if (isset($seen[$key]) && ($seen[$key] === $lineNo - 1) && ! $this->looksLikeFirmName($text)) {
                continue;
            }

            $lineNo++;
            $seen[$key] = $lineNo;
            $lines[] = [
                'text' => $text,
                'line_no' => $lineNo,
                'page' => $page,
            ];
        }

        return $lines;
    }

    private function isNoiseLine(string $text): bool
    {
        $lower = mb_strtolower($text);
        if (in_array($lower, self::NOISE_EXACT, true)) {
            return true;
        }

        if (preg_match('/^[\W_]+$/u', $text)) {
            return true;
        }

        if (mb_strlen($text) <= 1) {
            return true;
        }

        // UI chrome / repeated directory headings.
        if (preg_match('/^(list of|directory of|index of|table of contents)\b/i', $text)) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<array{text: string, line_no: int, page: int|null}>  $lines
     * @return list<array{city: ?string, city_meta: ?array, lines: list<array{text: string, line_no: int, page: int|null}>}>
     */
    private function splitIntoFirmBlocks(array $lines): array
    {
        $blocks = [];
        $currentCity = null;
        $currentCityMeta = null;
        $current = null;

        foreach ($lines as $line) {
            $text = $line['text'];

            if ($this->looksLikeCityHeader($text, $line)) {
                // Do not steal partner names (e.g. PIYUSH AGRAWAL) while a firm block is open.
                if ($current !== null && $this->looksLikePersonName($text)) {
                    $current['lines'][] = $line;
                    continue;
                }
                $currentCity = $this->titleCase($text);
                $currentCityMeta = $this->fieldMeta($currentCity, 0.72, $line);
                // City headers alone do not start a firm block.
                continue;
            }

            if ($this->looksLikeFirmName($text)) {
                if ($current !== null && ($current['lines'] !== [] || ! empty($current['firm_hint']))) {
                    $blocks[] = $current;
                }
                $current = [
                    'city' => $currentCity,
                    'city_meta' => $currentCityMeta,
                    'firm_hint' => $text,
                    'firm_hint_line' => $line,
                    'lines' => [],
                ];
                continue;
            }

            if ($current === null) {
                // Orphan content before first firm — start a soft block if it looks useful.
                if ($this->looksLikePersonName($text) || $this->extractPincode($text) || $this->extractGst($text)) {
                    $current = [
                        'city' => $currentCity,
                        'city_meta' => $currentCityMeta,
                        'firm_hint' => null,
                        'firm_hint_line' => null,
                        'lines' => [$line],
                    ];
                }
                continue;
            }

            $current['lines'][] = $line;
        }

        if ($current !== null && ($current['lines'] !== [] || ! empty($current['firm_hint']))) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * @param  array{city: ?string, city_meta: ?array, firm_hint?: ?string, firm_hint_line?: ?array, lines: list<array{text: string, line_no: int, page: int|null}>}  $block
     * @return array<string, mixed>|null
     */
    private function parseFirmBlock(array $block, int $sequence): ?array
    {
        $allLines = $block['lines'];
        $firmName = null;
        $firmNameMeta = null;

        if (! empty($block['firm_hint'])) {
            $firmName = $this->normalizeFirmName((string) $block['firm_hint']);
            $firmNameMeta = $this->fieldMeta(
                $firmName,
                0.92,
                $block['firm_hint_line'] ?? ['line_no' => null, 'page' => null, 'text' => $block['firm_hint']],
            );
        }

        $addressParts = [];
        $addressMetas = [];
        $partners = [];
        $gst = null;
        $gstMeta = null;
        $pan = null;
        $panMeta = null;
        $frn = null;
        $frnMeta = null;
        $pincode = null;
        $pincodeMeta = null;
        $phone = null;
        $phoneMeta = null;
        $email = null;
        $emailMeta = null;
        $website = null;
        $websiteMeta = null;
        $state = null;
        $stateMeta = null;
        $city = $block['city'] ?? null;
        $cityMeta = $block['city_meta'] ?? null;
        $pageNumber = $block['firm_hint_line']['page'] ?? ($allLines[0]['page'] ?? null);

        foreach ($allLines as $line) {
            $text = $line['text'];

            if ($gst === null && ($found = $this->extractGst($text))) {
                $gst = $found;
                $gstMeta = $this->fieldMeta($gst, 0.95, $line);
                continue;
            }

            if ($pan === null && ($found = $this->extractPan($text))) {
                $pan = $found;
                $panMeta = $this->fieldMeta($pan, 0.93, $line);
                continue;
            }

            if ($frn === null && ($found = $this->extractFrn($text))) {
                $frn = $found;
                $frnMeta = $this->fieldMeta($frn, 0.88, $line);
                continue;
            }

            if ($email === null && ($found = $this->extractEmail($text))) {
                $email = $found;
                $emailMeta = $this->fieldMeta($email, 0.96, $line);
            }

            if ($website === null && ($found = $this->extractWebsite($text))) {
                $website = $found;
                $websiteMeta = $this->fieldMeta($website, 0.9, $line);
            }

            if ($phone === null && ($found = $this->extractPhone($text))) {
                $phone = $found;
                $phoneMeta = $this->fieldMeta($phone, 0.9, $line);
            }

            $membershipOnLine = $this->extractMembership($text);

            if ($pincode === null && $membershipOnLine === null && ($found = $this->extractPincode($text))) {
                // Prefer standalone / address PIN codes over membership-like 6-digit tokens.
                $pincode = $found;
                $pincodeMeta = $this->fieldMeta($pincode, 0.94, $line);
                // Remainder of the line may still be address.
                $remainder = trim(preg_replace('/\b[1-9][0-9]{5}\b/', '', $text) ?? '');
                if ($remainder !== '' && ! $this->looksLikePersonName($remainder) && ! $this->looksLikeFirmName($remainder)) {
                    $addressParts[] = $remainder;
                    $addressMetas[] = $this->fieldMeta($remainder, 0.7, $line);
                }
                continue;
            }

            if ($state === null && ($found = $this->extractState($text))) {
                $state = $found;
                $stateMeta = $this->fieldMeta($state, 0.85, $line);
                continue;
            }

            if ($city === null && $this->looksLikeCityHeader($text, $line)) {
                $city = $this->titleCase($text);
                $cityMeta = $this->fieldMeta($city, 0.7, $line);
                continue;
            }

            // Labeled fields.
            if (preg_match('/^(firm\s*name|name of firm)\s*[:\-]\s*(.+)$/i', $text, $m)) {
                $firmName = $this->normalizeFirmName($m[2]);
                $firmNameMeta = $this->fieldMeta($firmName, 0.97, $line);
                continue;
            }

            if (preg_match('/^(city)\s*[:\-]\s*(.+)$/i', $text, $m)) {
                $city = $this->titleCase($m[2]);
                $cityMeta = $this->fieldMeta($city, 0.95, $line);
                continue;
            }

            if (preg_match('/^(state)\s*[:\-]\s*(.+)$/i', $text, $m)) {
                $state = $this->titleCase($m[2]);
                $stateMeta = $this->fieldMeta($state, 0.95, $line);
                continue;
            }

            if ($firmName === null && $this->looksLikeFirmName($text)) {
                $firmName = $this->normalizeFirmName($text);
                $firmNameMeta = $this->fieldMeta($firmName, 0.9, $line);
                continue;
            }

            $membership = $membershipOnLine;
            if ($this->looksLikePersonName($text) || $membership !== null) {
                $caName = $this->normalizePersonName($text);
                if ($caName !== '') {
                    $partners[] = [
                        'ca_name' => $caName,
                        'membership_no' => $membership,
                        'mobile' => $this->extractPhone($text),
                        'email' => $this->extractEmail($text),
                        'role' => $this->inferMemberRole($text, count($partners)),
                        'field_meta' => [
                            'ca_name' => $this->fieldMeta($caName, 0.86, $line),
                            'membership_no' => $membership
                                ? $this->fieldMeta($membership, 0.9, $line)
                                : null,
                        ],
                        'overall_confidence' => $membership ? 0.88 : 0.78,
                    ];
                }
                continue;
            }

            if ($this->looksLikeAddressLine($text)) {
                $addressParts[] = $this->titleCase($text);
                $addressMetas[] = $this->fieldMeta($this->titleCase($text), 0.75, $line);
                continue;
            }

            // Soft address fallback: short leftover lines that are not firm/person.
            if (! $this->looksLikeFirmName($text) && ! $this->looksLikePersonName($text) && mb_strlen($text) <= 80) {
                $addressParts[] = $this->titleCase($text);
                $addressMetas[] = $this->fieldMeta($this->titleCase($text), 0.55, $line);
            }
        }

        if ($firmName === null || $firmName === '') {
            // Cannot produce a firm without a name.
            if ($partners === [] && $gst === null && $addressParts === []) {
                return null;
            }
            $firmName = $partners[0]['ca_name'] ?? 'Unknown Firm';
            $firmNameMeta = $this->fieldMeta($firmName, 0.4, ['line_no' => null, 'page' => $pageNumber, 'text' => $firmName]);
        }

        $address = $addressParts !== []
            ? $this->normalizeAddress(implode(', ', array_unique($addressParts)))
            : null;

        $firmType = $this->inferFirmType($firmName, $partners);

        $fieldMeta = array_filter([
            'firm_name' => $firmNameMeta,
            'firm_type' => $firmType ? $this->fieldMeta($firmType, 0.7, $block['firm_hint_line'] ?? ['line_no' => null, 'page' => $pageNumber, 'text' => '']) : null,
            'frn' => $frnMeta,
            'gst_no' => $gstMeta,
            'pan_no' => $panMeta,
            'address' => $address ? ($addressMetas[0] ?? $this->fieldMeta($address, 0.6, ['line_no' => null, 'page' => $pageNumber, 'text' => $address])) : null,
            'city' => $cityMeta,
            'state' => $stateMeta,
            'pincode' => $pincodeMeta,
            'phone' => $phoneMeta,
            'email' => $emailMeta,
            'website' => $websiteMeta,
        ]);

        $scores = array_values(array_filter(array_map(
            static fn ($meta) => is_array($meta) ? (float) ($meta['confidence'] ?? 0) : null,
            $fieldMeta,
        )));
        $overall = $scores !== [] ? round(array_sum($scores) / count($scores), 4) : 0.5;

        $normalizedPartners = [];
        foreach ($partners as $i => $partner) {
            $normalizedPartners[] = [
                'sequence_no' => $i + 1,
                'ca_name' => $partner['ca_name'],
                'membership_no' => $partner['membership_no'],
                'mobile' => $partner['mobile'],
                'email' => $partner['email'],
                'role' => $partner['role'],
                'overall_confidence' => $partner['overall_confidence'],
                'field_meta' => $partner['field_meta'],
            ];
        }

        return [
            'sequence_no' => $sequence,
            'firm_name' => $firmName,
            'firm_type' => $firmType,
            'frn' => $frn,
            'gst_no' => $gst,
            'pan_no' => $pan,
            'address' => $address,
            'city' => $city,
            'state' => $state,
            'pincode' => $pincode,
            'phone' => $phone,
            'email' => $email,
            'website' => $website,
            'review_status' => 'pending',
            'overall_confidence' => $overall,
            'page_number' => $pageNumber,
            'field_meta' => $fieldMeta,
            'members' => $normalizedPartners,
        ];
    }

    private function looksLikeFirmName(string $text): bool
    {
        $lower = mb_strtolower($text);

        if (preg_match('/^(firm\s*name|name of firm)\s*[:\-]/i', $text)) {
            return true;
        }

        foreach (self::FIRM_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                // Avoid treating pure address "industrial area" as firm.
                if ($marker === 'area' || $marker === 'associates') {
                    // "associates" alone as whole word at end is ok; mid-address "area" alone is not a firm marker used alone.
                }
                if ($marker === 'area') {
                    continue;
                }
                return true;
            }
        }

        // "&" between name tokens: "Agrawal & Shah"
        if (preg_match('/\b[A-Z][A-Za-z.]+\s+&\s+[A-Z][A-Za-z.]+\b/', $text)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array{text: string, line_no: int, page: int|null}  $line
     */
    private function looksLikeCityHeader(string $text, array $line): bool
    {
        if (preg_match('/^(city)\s*[:\-]/i', $text)) {
            return true;
        }

        // PIN / GST / email / phone lines are not cities.
        if ($this->extractPincode($text) || $this->extractGst($text) || $this->extractEmail($text) || $this->extractPhone($text)) {
            return false;
        }

        if ($this->looksLikeFirmName($text)) {
            return false;
        }

        $words = preg_split('/\s+/', $text) ?: [];
        if (count($words) > 3 || mb_strlen($text) > 40) {
            return false;
        }

        $isAllCaps = $text === mb_strtoupper($text) && (bool) preg_match('/^[A-Z0-9 .\'\-]+$/', $text);

        // Directory city headers are often ALL CAPS (ABHANPUR, ABU ROAD). Allow mild
        // place words like ROAD / NAGAR here; reject detailed address markers.
        $lower = mb_strtolower($text);
        $strongAddress = false;
        foreach (['shop', 'floor', 'plot', 'sector', 'industrial', 'complex', 'building', 'colony', 'phase', 'wing', 'block'] as $marker) {
            if (str_contains($lower, $marker)) {
                $strongAddress = true;
                break;
            }
        }
        if ($strongAddress) {
            return false;
        }

        // Place-like tokens strongly indicate a city/locality header.
        $placeTokens = ['road', 'nagar', 'pur', 'bad', 'garh', 'ganj', 'city', 'vihar', 'bagh', 'pete', 'halli'];
        $hasPlaceToken = false;
        foreach ($placeTokens as $token) {
            if (str_contains($lower, $token)) {
                $hasPlaceToken = true;
                break;
            }
        }

        // Two-word ALL CAPS without place tokens is often a person name (PIYUSH AGRAWAL).
        if ($isAllCaps && count($words) === 2 && ! $hasPlaceToken) {
            return false;
        }

        if (! $isAllCaps && ! preg_match('/^[A-Z][a-z]+(?:\s+[A-Z][a-z]+){0,2}$/', $text)) {
            if (! preg_match('/^[A-Za-z .\'\-]+$/', $text)) {
                return false;
            }
            if (count($words) > 2) {
                return false;
            }
        }

        if ($this->extractState($text)) {
            return false;
        }

        // City headers in directories are usually 1–2 words (ABHANPUR, ABU ROAD).
        return count($words) <= 2 || ($isAllCaps && count($words) <= 3);
    }

    private function looksLikePersonName(string $text): bool
    {
        $lower = mb_strtolower(trim($text));

        foreach (self::PERSON_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        if ($this->looksLikeFirmName($text) || $this->looksLikeAddressLine($text)) {
            return false;
        }

        if ($this->extractPincode($text) || $this->extractGst($text) || $this->extractPan($text)) {
            return false;
        }

        // Strip membership / phone crumbs for shape check.
        $clean = preg_replace('/\b(m\.?\s*no\.?|mem(?:bership)?\.?\s*no\.?|membership)\s*[:\-]?\s*[A-Z0-9\/\-]+/i', '', $text) ?? $text;
        $clean = preg_replace('/\b[6-9]\d{9}\b/', '', $clean) ?? $clean;
        $clean = trim($clean);
        if ($clean === '') {
            return false;
        }

        $words = preg_split('/\s+/', $clean) ?: [];
        if (count($words) < 2 || count($words) > 5) {
            return false;
        }

        if (! preg_match('/^[A-Za-z .\'\-]+$/', $clean)) {
            return false;
        }

        // Avoid pure address tokens.
        foreach (self::ADDRESS_MARKERS as $marker) {
            if (str_contains(mb_strtolower($clean), $marker)) {
                return false;
            }
        }

        return true;
    }

    private function looksLikeAddressLine(string $text): bool
    {
        $lower = mb_strtolower($text);
        foreach (self::ADDRESS_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        if (preg_match('/\b(no\.?|number)\s*\d+/i', $text)) {
            return true;
        }

        return (bool) preg_match('/^\d+[A-Za-z]?\b/', $text);
    }

    private function extractGst(string $text): ?string
    {
        if (preg_match('/\b([0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z])\b/i', $text, $m)) {
            return strtoupper($m[1]);
        }

        if (preg_match('/\bgst(?:in| no\.?| number)?\s*[:\-]?\s*([0-9A-Z]{15})\b/i', $text, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    private function extractPan(string $text): ?string
    {
        if (preg_match('/\b([A-Z]{5}[0-9]{4}[A-Z])\b/', strtoupper($text), $m)) {
            // Avoid matching fragments inside GST (GST contains a PAN-like middle).
            if ($this->extractGst($text) && str_contains(strtoupper($text), $m[1])) {
                $gst = $this->extractGst($text);
                if ($gst && str_contains($gst, $m[1])) {
                    // Still allow explicit PAN label.
                    if (! preg_match('/\bpan\b/i', $text)) {
                        return null;
                    }
                }
            }

            return $m[1];
        }

        return null;
    }

    private function extractFrn(string $text): ?string
    {
        if (preg_match('/\bfrn\s*[:\-]?\s*([A-Z0-9\/\-]{4,20})\b/i', $text, $m)) {
            return strtoupper($m[1]);
        }

        if (preg_match('/\bfirm\s*reg(?:istration)?\s*(?:no|number)?\s*[:\-]?\s*([A-Z0-9\/\-]{4,20})\b/i', $text, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    private function extractMembership(string $text): ?string
    {
        if (preg_match('/\b(?:m\.?\s*no\.?|mem(?:bership)?\.?\s*no\.?|membership(?:\s*no\.?)?)\s*[:\-]?\s*([A-Z0-9\/\-]{4,20})\b/i', $text, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    private function extractPincode(string $text): ?string
    {
        if (preg_match('/\b([1-9][0-9]{5})\b/', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractPhone(string $text): ?string
    {
        if (preg_match('/\b(?:\+91[\-\s]?)?([6-9]\d{9})\b/', $text, $m)) {
            return $m[1];
        }

        if (preg_match('/\b(\d{3,5}[\-\s]\d{6,8})\b/', $text, $m)) {
            return preg_replace('/\s+/', '-', $m[1]) ?? $m[1];
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

    private function extractWebsite(string $text): ?string
    {
        if (preg_match('#(?:https?://)?(?:www\.)?([a-z0-9\-]+(?:\.[a-z0-9\-]+)+(?:/[^\s]*)?)#i', $text, $m)) {
            $host = strtolower(rtrim($m[1], '/.'));
            // Skip email domains accidentally caught? Already handled by email extractor first.
            if (str_contains($host, '@')) {
                return null;
            }
            // Require a plausible TLD length.
            if (! preg_match('/\.[a-z]{2,}$/i', $host)) {
                return null;
            }

            return $host;
        }

        return null;
    }

    private function extractState(string $text): ?string
    {
        $lower = mb_strtolower(trim($text));
        $lower = preg_replace('/^(state)\s*[:\-]\s*/i', '', $lower) ?? $lower;

        foreach (self::INDIAN_STATES as $state) {
            if ($lower === $state || str_starts_with($lower, $state.' ')) {
                return $this->titleCase($state);
            }
        }

        return null;
    }

    private function inferFirmType(string $firmName, array $partners): ?string
    {
        $lower = mb_strtolower($firmName);
        if (str_contains($lower, 'llp')) {
            return 'LLP';
        }
        if (str_contains($lower, 'pvt') || str_contains($lower, 'private limited')) {
            return 'Private Limited';
        }
        if (str_contains($lower, 'associates') || str_contains($lower, '& co') || str_contains($lower, 'and co')) {
            return count($partners) <= 1 ? 'Partnership' : 'Partnership';
        }
        if (count($partners) === 1) {
            return 'Proprietorship';
        }
        if (count($partners) > 1) {
            return 'Partnership';
        }

        return null;
    }

    private function inferMemberRole(string $text, int $index): string
    {
        $lower = mb_strtolower($text);
        if (str_contains($lower, 'proprietor')) {
            return 'Proprietor';
        }
        if (str_contains($lower, 'partner')) {
            return 'Partner';
        }
        if (str_contains($lower, 'branch')) {
            return 'Branch';
        }

        return $index === 0 ? 'Partner' : 'Partner';
    }

    private function normalizeFirmName(string $text): string
    {
        $text = preg_replace('/^(firm\s*name|name of firm)\s*[:\-]\s*/i', '', $text) ?? $text;

        return $this->titleCase(trim($text));
    }

    private function normalizePersonName(string $text): string
    {
        $text = preg_replace('/^(s\/o|sio|s\.o|w\/o|d\/o|c\/o|ca\.?|shri|smt|mr\.?|mrs\.?|ms\.?)\s+/i', '', $text) ?? $text;
        $text = preg_replace('/\b(?:m\.?\s*no\.?|mem(?:bership)?\.?\s*no\.?|membership)\s*[:\-]?\s*[A-Z0-9\/\-]+/i', '', $text) ?? $text;
        $text = preg_replace('/\b(?:\+91[\-\s]?)?[6-9]\d{9}\b/', '', $text) ?? $text;
        $text = trim($text, " \t-,.");

        return $this->titleCase($text);
    }

    private function normalizeAddress(string $text): string
    {
        $text = preg_replace('/\s+,/', ',', $text) ?? $text;
        $text = preg_replace('/,\s*,+/', ',', $text) ?? $text;

        return trim($text, " \t,");
    }

    private function titleCase(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        // Preserve small connectors.
        $parts = preg_split('/(\s+)/', mb_strtolower($text), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = '';
        foreach ($parts as $part) {
            if (preg_match('/^\s+$/', $part)) {
                $out .= $part;
                continue;
            }
            if (in_array($part, ['and', 'of', 'the', '&'], true)) {
                $out .= $part === '&' ? '&' : $part;
                continue;
            }
            if ($part === 'llp' || $part === 'ca') {
                $out .= mb_strtoupper($part);
                continue;
            }
            $out .= mb_strtoupper(mb_substr($part, 0, 1)).mb_substr($part, 1);
        }

        // Fix "& Co" style.
        $out = preg_replace('/\bCo\b/', 'Co', $out) ?? $out;

        return $out;
    }

    /**
     * @param  array{text?: string, line_no?: int|null, page?: int|null}  $line
     * @return array{value: string, confidence: float, source_line: int|null, page_number: int|null, source_text: string}
     */
    private function fieldMeta(string $value, float $confidence, array $line): array
    {
        return [
            'value' => $value,
            'confidence' => round(max(0, min(1, $confidence)), 4),
            'source_line' => $line['line_no'] ?? null,
            'page_number' => $line['page'] ?? null,
            'source_text' => $line['text'] ?? $value,
        ];
    }
}
