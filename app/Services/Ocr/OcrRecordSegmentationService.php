<?php

namespace App\Services\Ocr;

/**
 * Visual row segmentation using Document AI bounding boxes.
 * Each firm block is isolated — fields from row N+1 never enter row N.
 */
class OcrRecordSegmentationService
{
    private const COLUMN_GAP_MIN = 0.10;

    private const RECORD_GAP_MIN = 0.022;

    private const IDENTIFIER_STRIP_PAD = 0.10;

    public function __construct(
        private readonly ?OcrEntityClassificationService $classifier = null,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $tokens
     * @return list<array<string, mixed>>
     */
    public function segmentPage(array $tokens, ?string $inheritedSectionCity = null): array
    {
        $headings = $this->collectPageHeadings($tokens);
        $columns = $this->detectColumns($tokens);
        $blocks = [];
        foreach ($columns as $columnIndex => $columnTokens) {
            // Do not inherit left-column bottom city into the whole right column —
            // section city is resolved by Y against page-wide headings below.
            foreach ($this->segmentColumn($columnTokens, $columnIndex, null) as $block) {
                if (empty($block['is_section_heading'])) {
                    $firmToken = $block['firm_token'] ?? ($block['all_tokens'][0] ?? []);
                    $firmY = (float) ($firmToken['y_center'] ?? 0);
                    $firmX = (float) ($firmToken['x_center'] ?? $firmToken['x_min'] ?? 0);
                    $block['section_city'] = $this->sectionCityAtY($headings, $firmY, $firmX, $inheritedSectionCity);
                }
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /** Last section city on this page by reading order (for carry into the next page). */
    public function lastSectionCityFromBlocks(array $blocks): ?string
    {
        $last = null;
        foreach ($blocks as $block) {
            if (! empty($block['section_city'])) {
                $last = (string) $block['section_city'];
            }
        }

        return $last;
    }

    /**
     * @param  list<array<string, mixed>>  $pageTokens
     * @return list<array{y: float, city: string}>
     */
    private function collectPageHeadings(array $pageTokens): array
    {
        $entities = $this->classifier ?? new OcrEntityClassificationService;
        $headings = [];
        foreach ($pageTokens as $token) {
            $text = trim((string) ($token['text'] ?? ''));
            if ($text === '' || ! $this->isCityDirectoryHeading($text, $token, $entities)) {
                continue;
            }
            $headings[] = [
                'y' => (float) ($token['y_center'] ?? $token['y_min'] ?? 0),
                'x' => (float) ($token['x_center'] ?? $token['x_min'] ?? 0),
                'city' => $text,
            ];
        }
        usort($headings, static fn (array $a, array $b) => $a['y'] <=> $b['y']);

        return $headings;
    }

    /**
     * Active directory city for a firm — latest heading band at/above the firm,
     * then nearest heading by X (so left ABOHAR / right AMBALA at the same Y stay correct).
     *
     * @param  list<array{y: float, x: float, city: string}>  $headings
     */
    private function sectionCityAtY(array $headings, float $firmY, float $firmX, ?string $fallback): ?string
    {
        $active = [];
        foreach ($headings as $heading) {
            if ($heading['y'] <= $firmY + 0.03) {
                $active[] = $heading;
            }
        }
        if ($active === []) {
            return $fallback;
        }
        $maxY = max(array_column($active, 'y'));
        $band = array_values(array_filter($active, static fn (array $h) => $h['y'] >= $maxY - 0.04));
        usort($band, static fn (array $a, array $b) => abs($a['x'] - $firmX) <=> abs($b['x'] - $firmX));

        return $band[0]['city'] ?? $fallback;
    }

    /**
     * Assign tokens to directory columns using firm-name anchors so right-side
     * membership/FRN codes stay with their firm (2- or 3-column ICAI layouts).
     *
     * @param  list<array<string, mixed>>  $pageTokens
     * @return list<list<array<string, mixed>>>
     */
    public function detectColumns(array $pageTokens): array
    {
        if ($pageTokens === []) {
            return [];
        }

        $entities = $this->classifier ?? new OcrEntityClassificationService;
        $anchorMins = [];
        foreach ($pageTokens as $token) {
            $text = trim((string) ($token['text'] ?? ''));
            if ($text !== '' && $entities->isFirmName($this->stripInlineIdentifier($text))) {
                $anchorMins[] = (float) ($token['x_min'] ?? $token['x_center'] ?? 0);
            }
        }

        if (count($anchorMins) >= 2) {
            return $this->detectColumnsByFirmAnchors($pageTokens, $anchorMins);
        }

        return $this->detectColumnsByLargestGap($pageTokens);
    }

    /**
     * @param  list<array<string, mixed>>  $pageTokens
     * @param  list<float>  $anchorMins
     * @return list<list<array<string, mixed>>>
     */
    private function detectColumnsByFirmAnchors(array $pageTokens, array $anchorMins): array
    {
        sort($anchorMins);
        $clusters = $this->clusterValues($anchorMins, 0.06);
        $centers = array_map(static fn (array $c) => array_sum($c) / count($c), $clusters);
        sort($centers);
        $n = count($centers);
        $rightEdges = [];
        for ($i = 0; $i < $n; $i++) {
            if ($i < $n - 1) {
                $rightEdges[$i] = (($centers[$i] + $centers[$i + 1]) / 2) + self::IDENTIFIER_STRIP_PAD;
            } else {
                $rightEdges[$i] = 1.0;
            }
        }

        $columns = array_fill(0, $n, []);
        foreach ($pageTokens as $token) {
            $xMin = (float) ($token['x_min'] ?? $token['x_center'] ?? 0);
            $col = $n - 1;
            for ($i = 0; $i < $n; $i++) {
                if ($xMin < $rightEdges[$i]) {
                    $col = $i;
                    break;
                }
            }
            $token['column'] = $col;
            $columns[$col][] = $token;
        }

        return array_values($columns);
    }

    /**
     * @param  list<array<string, mixed>>  $pageTokens
     * @return list<list<array<string, mixed>>>
     */
    private function detectColumnsByLargestGap(array $pageTokens): array
    {
        $centers = array_map(static fn (array $t) => (float) $t['x_center'], $pageTokens);
        sort($centers);
        $gapPairs = [];
        for ($i = 1, $n = count($centers); $i < $n; $i++) {
            $gap = $centers[$i] - $centers[$i - 1];
            if ($gap >= self::COLUMN_GAP_MIN) {
                $gapPairs[] = ['gap' => $gap, 'split' => ($centers[$i] + $centers[$i - 1]) / 2];
            }
        }
        usort($gapPairs, static fn (array $a, array $b) => $b['gap'] <=> $a['gap']);
        $splitPoints = $gapPairs !== [] ? [round($gapPairs[0]['split'], 3)] : [];

        if ($splitPoints === []) {
            return [0 => $pageTokens];
        }

        $columns = [];
        foreach ($pageTokens as $token) {
            $col = 0;
            foreach ($splitPoints as $split) {
                if ($token['x_center'] > $split) {
                    $col++;
                }
            }
            $token['column'] = $col;
            $columns[$col][] = $token;
        }
        ksort($columns);

        return array_values($columns);
    }

    /**
     * @param  list<float>  $values
     * @return list<list<float>>
     */
    private function clusterValues(array $values, float $threshold): array
    {
        $clusters = [];
        foreach ($values as $value) {
            $placed = false;
            foreach ($clusters as &$cluster) {
                $center = array_sum($cluster) / count($cluster);
                if (abs($center - $value) <= $threshold) {
                    $cluster[] = $value;
                    $placed = true;
                    break;
                }
            }
            unset($cluster);
            if (! $placed) {
                $clusters[] = [$value];
            }
        }

        return $clusters;
    }

  /** Strip trailing ICAI code from firm line for anchor detection. */
    private function stripInlineIdentifier(string $text): string
    {
        if (preg_match('/^(.+?)\s*[-–]\s*\d{5,6}[A-Z]?\s*$/i', $text, $m)) {
            return trim($m[1]);
        }

        return $text;
    }

    /**
     * @param  list<array<string, mixed>>  $columnTokens
     * @return list<array<string, mixed>>
     */
    private function segmentColumn(array $columnTokens, int $columnIndex, ?string $inheritedSectionCity = null): array
    {
        $entities = $this->classifier ?? new OcrEntityClassificationService;
        usort($columnTokens, static fn (array $a, array $b) => $a['y_center'] <=> $b['y_center']);

        $blocks = [];
        $current = null;
        $prevYMax = null;
        $sectionCity = $inheritedSectionCity;

        foreach ($columnTokens as $token) {
            $text = $token['text'];
            $gap = $prevYMax !== null ? ($token['y_min'] - $prevYMax) : 0.0;
            $largeGap = $gap >= self::RECORD_GAP_MIN;

            // City headers only between firms (or at column start) — never mid-record street lines.
            if ($this->isCityDirectoryHeading($text, $token, $entities) && ($current === null || $largeGap)) {
                if ($current !== null) {
                    $blocks[] = $this->finalizeBlock($current);
                    $current = null;
                }
                $sectionCity = trim($text);
                $blocks[] = ['is_section_heading' => true, 'section_city' => $sectionCity, 'column' => $columnIndex, 'tokens' => [$token]];
                $prevYMax = $token['y_max'];
                continue;
            }

            $isFirmStart = $entities->isFirmName($this->stripInlineIdentifier($text))
                && ! $entities->isCareOfLine($text);

            if ($isFirmStart) {
                if ($current !== null) {
                    $current['row_split_suspected'] = $current['row_split_suspected'] ?? false;
                    $blocks[] = $this->finalizeBlock($current);
                }
                $current = [
                    'column' => $columnIndex,
                    'section_city' => $sectionCity,
                    'firm_token' => $token,
                    'tokens' => [],
                    // Dense directory packing is normal — never flag merge from proximity alone.
                    // Scoped merge is decided later when firm/CA/city tokens cross records.
                    'row_merge_suspected' => false,
                    'page' => $token['page'] ?? null,
                ];
                $prevYMax = $token['y_max'];
                continue;
            }

            if ($current === null) {
                $prevYMax = $token['y_max'];
                continue;
            }

            if ($largeGap && ! $entities->isAddress($text)) {
                $current['ambiguous_record_boundary'] = true;
            }

            $current['tokens'][] = $token;
            $prevYMax = max($prevYMax ?? 0, (float) $token['y_max']);
        }

        if ($current !== null) {
            $blocks[] = $this->finalizeBlock($current);
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function finalizeBlock(array $block): array
    {
        $all = [];
        if (! empty($block['firm_token'])) {
            $all[] = $block['firm_token'];
        }
        foreach ($block['tokens'] ?? [] as $t) {
            $all[] = $t;
        }
        $block['all_tokens'] = $all;
        $block['token_count'] = count($all);

        // Scoped merge: more than one firm-name token landed in the same visual block.
        $entities = $this->classifier ?? new OcrEntityClassificationService;
        $firmHits = [];
        // Scoped merge only when a real second firm title appears — not designations.
        foreach ($all as $token) {
            $text = $this->stripInlineIdentifier((string) ($token['text'] ?? ''));
            if ($text === '' || preg_match('/^chartered\s+accountants?\.?$/iu', $text)) {
                continue;
            }
            if ($entities->isFirmName($text) && ! $entities->isCareOfLine($text)) {
                $firmHits[] = $text;
            }
        }
        if (count($firmHits) > 1) {
            $block['row_merge_suspected'] = true;
            $block['row_merge_evidence'] = [
                [
                    'affected_field' => 'firm_name',
                    'token' => $firmHits[1],
                    'source_row' => null,
                    'assigned_row' => null,
                    'bounding_box' => null,
                    'reason' => 'multiple_firm_name_tokens_in_same_block',
                ],
            ];
        }

        return $block;
    }

    private function isCityDirectoryHeading(string $text, array $token, OcrEntityClassificationService $entities): bool
    {
        if ($entities->isFirmName($text) || $entities->isPerson($text) || $entities->isAddress($text)) {
            return false;
        }
        if (preg_match('/\d/', $text)) {
            return false;
        }
        // Mid-record street lines stay with the firm (CIRCULAR ROAD, NEAR BANK, H NO …).
        if (preg_match('/\b(?:street\s*no|house|h\.?\s*no|shop|floor|near|opp|behind|backside|hospital|sector\s*\d|ward\s*no)\b/iu', $text)) {
            return false;
        }
        $words = preg_split('/\s+/', trim($text)) ?: [];
        if (count($words) > 2) {
            return false;
        }
        // Known cities (ABOHAR) are often short labels — do not require wide bbox.
        if ($entities->isCity($text)) {
            return true;
        }
        if (($token['width'] ?? 0) < 0.10) {
            return false;
        }
        // ABU ROAD / X CITY / Y CANTT style headers when not yet in city master.
        return (bool) preg_match('/^[A-Z][A-Z\s\-]{1,30}$/', trim($text))
            && (bool) preg_match('/\b(?:ROAD|CITY|CANTT)$/i', $text);
    }
}
