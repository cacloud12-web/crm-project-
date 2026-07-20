<?php

namespace App\Services\Ocr;

use App\Support\DocumentAi\LayoutGeometryHelper;

/**
 * Layout-aware directory parser — delegates row segmentation and field extraction
 * to production services. Optimized for correctness over speed.
 */
class OcrLayoutDirectoryParser
{
    private const PARSER_VERSION = '4.0.0-layout';

    public function __construct(
        private readonly ?OcrRecordSegmentationService $segmentation = null,
        private readonly ?OcrDirectoryRecordParser $recordParser = null,
        private readonly ?OcrEntityClassificationService $classifier = null,
    ) {}

    public function canParse(array $structuredData): bool
    {
        foreach ($structuredData['pages'] ?? [] as $page) {
            foreach ($page['paragraphs'] ?? [] as $paragraph) {
                $bbox = $paragraph['bounding_box'] ?? [];
                if (is_array($bbox) && count($bbox) >= 4) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $structuredData
     * @param  string|null  $directoryProfile  partnership_directory | proprietor_directory
     * @return array<string, mixed>|null
     */
    public function parse(array $structuredData, ?string $directoryProfile = null): ?array
    {
        $tokens = $this->collectTokens($structuredData);
        if ($tokens === []) {
            return null;
        }

        $isPartnership = $directoryProfile === OcrDirectoryProfileDetector::PARTNERSHIP;
        $segmenter = $this->segmentation ?? new OcrRecordSegmentationService($this->classifier);
        $parser = $this->recordParser ?? new OcrDirectoryRecordParser($this->classifier);
        $partnershipExtractor = $isPartnership
            ? new OcrPartnershipDirectoryExtractor($this->classifier)
            : null;

        $firms = [];
        $sequence = 1;
        $headingCount = 0;
        $skipped = [];
        $ambiguous = 0;
        $carrySectionCity = null;
        $partnerTotal = 0;

        foreach ($this->groupByPage($tokens) as $pageNumber => $pageTokens) {
            $continuation = $segmenter->continuationCityForNextPage($pageTokens, $carrySectionCity);
            $blocks = $segmenter->segmentPage($pageTokens, $continuation);
            $carrySectionCity = $segmenter->lastSectionCityFromBlocks($blocks);
            foreach ($blocks as $block) {
                if (! empty($block['is_section_heading'])) {
                    $headingCount++;
                    continue;
                }

                $allTokens = $block['all_tokens'] ?? [];
                if ($allTokens === []) {
                    continue;
                }

                $ctx = [
                    'sequence_no' => $sequence,
                    'page' => $pageNumber,
                    'column' => $block['column'] ?? null,
                    'section_city' => $block['section_city'] ?? null,
                    'row_merge_suspected' => $block['row_merge_suspected'] ?? false,
                    'row_merge_evidence' => $block['row_merge_evidence'] ?? [],
                    'row_split_suspected' => $block['row_split_suspected'] ?? false,
                    'ambiguous_record_boundary' => $block['ambiguous_record_boundary'] ?? false,
                    'extraction_source' => $isPartnership ? 'partnership_directory' : 'layout_directory',
                    'directory_profile' => $directoryProfile,
                ];

                $firm = $isPartnership && $partnershipExtractor !== null
                    ? $partnershipExtractor->extract($allTokens, $ctx)
                    : $parser->parseBlock($allTokens, $ctx);

                if ($firm === null) {
                    $skipped[] = ['reason' => 'empty_record_block', 'snippet' => mb_substr((string) ($allTokens[0]['text'] ?? ''), 0, 80)];
                    continue;
                }

                if (! empty($firm['ambiguous_layout']) || ! empty($firm['row_merge_suspected'])) {
                    $ambiguous++;
                }
                $partnerTotal += count($firm['partners'] ?? []);
                $firms[] = $firm;
                $sequence++;
            }
        }

        if ($firms === []) {
            return null;
        }

        $beforeDedupe = count($firms);
        $firms = $this->forwardFillSectionCities($firms);
        $dedupe = $this->collapseNormalizedDuplicates($firms);
        $firms = $dedupe['firms'];
        $partnerTotal = 0;
        foreach ($firms as $firm) {
            $partnerTotal += count($firm['partners'] ?? []);
        }

        return [
            'parser_version' => self::PARSER_VERSION,
            'parse_mode' => $isPartnership ? 'partnership_directory' : 'layout_directory',
            'directory_profile' => $directoryProfile ?? OcrDirectoryProfileDetector::PROPRIETOR,
            'firm_count' => count($firms),
            'partner_count' => $partnerTotal,
            'heading_count' => $headingCount,
            'rows_detected' => $beforeDedupe + count($skipped),
            'skipped_blocks' => count($skipped) + $dedupe['duplicates_removed'],
            'skipped_details' => array_merge($skipped, $dedupe['rejected']),
            'missing_serials' => [],
            'duplicate_serials' => [],
            'duplicate_firms' => $dedupe['rejected'],
            'unique_firm_estimate' => count($firms),
            'ambiguous_layout_count' => $ambiguous,
            'duplicates_removed' => $dedupe['duplicates_removed'],
            'firms' => $firms,
        ];
    }

    /**
     * Collapse M/S vs plain and & CO vs AND CO duplicates within the same city.
     *
     * @param  list<array<string, mixed>>  $firms
     * @return array{firms: list<array<string, mixed>>, duplicates_removed: int, rejected: list<array<string, mixed>>}
     */
    private function collapseNormalizedDuplicates(array $firms): array
    {
        $seen = [];
        $kept = [];
        $rejected = [];
        foreach ($firms as $firm) {
            $name = trim((string) ($firm['firm_name'] ?? ''));
            $city = mb_strtolower(trim((string) ($firm['city'] ?? '')));
            if ($name === '') {
                $rejected[] = ['reason' => 'empty_firm_name', 'snippet' => ''];
                continue;
            }
            $key = $this->normalizeFirmKey($name).'|'.$city;
            if (isset($seen[$key])) {
                $rejected[] = [
                    'reason' => 'duplicate_normalized_firm_city',
                    'snippet' => mb_substr($name.' / '.$city, 0, 80),
                    'kept_firm' => $seen[$key],
                ];
                continue;
            }
            $seen[$key] = $name;
            $kept[] = $firm;
        }

        return [
            'firms' => $kept,
            'duplicates_removed' => count($rejected),
            'rejected' => $rejected,
        ];
    }

    private function normalizeFirmKey(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/^m\/?s\.?\s+/u', '', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = preg_replace('/\s+and\s+co(?:mpany)?\.?$/u', ' & co', $name) ?? $name;
        $name = preg_replace('/\s+&\s+co(?:mpany)?\.?$/u', ' & co', $name) ?? $name;
        $name = preg_replace('/\s+and\s+associates$/u', ' & associates', $name) ?? $name;
        $name = preg_replace('/\s+&\s+associates$/u', ' & associates', $name) ?? $name;
        $name = preg_replace('/\s+ca\s+office$/u', '', $name) ?? $name;
        $name = preg_replace('/\s+chartered\s+accountants?.*$/u', '', $name) ?? $name;

        return trim($name);
    }

    /**
     * Forward-fill missing city only within the same page + column section.
     * Never leak across columns or invent a document-wide first city.
     *
     * @param  list<array<string, mixed>>  $firms
     * @return list<array<string, mixed>>
     */
    private function forwardFillSectionCities(array $firms): array
    {
        $lastByBucket = [];
        foreach ($firms as $i => $firm) {
            $page = (int) ($firm['page_number'] ?? 0);
            $col = (int) ($firm['column_number'] ?? -1);
            $bucket = $page.'|'.$col;
            $city = trim((string) ($firm['city'] ?? ''));
            if ($city !== '') {
                $lastByBucket[$bucket] = $city;
                continue;
            }
            if (! isset($lastByBucket[$bucket])) {
                continue;
            }
            $fill = $lastByBucket[$bucket];
            $firms[$i]['city'] = $fill;
            $firms[$i]['raw_city'] = $firm['raw_city'] ?? $fill;
            $firms[$i]['city_source'] = $firm['city_source'] ?? 'section_forward_fill';
            $missing = is_array($firm['missing_required_fields'] ?? null) ? $firm['missing_required_fields'] : [];
            $firms[$i]['missing_required_fields'] = array_values(array_filter(
                $missing,
                static fn ($f) => $f !== 'city',
            ));
        }

        return $firms;
    }

    /**
     * @param  array<string, mixed>  $structuredData
     * @return list<array<string, mixed>>
     */
    private function collectTokens(array $structuredData): array
    {
        $tokens = [];
        foreach ($structuredData['pages'] ?? [] as $page) {
            $pageNumber = (int) ($page['page_number'] ?? 1);
            foreach ($page['paragraphs'] ?? [] as $paragraph) {
                $text = trim((string) ($paragraph['text'] ?? ''));
                if ($text === '' || mb_strlen($text) <= 1) {
                    continue;
                }
                $vertices = is_array($paragraph['bounding_box'] ?? null) ? $paragraph['bounding_box'] : [];
                $bbox = LayoutGeometryHelper::bboxFromVertices($vertices);
                if ($bbox === null) {
                    continue;
                }
                $tokens[] = array_merge($bbox, [
                    'text' => $text,
                    'page' => $pageNumber,
                    'ocr_confidence' => isset($paragraph['confidence']) ? (float) $paragraph['confidence'] : null,
                    'vertices' => $vertices,
                ]);
            }
        }

        usort($tokens, static function (array $a, array $b) {
            if ($a['page'] !== $b['page']) {
                return $a['page'] <=> $b['page'];
            }
            if (abs($a['y_center'] - $b['y_center']) > 0.005) {
                return $a['y_center'] <=> $b['y_center'];
            }

            return $a['x_center'] <=> $b['x_center'];
        });

        return $tokens;
    }

    /**
     * @param  list<array<string, mixed>>  $tokens
     * @return array<int, list<array<string, mixed>>>
     */
    private function groupByPage(array $tokens): array
    {
        $pages = [];
        foreach ($tokens as $token) {
            $pages[(int) $token['page']][] = $token;
        }
        ksort($pages);

        return $pages;
    }
}
