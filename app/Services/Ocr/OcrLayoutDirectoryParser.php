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
     * @return array<string, mixed>|null
     */
    public function parse(array $structuredData): ?array
    {
        $tokens = $this->collectTokens($structuredData);
        if ($tokens === []) {
            return null;
        }

        $segmenter = $this->segmentation ?? new OcrRecordSegmentationService($this->classifier);
        $parser = $this->recordParser ?? new OcrDirectoryRecordParser($this->classifier);

        $firms = [];
        $sequence = 1;
        $headingCount = 0;
        $skipped = [];
        $ambiguous = 0;
        $carrySectionCity = null;

        foreach ($this->groupByPage($tokens) as $pageNumber => $pageTokens) {
            $blocks = $segmenter->segmentPage($pageTokens, $carrySectionCity);
            $carrySectionCity = $segmenter->lastSectionCityFromBlocks($blocks) ?? $carrySectionCity;
            foreach ($blocks as $block) {
                if (! empty($block['is_section_heading'])) {
                    $headingCount++;
                    continue;
                }

                $allTokens = $block['all_tokens'] ?? [];
                if ($allTokens === []) {
                    continue;
                }

                $firm = $parser->parseBlock($allTokens, [
                    'sequence_no' => $sequence,
                    'page' => $pageNumber,
                    'column' => $block['column'] ?? null,
                    'section_city' => $block['section_city'] ?? null,
                    'row_merge_suspected' => $block['row_merge_suspected'] ?? false,
                    'row_merge_evidence' => $block['row_merge_evidence'] ?? [],
                    'row_split_suspected' => $block['row_split_suspected'] ?? false,
                    'ambiguous_record_boundary' => $block['ambiguous_record_boundary'] ?? false,
                    'extraction_source' => 'layout_directory',
                ]);

                if ($firm === null) {
                    $skipped[] = ['reason' => 'empty_record_block', 'snippet' => mb_substr((string) ($allTokens[0]['text'] ?? ''), 0, 80)];
                    continue;
                }

                if (! empty($firm['ambiguous_layout']) || ! empty($firm['row_merge_suspected'])) {
                    $ambiguous++;
                }
                $firms[] = $firm;
                $sequence++;
            }
        }

        if ($firms === []) {
            return null;
        }

        return [
            'parser_version' => self::PARSER_VERSION,
            'parse_mode' => 'layout_directory',
            'firm_count' => count($firms),
            'heading_count' => $headingCount,
            'rows_detected' => count($firms) + count($skipped),
            'skipped_blocks' => count($skipped),
            'skipped_details' => $skipped,
            'missing_serials' => [],
            'duplicate_serials' => [],
            'duplicate_firms' => [],
            'unique_firm_estimate' => count($firms),
            'ambiguous_layout_count' => $ambiguous,
            'firms' => $firms,
        ];
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
