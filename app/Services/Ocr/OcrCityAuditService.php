<?php

namespace App\Services\Ocr;

use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;

/**
 * City-section audit for OCR documents — headings, assignments, conflicts.
 */
class OcrCityAuditService
{
    public function __construct(
        private readonly ?OcrCityHeadingDetector $headings = null,
        private readonly ?OcrCityResolverService $resolver = null,
        private readonly ?OcrLayoutDirectoryParser $layoutParser = null,
        private readonly ?OcrDirectoryProfileDetector $profiles = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function audit(OcrDocument $document): array
    {
        $structured = is_array($document->structured_data) ? $document->structured_data : [];
        $headings = $this->headings ?? new OcrCityHeadingDetector;
        $resolver = $this->resolver ?? new OcrCityResolverService;
        $profile = ($this->profiles ?? new OcrDirectoryProfileDetector)->detect($document, $structured);
        $detectedHeadings = [];
        $rejected = [];

        foreach ($structured['pages'] ?? [] as $page) {
            $pageNo = (int) ($page['page_number'] ?? 1);
            foreach ($page['paragraphs'] ?? [] as $para) {
                $text = trim((string) ($para['text'] ?? ''));
                if ($text === '' || mb_strlen($text) > 80) {
                    continue;
                }
                $hit = $headings->detect($text, [
                    'width' => abs((float) (($para['bounding_box'][1]['x'] ?? 0) - ($para['bounding_box'][0]['x'] ?? 0))),
                    'y_center' => (float) (($para['bounding_box'][0]['y'] ?? 0)),
                    'x_center' => (float) (($para['bounding_box'][0]['x'] ?? 0)),
                ]);
                if ($hit !== null) {
                    $detectedHeadings[] = [
                        'page' => $pageNo,
                        'raw_heading' => $hit['raw_city'],
                        'canonical_city' => $hit['city'],
                        'confidence' => $hit['confidence'],
                        'evidence' => $hit['evidence'] ?? null,
                        'y' => (float) (($para['bounding_box'][0]['y'] ?? 0)),
                        'x' => (float) (($para['bounding_box'][0]['x'] ?? 0)),
                    ];
                } elseif (preg_match('/^[A-Z][A-Z\s\-]{2,40}$/', $text)
                    && ! preg_match('/\d/', $text)
                    && count(preg_split('/\s+/', $text) ?: []) <= 3) {
                    $rejected[] = [
                        'page' => $pageNo,
                        'text' => $text,
                        'reason' => 'all_caps_not_resolvable_city',
                    ];
                }
            }
        }

        $firms = $document->parsedFirms()->with('members')->orderBy('sequence_no')->get();
        $missingCity = [];
        $conflicts = [];
        $addressLike = [];
        $rows = [];
        foreach ($firms as $firm) {
            $city = trim((string) ($firm->city ?? ''));
            $source = is_array($firm->source_data) ? $firm->source_data : [];
            $citySource = $source['city_source'] ?? ($source['parsed']['city_source'] ?? 'unknown');
            $partners = $firm->members
                ->where('is_primary', false)
                ->pluck('ca_name')
                ->filter()
                ->values()
                ->all();
            $ca = $source['parsed']['ca_name'] ?? ($firm->members->firstWhere('is_primary', true)?->ca_name);
            $row = [
                'document' => $document->original_filename,
                'page' => $firm->page_number,
                'column' => $firm->column_number,
                'row' => $firm->sequence_no,
                'raw_city_heading' => $source['raw']['city'] ?? $firm->raw_city,
                'canonical_city' => $city,
                'city_source' => $citySource,
                'city_confidence' => $source['field_meta']['city']['confidence'] ?? null,
                'firm_name' => $firm->firm_name,
                'ca_name' => $ca,
                'partners' => implode(' | ', $partners),
                'validation_errors' => is_array($firm->validation_errors)
                    ? implode('|', $firm->validation_errors)
                    : '',
            ];
            $rows[] = $row;
            if ($city === '') {
                $missingCity[] = $row;
            } elseif ($resolver->isForbiddenLocalityShape($city)
                || (preg_match('/\b(?:road|street|floor|colony|market|mandi|paraganas)\b/iu', $city)
                    && ! $resolver->isResolvableCity($city))) {
                $addressLike[] = $row;
            }
            if ($city !== '' && $ca !== null && mb_strtolower($city) === mb_strtolower((string) $ca)) {
                $conflicts[] = $row;
            }
        }

        $byCity = [];
        foreach ($firms as $firm) {
            $c = trim((string) ($firm->city ?? '')) ?: '(missing)';
            $byCity[$c] = ($byCity[$c] ?? 0) + 1;
        }

        return [
            'document_id' => $document->id,
            'document' => $document->original_filename,
            'directory_profile' => $profile,
            'detected_headings' => $detectedHeadings,
            'heading_count' => count($detectedHeadings),
            'rejected_city_candidates' => array_slice($rejected, 0, 200),
            'rejected_count' => count($rejected),
            'firm_count' => $firms->count(),
            'missing_city_count' => count($missingCity),
            'missing_city_rows' => array_slice($missingCity, 0, 100),
            'address_like_city_count' => count($addressLike),
            'address_like_city_rows' => array_slice($addressLike, 0, 50),
            'city_ca_conflicts' => $conflicts,
            'firms_by_city' => $byCity,
            'rows' => $rows,
        ];
    }

    /**
     * @param  resource  $out
     */
    public function writeCsv($out, array $report): void
    {
        fputcsv($out, [
            'document', 'page', 'column', 'row', 'raw_city_heading', 'canonical_city',
            'city_source', 'city_confidence', 'firm_name', 'ca_name', 'partners', 'validation_errors',
        ]);
        foreach ($report['rows'] ?? [] as $row) {
            fputcsv($out, [
                $row['document'] ?? '',
                $row['page'] ?? '',
                $row['column'] ?? '',
                $row['row'] ?? '',
                $row['raw_city_heading'] ?? '',
                $row['canonical_city'] ?? '',
                $row['city_source'] ?? '',
                $row['city_confidence'] ?? '',
                $row['firm_name'] ?? '',
                $row['ca_name'] ?? '',
                $row['partners'] ?? '',
                $row['validation_errors'] ?? '',
            ]);
        }
    }
}
