<?php

namespace App\Services\Ocr;

/**
 * Converts raw Document AI OCR text into structured CA firm / partner records.
 *
 * Pure parsing — no database I/O. Designed for large directory PDFs (hundreds of firms).
 */
class OcrStructureParserService
{
    public const PARSER_VERSION = '2.2.0';

    public function __construct(
        private readonly ?OcrSpreadsheetTableParser $spreadsheetParser = null,
        private readonly ?OcrEntityClassificationService $entityClassifier = null,
        private readonly ?OcrDirectoryRecordParser $recordParser = null,
        private readonly ?OcrCityHeadingDetector $cityHeadingDetector = null,
    ) {}

    private function spreadsheet(): OcrSpreadsheetTableParser
    {
        return $this->spreadsheetParser ?? new OcrSpreadsheetTableParser($this->entities());
    }

    private function entities(): OcrEntityClassificationService
    {
        return $this->entityClassifier ?? new OcrEntityClassificationService;
    }

    private function cityHeadings(): OcrCityHeadingDetector
    {
        return $this->cityHeadingDetector ?? new OcrCityHeadingDetector($this->entities());
    }

    private const NOISE_EXACT = [
        'preview', 'file', 'edit', 'view', 'go', 'tools', 'window', 'help',
        'home', 'back', 'next', 'search', 'print', 'share', 'download',
        'menu', 'close', 'open', 'save', 'cancel', 'ok', 'yes', 'no',
        'chrome', 'safari', 'firefox', 'edge', 'browser',
    ];

    private const FIRM_MARKERS = [
        '& associates', 'and associates', '& co.', '& co',
        'llp', 'pvt ltd', 'pvt. ltd', 'private limited',
        'chartered accountants', 'chartered accountant',
        '& company', 'and company', '& sons', 'and sons',
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
     * @param  array<string, mixed>|null  $layoutMetadata  Optional Document AI structured_data (pages/paragraphs).
     * @return array<string, mixed>
     */
    public function parse(string $rawText, ?array $layoutMetadata = null): array
    {
        $rawText = str_replace(["\r\n", "\r"], "\n", $rawText);

        // Spreadsheet / table PDFs: one serial+date anchor → one firm (never drop rows).
        if (trim($rawText) !== '' && $this->spreadsheet()->looksLikeSpreadsheet($rawText)) {
            $sheet = $this->spreadsheet()->parse($rawText);
            $firms = $sheet['firms'];
            $uniquePhones = [];
            $duplicateFirms = [];
            foreach ($firms as $firm) {
                $phone = preg_replace('/\D+/', '', (string) ($firm['phone'] ?? '')) ?: null;
                $key = mb_strtolower(trim((string) ($firm['firm_name'] ?? ''))).'|'.($phone ?? '');
                if ($phone && isset($uniquePhones[$phone])) {
                    $duplicateFirms[] = [
                        'firm_name' => $firm['firm_name'] ?? null,
                        'phone' => $phone,
                        'row_serial' => $firm['row_serial'] ?? null,
                    ];
                }
                if ($phone) {
                    $uniquePhones[$phone] = true;
                }
            }

            return [
                'parser_version' => self::PARSER_VERSION,
                'parse_mode' => 'spreadsheet_table',
                'strategy' => 'spreadsheet_table',
                'firm_count' => count($firms),
                'heading_count' => (int) ($sheet['rows_detected'] ?? count($firms)),
                'rows_detected' => (int) ($sheet['rows_detected'] ?? count($firms)),
                'skipped_blocks' => count($sheet['skipped'] ?? []),
                'skipped_details' => $sheet['skipped'] ?? [],
                'missing_serials' => $sheet['missing_serials'] ?? [],
                'duplicate_serials' => $sheet['duplicate_serials'] ?? [],
                'duplicate_firms' => $duplicateFirms,
                'unique_firm_estimate' => count($firms) - count($duplicateFirms),
                'firms' => $firms,
            ];
        }

        $strategy = 'line_based';
        $sourceText = $rawText;
        $layoutText = $this->rebuildTextFromLayout($layoutMetadata);
        if ($layoutText !== null && trim($layoutText) !== '') {
            $sourceText = $layoutText;
            $strategy = 'layout_aware';
        }

        if (trim($sourceText) === '' && trim($rawText) === '') {
            return array_merge($this->emptyResult('empty_ocr_text'), [
                'strategy' => 'empty',
                'candidate_firm_count' => 0,
            ]);
        }

        $lines = $this->prepareLines($sourceText);
        if ($lines === [] && trim($rawText) !== '') {
            $lines = $this->prepareLines($rawText);
            $strategy = 'line_based_fallback';
        }
        if ($lines === []) {
            return array_merge($this->emptyResult($strategy === 'layout_aware' ? 'layout_empty' : 'no_content_lines_after_noise_filter'), [
                'strategy' => $strategy === 'layout_aware' ? 'layout_empty' : 'empty',
                'candidate_firm_count' => 0,
            ]);
        }

        $pageStats = $this->pageStats($lines);
        $blocks = $this->splitIntoFirmBlocks($lines);
        $firms = [];
        $sequence = 1;
        $skippedBlocks = 0;
        $skippedDetails = [];
        $headingCount = count($blocks);

        foreach ($blocks as $block) {
            $firm = $this->parseFirmBlock($block, $sequence);
            if ($firm === null) {
                $skippedBlocks++;
                $hint = (string) ($block['firm_hint'] ?? '');
                $skippedDetails[] = [
                    'serial' => null,
                    'reason' => 'empty_firm_block_no_identity',
                    'snippet' => mb_substr($hint !== '' ? $hint : ($block['lines'][0]['text'] ?? ''), 0, 80),
                ];
                continue;
            }
            $firms[] = $firm;
            $sequence++;
        }

        if ($firms === [] && $strategy === 'layout_aware') {
            $fallbackLines = $this->prepareLines($rawText);
            $fallbackBlocks = $this->splitIntoFirmBlocks($fallbackLines);
            foreach ($fallbackBlocks as $block) {
                $firm = $this->parseFirmBlock($block, $sequence);
                if ($firm === null) {
                    continue;
                }
                $firms[] = $firm;
                $sequence++;
            }
            if ($firms !== []) {
                $strategy = 'line_based_fallback';
                $headingCount = count($fallbackBlocks);
                $pageStats = $this->pageStats($fallbackLines);
            }
        }

        if ($firms === []) {
            $conservative = $this->conservativeFallbackParse($lines);
            if (($conservative['firms'] ?? []) !== []) {
                return array_merge($conservative, [
                    'parser_version' => self::PARSER_VERSION,
                    'parse_mode' => 'directory',
                    'strategy' => 'conservative_fallback',
                    'rows_detected' => $conservative['heading_count'] ?? count($conservative['firms']),
                    'skipped_details' => [],
                    'missing_serials' => [],
                    'duplicate_serials' => [],
                    'duplicate_firms' => [],
                    'unique_firm_estimate' => count($conservative['firms']),
                    'page_stats' => $pageStats,
                ]);
            }
        }

        return [
            'parser_version' => self::PARSER_VERSION,
            'parse_mode' => 'directory',
            'strategy' => $strategy,
            'firm_count' => count($firms),
            'heading_count' => $headingCount,
            'rows_detected' => $headingCount,
            'skipped_blocks' => $skippedBlocks,
            'skipped_details' => $skippedDetails,
            'missing_serials' => [],
            'duplicate_serials' => [],
            'duplicate_firms' => [],
            'unique_firm_estimate' => count($firms),
            'candidate_firm_count' => $headingCount,
            'page_stats' => $pageStats,
            'firms' => $this->forwardFillSectionCities($firms),
        ];
    }

    /**
     * Forward-fill missing city only within the same page bucket (text parser has no columns).
     *
     * @param  list<array<string, mixed>>  $firms
     * @return list<array<string, mixed>>
     */
    private function forwardFillSectionCities(array $firms): array
    {
        $lastByPage = [];
        foreach ($firms as $i => $firm) {
            $page = (int) ($firm['page_number'] ?? 0);
            $city = trim((string) ($firm['city'] ?? ''));
            if ($city !== '') {
                $lastByPage[$page] = $city;
                continue;
            }
            if (! isset($lastByPage[$page])) {
                continue;
            }
            $fill = $lastByPage[$page];
            $firms[$i]['city'] = $fill;
            $firms[$i]['raw_city'] = $firm['raw_city'] ?? $fill;
            $missing = is_array($firm['missing_required_fields'] ?? null) ? $firm['missing_required_fields'] : [];
            $firms[$i]['missing_required_fields'] = array_values(array_filter(
                $missing,
                static fn ($f) => $f !== 'city',
            ));
        }

        return $firms;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(string $reason): array
    {
        return [
            'parser_version' => self::PARSER_VERSION,
            'parse_mode' => 'none',
            'firm_count' => 0,
            'heading_count' => 0,
            'rows_detected' => 0,
            'skipped_blocks' => 1,
            'skipped_details' => [['serial' => null, 'reason' => $reason, 'snippet' => '']],
            'missing_serials' => [],
            'duplicate_serials' => [],
            'duplicate_firms' => [],
            'unique_firm_estimate' => 0,
            'firms' => [],
        ];
    }

    /**
     * @param  list<array{text: string, line_no: int, page: int|null}>  $lines
     * @return array{pages_with_text: int, lines_per_page: array<int, int>}
     */
    private function pageStats(array $lines): array
    {
        $perPage = [];
        foreach ($lines as $line) {
            $page = (int) ($line['page'] ?? 1);
            $perPage[$page] = ($perPage[$page] ?? 0) + 1;
        }

        return [
            'pages_with_text' => count($perPage),
            'lines_per_page' => $perPage,
        ];
    }

    /**
     * @return list<array{text: string, line_no: int, page: int|null}>
     */
    private function prepareLines(string $rawText): array
    {
        $rawText = str_replace(["\r\n", "\r"], "\n", $rawText);
        $rawText = $this->repairBrokenFirmSuffixes($rawText);
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

            $text = $this->normalizeOcrFirmTypos($text);

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

    /**
     * Join OCR wraps such as "GIN AGARWAL AND\nOCIATES" → "... AND ASSOCIATES".
     */
    private function repairBrokenFirmSuffixes(string $rawText): string
    {
        $patterns = [
            '/\bAND\s*\n\s*(ASSOCIATES|OCIATES|COMPANY|CO\.?)\b/iu' => 'AND $1',
            '/\b&\s*\n\s*(ASSOCIATES|OCIATES|COMPANY|CO\.?)\b/iu' => '& $1',
            '/\b(AGARWAL|AGRAWAL|GOYAL|SHAH|MEHTA|SINGHAL)\s*\n\s*(AND\s+ASSOCIATES|&\s*ASSOCIATES|&\s*CO)\b/iu' => '$1 $2',
            '/\bOCIATES\b/iu' => 'ASSOCIATES',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $rawText = preg_replace($pattern, $replacement, $rawText) ?? $rawText;
        }

        return $rawText;
    }

    private function normalizeOcrFirmTypos(string $text): string
    {
        $text = preg_replace('/&\s*CD\b/i', '& CO', $text) ?? $text;
        $text = preg_replace('/&\s*C0\b/i', '& CO', $text) ?? $text;
        $text = preg_replace('/\bAND\s+OCIATES\b/i', 'AND ASSOCIATES', $text) ?? $text;
        $text = preg_replace('/&\s*OCIATES\b/i', '& ASSOCIATES', $text) ?? $text;

        return $text;
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
        $skipBranchCities = false;

        foreach ($lines as $line) {
            $text = $line['text'];

            // "Also at JAIPUR / BARAN" are branch offices — never replace the section city.
            if (preg_match('/^also\s+at\b/iu', $text)) {
                $skipBranchCities = true;
                if ($current !== null) {
                    $current['lines'][] = $line;
                }
                continue;
            }
            if ($skipBranchCities) {
                if ($this->looksLikeFirmName($text)) {
                    $skipBranchCities = false;
                } elseif ($this->looksLikeCityHeader($text, $line) || $this->entities()->isCity($text)) {
                    if ($current !== null) {
                        $current['lines'][] = $line;
                    }
                    continue;
                } else {
                    $skipBranchCities = false;
                }
            }

            // City headers before address absorb — "ABU ROAD" must start a new city, not join prior firm.
            if ($this->looksLikeCityHeader($text, $line)) {
                if ($current !== null && $this->looksLikePersonName($text)) {
                    $current['lines'][] = $line;
                    continue;
                }
                $cityHeader = $this->normalizeDirectoryCityHeader($text);
                // Mid-record localities (LAJPAT NAGAR) stay with the open firm until it looks complete
                // (PIN / address already captured). Confirmed section cities after a complete firm
                // (AHILY NAGAR after 370205) start the next section.
                if ($current !== null && $cityHeader !== null
                    && $this->entities()->isAddress($text) && ! $this->isStrongCityDirectoryHeader($text)
                    && ! $this->firmBlockLooksComplete($current)) {
                    $current['lines'][] = $line;
                    continue;
                }
                if ($current !== null && $cityHeader === null
                    && $this->entities()->isAddress($text) && ! $this->isStrongCityDirectoryHeader($text)) {
                    $current['lines'][] = $line;
                    continue;
                }
                // Westprop-style: city AFTER firm address closes the open firm WITH that city
                // only when the firm still lacks a section city. ICAI headers between firms
                // (ADIPUR … AHILY NAGAR) must not overwrite the prior firm’s city.
                if ($current !== null && ($current['lines'] !== [] || ! empty($current['firm_hint']))) {
                    if ($cityHeader !== null && empty($current['city'])) {
                        $current['city'] = $cityHeader;
                        $current['city_meta'] = $this->fieldMeta($cityHeader, 0.8, $line);
                        $current['lines'][] = $line;
                    }
                    $blocks[] = $current;
                    $current = null;
                }
                if ($cityHeader !== null) {
                    $currentCity = $cityHeader;
                    $currentCityMeta = $this->fieldMeta($currentCity, 0.72, $line);
                }
                continue;
            }

            if ($current !== null && $this->entities()->isAddress($text)) {
                $current['lines'][] = $line;
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
                // Never start a firm block from person/PIN alone — that creates phantom firm rows.
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
     * @param  array{city: ?string, firm_hint?: ?string, lines: list<array{text: string}>}  $block
     */
    private function firmBlockLooksComplete(array $block): bool
    {
        foreach ($block['lines'] ?? [] as $line) {
            $text = (string) ($line['text'] ?? '');
            if ($this->extractPincode($text)) {
                return true;
            }
        }
        $hasPerson = false;
        $hasAddress = false;
        foreach ($block['lines'] ?? [] as $line) {
            $text = (string) ($line['text'] ?? '');
            if ($this->looksLikePersonName($text)) {
                $hasPerson = true;
            }
            if ($this->entities()->isAddress($text)) {
                $hasAddress = true;
            }
        }

        return $hasPerson && $hasAddress;
    }

    private function recordParser(): OcrDirectoryRecordParser
    {
        return $this->recordParser ?? new OcrDirectoryRecordParser($this->entities());
    }

    /**
     * @param  array{city: ?string, city_meta: ?array, firm_hint?: ?string, firm_hint_line?: ?array, lines: list<array{text: string, line_no: int, page: int|null}>}  $block
     * @return array<string, mixed>|null
     */
    private function parseFirmBlock(array $block, int $sequence): ?array
    {
        $tokens = [];
        if (! empty($block['firm_hint'])) {
            $line = $block['firm_hint_line'] ?? ['line_no' => null, 'page' => null, 'text' => $block['firm_hint']];
            $tokens[] = [
                'text' => (string) $block['firm_hint'],
                'page' => $line['page'] ?? null,
                'line_no' => $line['line_no'] ?? null,
                'x_center' => 0.1,
            ];
        }
        foreach ($block['lines'] as $line) {
            $tokens[] = [
                'text' => $line['text'],
                'page' => $line['page'] ?? null,
                'line_no' => $line['line_no'] ?? null,
                'x_center' => 0.1,
            ];
        }
        if ($tokens === []) {
            return null;
        }

        $parsed = $this->recordParser()->parseBlock($tokens, [
            'sequence_no' => $sequence,
            'section_city' => $block['city'] ?? null,
            'page' => $tokens[0]['page'] ?? null,
            'extraction_source' => 'directory_text',
        ]);

        return $parsed;
    }

    /**
     * Rebuild reading-order text from Document AI paragraphs when coordinates exist.
     *
     * @param  array<string, mixed>|null  $layoutMetadata
     */
    private function rebuildTextFromLayout(?array $layoutMetadata): ?string
    {
        if (! is_array($layoutMetadata)) {
            return null;
        }

        $pages = $layoutMetadata['pages'] ?? null;
        if (! is_array($pages) || $pages === []) {
            return null;
        }

        $pageTexts = [];
        foreach ($pages as $pageIndex => $page) {
            if (! is_array($page)) {
                continue;
            }

            $paragraphs = $page['paragraphs'] ?? $page['blocks'] ?? null;
            if (! is_array($paragraphs) || $paragraphs === []) {
                continue;
            }

            $items = [];
            foreach ($paragraphs as $paragraph) {
                if (is_string($paragraph)) {
                    $text = trim($paragraph);
                    if ($text !== '') {
                        $items[] = ['text' => $text, 'x' => null, 'y' => null];
                    }
                    continue;
                }
                if (! is_array($paragraph)) {
                    continue;
                }
                $text = trim((string) ($paragraph['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $items[] = [
                    'text' => $text,
                    'x' => isset($paragraph['x']) ? (float) $paragraph['x'] : null,
                    'y' => isset($paragraph['y']) ? (float) $paragraph['y'] : null,
                ];
            }

            if ($items === []) {
                continue;
            }

            $hasCoords = false;
            foreach ($items as $item) {
                if ($item['x'] !== null && $item['y'] !== null) {
                    $hasCoords = true;
                    break;
                }
            }
            if ($hasCoords) {
                $ordered = $this->orderParagraphsByColumns($items);
            } else {
                $ordered = array_column($items, 'text');
            }

            $pageNumber = (int) ($page['page_number'] ?? ($pageIndex + 1));
            $pageTexts[] = '--- page '.$pageNumber.' ---'."\n".implode("\n", $ordered);
        }

        if ($pageTexts === []) {
            return null;
        }

        return implode("\n", $pageTexts);
    }

    /**
     * @param  list<array{text: string, x: ?float, y: ?float}>  $items
     * @return list<string>
     */
    private function orderParagraphsByColumns(array $items): array
    {
        $xs = array_values(array_filter(array_map(static fn ($i) => $i['x'], $items), static fn ($x) => $x !== null));
        if ($xs === []) {
            return array_column($items, 'text');
        }

        sort($xs);
        $gaps = [];
        for ($i = 1, $n = count($xs); $i < $n; $i++) {
            $gap = $xs[$i] - $xs[$i - 1];
            if ($gap > 0.08) {
                $gaps[] = ['gap' => $gap, 'mid' => ($xs[$i] + $xs[$i - 1]) / 2];
            }
        }

        usort($gaps, static fn ($a, $b) => $b['gap'] <=> $a['gap']);
        $splits = array_column(array_slice($gaps, 0, 3), 'mid');
        sort($splits);

        $columns = [];
        foreach ($items as $item) {
            $col = 0;
            $x = $item['x'] ?? 0.0;
            foreach ($splits as $split) {
                if ($x >= $split) {
                    $col++;
                }
            }
            $columns[$col][] = $item;
        }

        ksort($columns);
        $ordered = [];
        foreach ($columns as $columnItems) {
            usort($columnItems, static function ($a, $b) {
                $ay = $a['y'] ?? 0.0;
                $by = $b['y'] ?? 0.0;
                if (abs($ay - $by) < 0.01) {
                    return ($a['x'] ?? 0) <=> ($b['x'] ?? 0);
                }

                return $ay <=> $by;
            });
            foreach ($columnItems as $item) {
                $ordered[] = $item['text'];
            }
        }

        return $ordered;
    }

    /**
     * Last-resort parser for noisy multi-column OCR dumps.
     *
     * @param  list<array{text: string, line_no: int, page: int|null}>  $lines
     * @return array{firm_count: int, heading_count: int, skipped_blocks: int, candidate_firm_count: int, firms: list<array<string, mixed>>}
     */
    private function conservativeFallbackParse(array $lines): array
    {
        $firms = [];
        $sequence = 1;
        $currentCity = null;
        $currentCityMeta = null;

        foreach ($lines as $index => $line) {
            $text = $line['text'];
            if ($this->looksLikeCityHeader($text, $line)) {
                $currentCity = $this->titleCase($text);
                $currentCityMeta = $this->fieldMeta($currentCity, 0.65, $line);
                continue;
            }

            if (! $this->looksLikeFirmName($text) && ! $this->looksLikeLooseFirmCandidate($text)) {
                continue;
            }

            $blockLines = [];
            for ($j = $index + 1; $j < min($index + 6, count($lines)); $j++) {
                $next = $lines[$j];
                if ($this->looksLikeFirmName($next['text']) || $this->looksLikeCityHeader($next['text'], $next) || $this->looksLikeLooseFirmCandidate($next['text'])) {
                    break;
                }
                $blockLines[] = $next;
            }

            $firm = $this->parseFirmBlock([
                'city' => $currentCity,
                'city_meta' => $currentCityMeta,
                'firm_hint' => $text,
                'firm_hint_line' => $line,
                'lines' => $blockLines,
            ], $sequence);

            if ($firm === null) {
                continue;
            }

            $firm['overall_confidence'] = min((float) ($firm['overall_confidence'] ?? 0.5), 0.55);
            $firms[] = $firm;
            $sequence++;
        }

        return [
            'firm_count' => count($firms),
            'heading_count' => count($firms),
            'skipped_blocks' => 0,
            'candidate_firm_count' => count($firms),
            'firms' => $firms,
        ];
    }

    private function looksLikeLooseFirmCandidate(string $text): bool
    {
        $lower = mb_strtolower($text);
        if ($this->entities()->isAddressShape($text) || $this->looksLikeAddressLine($text)) {
            return false;
        }
        if ($this->looksLikePersonName($text)) {
            return false;
        }
        if ($this->extractPincode($text) || $this->extractPhone($text) || $this->extractGst($text)) {
            return false;
        }
        if (mb_strlen($text) < 6 || mb_strlen($text) > 80) {
            return false;
        }
        // ALL CAPS multi-token business-like headings without classic markers.
        if ($text === mb_strtoupper($text) && preg_match('/^[A-Z0-9 .&\'\-]+$/', $text)) {
            $words = preg_split('/\s+/', $text) ?: [];
            if (count($words) >= 2 && count($words) <= 6
                && ! preg_match('/\d/', $text)
                && (preg_match('/\b(?:and|&)\s+co(?:mpany)?\.?\b/u', $lower) || str_contains($lower, 'associat'))) {
                return true;
            }
        }

        return false;
    }


    private function looksLikeFirmName(string $text): bool
    {
        // Delegate to shared classifier (address veto, bare-marker veto, title stem).
        if ($this->entities()->isFirmName($text)) {
            return true;
        }

        $lower = mb_strtolower($text);

        if (preg_match('/^(firm\s*name|name of firm)\s*[:\-]/i', $text)) {
            return true;
        }

        // Directory headings without classic markers: "MEHTA BROS", "TAX BUREAU"
        if (preg_match('/\b(bros|bureau|group|enterprise|enterprises|services|consultancy)\b/i', $text)
            && ! $this->entities()->isAddressShape($text)
            && ! $this->extractPhone($text)
            && mb_strlen($text) <= 60
            && ! preg_match('/^\d/', $text)) {
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
        if (preg_match('/^\d{5,6}\s*[A-Z]$/i', trim($text)) || preg_match('/^\d{5,8}$/', trim($text))) {
            return false;
        }
        if ((new OcrIdentifierExtractorService)->isIcaiFrnPattern($text)) {
            return false;
        }
        if ($this->extractPincode($text) || $this->extractGst($text) || $this->extractEmail($text) || $this->extractPhone($text)) {
            return false;
        }
        if ($this->looksLikeFirmName($text)) {
            return false;
        }
        if ($this->extractState($text)) {
            return false;
        }

        return $this->cityHeadings()->isHeading($text, [
            'width' => (float) ($line['width'] ?? 0.12),
            'y_center' => (float) ($line['y_center'] ?? 0),
            'x_center' => (float) ($line['x_center'] ?? 0),
        ]);
    }

    /** True city section headers only — not street/locality lines like LAJPAT NAGAR. */
    private function isStrongCityDirectoryHeader(string $text): bool
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        if (count($words) === 1 && preg_match('/^[A-Z]{3,}$/', trim($text))) {
            return true;
        }
        if (count($words) === 2 && preg_match('/^[A-Z][A-Z\s]+\s+(?:ROAD|CITY|CANTT)$/i', trim($text))) {
            return ! preg_match('/\b(?:circular|main|bank|jail|railway|gt|ring|link|patel|nicholson|idgah|jagadhri|rajauli|new|old|suraj)\b/iu', $text);
        }

        return false;
    }

    /** Strip PIN / membership crumbs from city headers (ADIPUR KACHCHH-370205 → ADIPUR). */
    private function normalizeDirectoryCityHeader(string $text): ?string
    {
        $detected = $this->cityHeadings()->detect($text);

        return $detected['city'] ?? null;
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
        // Accept spaced mobiles from OCR: "73386 33003", "093594 72100"
        if (preg_match('/(?<!\d)(?:\+91[\-\s]?|0)?([6-9](?:[\s\-]?\d){9})(?!\d)/u', $text, $m)) {
            $digits = preg_replace('/\D+/', '', $m[1]) ?? '';

            return strlen($digits) === 10 ? $digits : null;
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
                return trim($text);
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

        return $index === 0 ? 'Proprietor' : 'Partner';
    }

    private function stripFirmLabel(string $text): string
    {
        $text = preg_replace('/^(firm\s*name|name of firm)\s*[:\-]\s*/i', '', $text) ?? $text;

        return trim($text);
    }

    private function stripPersonDecorations(string $text): string
    {
        $text = preg_replace('/^(s\/o|sio|s\.o|w\/o|d\/o|c\/o|ca\.?|shri|smt|mr\.?|mrs\.?|ms\.?)\s+/i', '', $text) ?? $text;
        $text = preg_replace('/\b(?:m\.?\s*no\.?|mem(?:bership)?\.?\s*no\.?|membership)\s*[:\-]?\s*[A-Z0-9\/\-]+/i', '', $text) ?? $text;
        $text = preg_replace('/(?<!\d)(?:\+91[\-\s]?|0)?[6-9](?:[\s\-]?\d){9}(?!\d)/u', '', $text) ?? $text;

        return trim($text, " \t-,.");
    }

    /**
     * @param  list<string>  $parts
     */
    private function joinAddressParts(array $parts): string
    {
        $text = implode(', ', array_values(array_unique($parts)));
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
