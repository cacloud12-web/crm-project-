<?php

namespace App\Services\Ocr;

/**
 * Partnership-directory extractor for PART PDFs.
 *
 * Output contract:
 * - firm_name
 * - city (active section heading)
 * - ca_name = first clearly associated validated person (primary/lead)
 * - partners = remaining unique validated persons (primary NOT duplicated)
 *
 * Address region ends person collection. City/address never become persons.
 */
class OcrPartnershipDirectoryExtractor
{
    public function __construct(
        private readonly ?OcrEntityClassificationService $classifier = null,
        private readonly ?OcrHumanNameClassifier $humanNames = null,
        private readonly ?OcrCityHeadingDetector $cityHeadings = null,
        private readonly ?OcrUnicodeNormalizationService $unicode = null,
    ) {}

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

        $entities = $this->classifier ?? new OcrEntityClassificationService;
        $humans = $this->humanNames ?? new OcrHumanNameClassifier($entities);
        $headings = $this->cityHeadings ?? new OcrCityHeadingDetector($entities);
        $unicode = $this->unicode ?? new OcrUnicodeNormalizationService;

        // Document AI often merges firm + partners into one paragraph (newlines or spaces).
        $tokens = $this->expandMergedFirmPersonTokens($tokens, $entities, $humans);

        $sectionCity = trim((string) ($context['section_city'] ?? ''));
        if ($sectionCity === '') {
            $sectionCity = null;
        }

        $firmName = null;
        $rawFirmName = null;
        $firmToken = null;
        /** @var list<array{name: string, raw_name: string, token: array<string, mixed>}> $persons */
        $persons = [];
        $seenKeys = [];
        $inAddress = false;
        $city = $sectionCity;
        $rawCity = $sectionCity;

        foreach ($tokens as $token) {
            $raw = trim((string) ($token['text'] ?? ''));
            if ($raw === '') {
                continue;
            }
            $text = $unicode->classificationValue($raw);

            // City headings only before/outside the firm title — never mid-block (wrapped surnames like JOSHI).
            if ($firmName === null && $headings->isHeading($text) && ! $entities->isFirmName($text)) {
                $detected = $headings->detect($text, $token);
                $city = $detected['city'] ?? $text;
                $rawCity = $detected['raw_city'] ?? $text;
                continue;
            }

            if ($firmName === null) {
                $candidate = $this->stripInlineCode($text);
                if ($entities->isFirmName($candidate) && ! $entities->isAddressShape($candidate)) {
                    $firmName = $this->stripFirmDecorations($candidate);
                    $rawFirmName = $this->stripFirmDecorations($this->stripInlineCode($raw));
                    $firmToken = $token;
                }
                continue;
            }

            if ($inAddress) {
                continue;
            }

            if ($entities->isAddressShape($text) || $this->looksLikeAddressTransition($text)) {
                $inAddress = true;
                continue;
            }

            if ($entities->isFirmName($text)) {
                break;
            }

            if (! $humans->isValid($text, $firmName, $city)) {
                // Strip bullet / star prefixes common in partnership directories.
                $stripped = trim((string) preg_replace('/^[\*\•\·\-–—]+\s*/u', '', $text));
                if ($stripped === $text || ! $humans->isValid($stripped, $firmName, $city)) {
                    continue;
                }
                $text = $stripped;
                $raw = trim((string) preg_replace('/^[\*\•\·\-–—]+\s*/u', '', $raw));
            }

            if ($persons !== [] && $this->shouldMergeWrappedName($persons[array_key_last($persons)], $text, $token, $humans, $firmName, $city)) {
                $last = &$persons[array_key_last($persons)];
                $merged = trim($last['name'].' '.$text);
                unset($seenKeys[mb_strtolower($last['name'])]);
                $last['name'] = $merged;
                $last['raw_name'] = trim($last['raw_name'].' '.$raw);
                $last['token'] = $token;
                $seenKeys[mb_strtolower($merged)] = true;
                unset($last);
                continue;
            }

            $key = mb_strtolower($text);
            if (isset($seenKeys[$key])) {
                continue;
            }
            $seenKeys[$key] = true;
            $persons[] = [
                'name' => $text,
                'raw_name' => $raw,
                'token' => $token,
            ];
        }

        if ($firmName === null || trim($firmName) === '') {
            return null;
        }

        if ($city !== null) {
            $resolved = (new OcrCityResolverService)->canonical((string) $city);
            if ($resolved !== null) {
                $city = $resolved;
            }
        }

        $caName = $persons[0]['name'] ?? null;
        $rawCaName = $persons[0]['raw_name'] ?? null;
        $partners = [];
        $members = [];
        foreach ($persons as $i => $person) {
            if ($i === 0) {
                $members[] = [
                    'sequence_no' => 1,
                    'ca_name' => $person['name'],
                    'raw_ca_name' => $person['raw_name'],
                    'role' => 'Partner',
                    'is_primary' => true,
                    'overall_confidence' => 0.9,
                    'page_number' => $person['token']['page'] ?? ($context['page'] ?? null),
                    'source_fingerprint' => hash('sha256', mb_strtolower($firmName).'|'.mb_strtolower($person['name'])),
                ];
                continue;
            }
            $partners[] = $person['name'];
            $members[] = [
                'sequence_no' => $i + 1,
                'ca_name' => $person['name'],
                'raw_ca_name' => $person['raw_name'],
                'role' => 'Partner',
                'is_primary' => false,
                'overall_confidence' => 0.86,
                'page_number' => $person['token']['page'] ?? ($context['page'] ?? null),
                'source_fingerprint' => hash('sha256', mb_strtolower($firmName).'|'.mb_strtolower($person['name'])),
            ];
        }

        $missing = [];
        if ($city === null || trim((string) $city) === '') {
            $missing[] = 'city';
        }
        if ($caName === null && $partners === []) {
            $missing[] = 'ca_name';
        }

        $page = $context['page'] ?? ($firmToken['page'] ?? null);
        $column = $context['column'] ?? ($firmToken['column'] ?? null);
        $bboxKey = sprintf(
            '%.3f,%.3f,%.3f,%.3f',
            (float) ($firmToken['x_min'] ?? 0),
            (float) ($firmToken['y_min'] ?? 0),
            (float) ($firmToken['x_max'] ?? 0),
            (float) ($firmToken['y_max'] ?? 0),
        );
        $sourceFingerprint = hash('sha256', implode('|', [
            (string) ($context['document_id'] ?? ''),
            (string) ($context['parse_run_id'] ?? ''),
            (string) $page,
            (string) $column,
            $bboxKey,
            mb_strtolower($firmName),
        ]));

        return [
            'sequence_no' => (int) ($context['sequence_no'] ?? 1),
            'firm_name' => $firmName,
            'raw_firm_name' => $rawFirmName ?? $firmName,
            'ca_name' => $caName,
            'raw_ca_name' => $rawCaName,
            'city' => $city,
            'raw_city' => $rawCity ?? $city,
            'partners' => $partners,
            'partner_count' => count($partners),
            'members' => $members,
            'firm_type' => 'Partnership',
            'ca_role' => $caName !== null ? 'Partner' : null,
            'page_number' => $page,
            'column_number' => $column,
            'missing_required_fields' => $missing,
            'extraction_source' => 'partnership_directory',
            'directory_profile' => OcrDirectoryProfileDetector::PARTNERSHIP,
            'source_fingerprint' => $sourceFingerprint,
            'row_merge_suspected' => (bool) ($context['row_merge_suspected'] ?? false),
            'row_merge_evidence' => $context['row_merge_evidence'] ?? [],
            'row_split_suspected' => (bool) ($context['row_split_suspected'] ?? false),
            'overall_confidence' => 0.88,
        ];
    }

    /**
     * @param  array{name: string, raw_name: string, token: array<string, mixed>}  $prev
     * @param  array<string, mixed>  $token
     */
    private function shouldMergeWrappedName(
        array $prev,
        string $text,
        array $token,
        OcrHumanNameClassifier $humans,
        ?string $firmName,
        ?string $city,
    ): bool {
        $prevWords = preg_split('/\s+/u', trim($prev['name'])) ?: [];
        $curWords = preg_split('/\s+/u', trim($text)) ?: [];
        if (count($curWords) !== 1 || count($prevWords) > 3 || count($prevWords) < 1) {
            return false;
        }
        $prevY = (float) ($prev['token']['y_max'] ?? $prev['token']['y_center'] ?? 0);
        $curY = (float) ($token['y_min'] ?? $token['y_center'] ?? 0);
        if (($curY - $prevY) > 0.035) {
            return false;
        }
        $combined = trim($prev['name'].' '.$text);

        return $humans->isValid($combined, $firmName, $city);
    }

    private function looksLikeAddressTransition(string $text): bool
    {
        $t = trim($text);
        // Membership / FRN codes (005376, 329018E) must not end partner collection.
        if (preg_match('/^\d{5,6}[A-Z]?$/i', $t)) {
            return false;
        }
        if (preg_match('/\b(?:pin\s*code|pincode|house\s*no|door\s*no|d\.?\s*no|shop\s*no|plot\s*no|at\s+post|via\s+)\b/iu', $t)) {
            return true;
        }
        // City/locality with trailing PIN: SALTLAKE-700064 / BLOCK A-700089
        if (preg_match('/[A-Za-z].*[-–]\s*[1-9]\d{5}\b/u', $t)) {
            return true;
        }
        // Standalone PIN only when labeled or embedded in longer address text.
        if (preg_match('/\b[1-9]\d{5}\b/u', $t) && preg_match('/[A-Za-z]/u', $t)
            && preg_match('/\b(?:road|street|floor|block|sector|nagar|colony|market|apartment|building|near|opp|lake|town|estate)\b/iu', $t)) {
            return true;
        }

        return false;
    }

    /**
     * Document AI often returns firm + partners as one paragraph (newlines or spaces).
     *
     * @param  list<array<string, mixed>>  $tokens
     * @return list<array<string, mixed>>
     */
    private function expandMergedFirmPersonTokens(
        array $tokens,
        OcrEntityClassificationService $entities,
        OcrHumanNameClassifier $humans,
    ): array {
        $out = [];
        foreach ($tokens as $token) {
            $text = trim((string) ($token['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $lineParts = preg_split("/\R+/u", $text) ?: [$text];
            $lineParts = array_values(array_filter(array_map('trim', $lineParts), static fn (string $p) => $p !== ''));

            // Multiline: firm on line 1, partners on following lines.
            if (count($lineParts) > 1 && $entities->isFirmName($lineParts[0])) {
                foreach ($this->cloneTokenParts($token, $lineParts) as $part) {
                    $out[] = $part;
                }
                continue;
            }

            // Single-line glue: "AGRAWAL GOYAL & CO VISHNU BABOO AGRAWAL PRADEEP…"
            $split = $this->splitFirmAndTrailingPersons($text, $entities, $humans);
            if ($split !== null) {
                foreach ($this->cloneTokenParts($token, $split) as $part) {
                    $out[] = $part;
                }
                continue;
            }

            $out[] = $token;
        }

        return $out;
    }

    /**
     * @return list<string>|null  [firm, person, person, …]
     */
    private function splitFirmAndTrailingPersons(
        string $text,
        OcrEntityClassificationService $entities,
        OcrHumanNameClassifier $humans,
    ): ?array {
        if (! $entities->isFirmName($text) || ! preg_match('/\s+/u', $text)) {
            return null;
        }
        if (! preg_match(
            '/^(.+?(?:\bassociates\b|\bllp\b|(?:&\s*|and\s+)co\.?\b|(?:&\s*|and\s+)associates\b|\bcompany\b|\bchartered\s+accountants\b))\s+(.+)$/iu',
            $text,
            $m,
        )) {
            return null;
        }
        $firm = trim($m[1]);
        $rest = trim($m[2]);
        if (! $entities->isFirmName($firm) || $rest === '') {
            return null;
        }
        $persons = $this->chunkTrailingPersonNames($rest, $humans, $firm);
        if ($persons === []) {
            return null;
        }

        return array_merge([$firm], $persons);
    }

    /**
     * @return list<string>
     */
    private function chunkTrailingPersonNames(string $rest, OcrHumanNameClassifier $humans, string $firmName): array
    {
        $words = preg_split('/\s+/u', trim($rest)) ?: [];
        $persons = [];
        $i = 0;
        $n = count($words);
        while ($i < $n) {
            $matched = false;
            foreach ([3, 2, 4, 1] as $len) {
                if ($i + $len > $n) {
                    continue;
                }
                $candidate = trim(implode(' ', array_slice($words, $i, $len)));
                if ($humans->isValid($candidate, $firmName, null)) {
                    $persons[] = $candidate;
                    $i += $len;
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                $i++;
            }
        }

        return $persons;
    }

    /**
     * @param  list<string>  $parts
     * @return list<array<string, mixed>>
     */
    private function cloneTokenParts(array $token, array $parts): array
    {
        $yMin = isset($token['y_min']) ? (float) $token['y_min'] : 0.0;
        $yMax = isset($token['y_max']) ? (float) $token['y_max'] : ($yMin + 0.02 * count($parts));
        $span = max(0.004, ($yMax - $yMin) / max(1, count($parts)));
        $out = [];
        foreach ($parts as $i => $part) {
            $clone = $token;
            $clone['text'] = $part;
            $clone['y_min'] = $yMin + ($i * $span);
            $clone['y_max'] = $yMin + (($i + 1) * $span);
            $clone['y_center'] = ($clone['y_min'] + $clone['y_max']) / 2;
            $out[] = $clone;
        }

        return $out;
    }

    private function stripInlineCode(string $text): string
    {
        if (preg_match('/^(.+?)\s*[-–]\s*\d{5,6}[A-Z]?\s*$/i', $text, $m)) {
            return trim($m[1]);
        }

        return $text;
    }

    private function stripFirmDecorations(string $name): string
    {
        $name = trim((string) preg_replace('/^PROP(?:RIETOR)?\.?\s+/iu', '', $name));
        $name = trim((string) preg_replace('/^M\/?S\.?\s+/iu', '', $name));

        return trim($name);
    }
}
