<?php

namespace App\Services\Ocr;

use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use Illuminate\Support\Facades\Schema;

/**
 * Classifies OCR staging rows to explain firm-count overcount (city headings, person-only, dups).
 */
class OcrOvercountAuditService
{
    public function __construct(
        private readonly ?OcrEntityClassificationService $classifier = null,
        private readonly ?OcrCityHeadingDetector $cityHeadingDetector = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function audit(OcrDocument $document, ?string $csvPath = null): array
    {
        $entities = $this->classifier ?? new OcrEntityClassificationService;
        $headings = $this->cityHeadingDetector ?? new OcrCityHeadingDetector($entities);

        $categories = [
            'valid_complete' => 0,
            'missing_firm_name' => 0,
            'missing_ca_name' => 0,
            'missing_city' => 0,
            'city_heading_incorrectly_counted' => 0,
            'ca_person_as_firm' => 0,
            'address_only_row' => 0,
            'number_only_row' => 0,
            'header_footer_noise' => 0,
            'duplicate_exact_row' => 0,
            'duplicate_normalized_row' => 0,
            'duplicate_source_bounding_box' => 0,
            'duplicate_page_column_row' => 0,
            'shard_duplicate' => 0,
            'split_continuation_row' => 0,
            'neighbouring_row_merge' => 0,
            'prior_parse_version_rows' => 0,
            'other_invalid_candidate' => 0,
        ];
        $suspicious = [];
        $seenSource = [];
        $seenBusiness = [];
        $seenBbox = [];
        $seenPageColRow = [];
        $duplicateSource = 0;
        $duplicateBusiness = 0;
        $duplicateBusinessRemoved = 0;
        $duplicateBbox = 0;
        $duplicatePageColRow = 0;
        $rowsWithFirm = 0;
        $validUniqueKeys = [];
        $activeRun = (string) ($document->active_parse_run_id ?? '');

        $structured = is_array($document->structured_data) ? $document->structured_data : [];
        $meta = is_array($structured['metadata'] ?? null) ? $structured['metadata'] : [];
        $quality = is_array($structured['parsed']['quality_report'] ?? null)
            ? $structured['parsed']['quality_report']
            : [];

        $query = OcrParsedFirm::query()->where('ocr_document_id', $document->id)->orderBy('sequence_no');
        $total = (clone $query)->count();

        $query->chunkById(500, function ($firms) use (
            &$categories, &$suspicious, &$seenSource, &$seenBusiness, &$seenBbox, &$seenPageColRow,
            &$duplicateSource, &$duplicateBusiness, &$duplicateBusinessRemoved,
            &$duplicateBbox, &$duplicatePageColRow,
            &$rowsWithFirm, &$validUniqueKeys, $entities, $headings, $activeRun
        ) {
            foreach ($firms as $firm) {
                $src = is_array($firm->source_data) ? $firm->source_data : [];
                $parsed = is_array($src['parsed'] ?? null) ? $src['parsed'] : [];
                $firmName = trim((string) ($firm->firm_name ?: ($parsed['firm_name'] ?? '')));
                $caName = trim((string) ($parsed['ca_name'] ?? ($src['ca_name'] ?? '')));
                $city = trim((string) ($firm->city ?: ($parsed['city'] ?? '')));
                $runId = (string) ($firm->parse_run_id ?? '');
                if ($activeRun !== '' && $runId !== '' && $runId !== $activeRun) {
                    $categories['prior_parse_version_rows']++;
                }

                $bboxKey = json_encode($firm->bounding_box);
                $pageColRow = implode('|', [
                    (int) ($firm->page_number ?? 0),
                    (int) ($firm->column_number ?? ($src['column_number'] ?? 0)),
                    (int) ($firm->row_number ?? 0),
                ]);
                if ($bboxKey && $bboxKey !== 'null' && $bboxKey !== '[]') {
                    if (isset($seenBbox[$bboxKey])) {
                        $duplicateBbox++;
                        $categories['duplicate_source_bounding_box']++;
                    } else {
                        $seenBbox[$bboxKey] = true;
                    }
                }
                if (isset($seenPageColRow[$pageColRow]) && $pageColRow !== '0|0|0') {
                    $duplicatePageColRow++;
                    $categories['duplicate_page_column_row']++;
                } else {
                    $seenPageColRow[$pageColRow] = true;
                }

                $sourceFp = (string) ($firm->source_fingerprint
                    ?? hash('sha256', implode('|', [
                        $firm->ocr_document_id,
                        $firm->page_number ?? 0,
                        $firm->column_number ?? 0,
                        $bboxKey,
                        mb_strtolower($firmName.'|'.$caName.'|'.$city),
                    ])));
                $businessFp = (string) ($firm->business_fingerprint
                    ?? hash('sha256', mb_strtolower(trim($firmName)).'|'.mb_strtolower(trim($caName)).'|'.mb_strtolower(trim($city))));

                if (isset($seenSource[$sourceFp])) {
                    $duplicateSource++;
                    $categories['duplicate_exact_row']++;
                } else {
                    $seenSource[$sourceFp] = true;
                }
                if ($firmName !== '') {
                    $rowsWithFirm++;
                    if (isset($seenBusiness[$businessFp])) {
                        $duplicateBusiness++;
                        $duplicateBusinessRemoved++;
                        $categories['duplicate_normalized_row']++;
                    } else {
                        $seenBusiness[$businessFp] = true;
                    }
                }

                $mergeCodes = is_array($src['validation']['collision_codes'] ?? null) ? $src['validation']['collision_codes'] : [];
                if (in_array('ROW_MERGE_SUSPECTED', $mergeCodes, true) || ! empty($src['row_merge_suspected'])) {
                    $categories['neighbouring_row_merge']++;
                }
                if (! empty($src['split_continuation']) || str_contains(mb_strtolower($firmName), 'continued')) {
                    $categories['split_continuation_row']++;
                }

                $category = $this->classifyRow($firmName, $caName, $city, $entities, $headings);
                $categories[$category]++;

                if ($category === 'valid_complete') {
                    $validUniqueKeys[$businessFp] = true;
                }

                if ($category !== 'valid_complete') {
                    $suspicious[] = [
                        'id' => $firm->id,
                        'sequence_no' => $firm->sequence_no,
                        'category' => $category,
                        'firm_name' => $firmName,
                        'ca_name' => $caName,
                        'city' => $city,
                        'page_number' => $firm->page_number,
                        'match_status' => $firm->match_status,
                    ];
                }
            }
        });

        $dupPages = is_array($meta['duplicate_pages'] ?? null) ? $meta['duplicate_pages'] : [];
        $categories['shard_duplicate'] = count($dupPages);

        $invalidNoise = $categories['city_heading_incorrectly_counted']
            + $categories['ca_person_as_firm']
            + $categories['address_only_row']
            + $categories['number_only_row']
            + $categories['header_footer_noise']
            + $categories['missing_firm_name'];

        $validComplete = $categories['valid_complete'];
        $finalUnique = count($validUniqueKeys);
        $expectedApprox = 26000;
        $overcount = max(0, $total - $rowsWithFirm);

        $report = [
            'document_id' => $document->id,
            'filename' => $document->original_filename,
            'source_pages' => (int) ($document->page_count ?? $document->total_pages ?? 0),
            'ocr_output_shards' => (int) ($meta['shard_count'] ?? 0),
            'raw_blocks' => (int) ($quality['total_rows_detected'] ?? $quality['total_source_rows'] ?? $total),
            'candidate_records' => $total,
            'rows_with_firm_name' => $rowsWithFirm,
            'valid_complete_records' => $validComplete,
            'final_unique_valid_records' => $finalUnique,
            'unique_firm_city_pairs' => count($seenBusiness),
            'invalid_noise_records' => $invalidNoise,
            'exact_source_duplicates' => $duplicateSource,
            'normalized_business_duplicates' => $duplicateBusiness,
            'duplicate_bounding_boxes' => $duplicateBbox,
            'duplicate_source_rows' => $duplicatePageColRow,
            'duplicate_business_removed_estimate' => $duplicateBusinessRemoved,
            'categories' => $categories,
            'expected_firm_count_approx' => $expectedApprox,
            'overcount_vs_rows_with_firm' => $overcount,
            'overcount_vs_expected_approx' => max(0, $total - $expectedApprox),
            'ui_count_label' => 'parsed_firm_count = rows with firm_name (active parse); UI shows valid unique firms when valid_firm_count is set',
            'reconciliation' => [
                'source_record_candidates' => $total,
                'valid_complete_records' => $validComplete,
                'invalid_candidates' => $categories['missing_ca_name'] + $categories['missing_city'] + $categories['other_invalid_candidate'],
                'duplicate_source_records' => $duplicateSource,
                'rejected_noise' => $invalidNoise,
                'equation_lhs' => $total,
                'equation_rhs' => $validComplete
                    + ($categories['missing_ca_name'] + $categories['missing_city'] + $categories['other_invalid_candidate'])
                    + $duplicateSource
                    + $invalidNoise,
                'equation_balances' => $total === (
                    $validComplete
                    + ($categories['missing_ca_name'] + $categories['missing_city'] + $categories['other_invalid_candidate'])
                    + $duplicateSource
                    + $invalidNoise
                ),
                'final_unique_records' => $finalUnique,
            ],
            'suspicious_row_count' => count($suspicious),
            'csv_path' => null,
        ];

        if ($csvPath !== null) {
            $dir = dirname($csvPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $fh = fopen($csvPath, 'w');
            fputcsv($fh, ['id', 'sequence_no', 'category', 'firm_name', 'ca_name', 'city', 'page_number', 'match_status']);
            foreach ($suspicious as $row) {
                fputcsv($fh, [
                    $row['id'], $row['sequence_no'], $row['category'], $row['firm_name'],
                    $row['ca_name'], $row['city'], $row['page_number'], $row['match_status'],
                ]);
            }
            fclose($fh);
            $report['csv_path'] = $csvPath;
        }

        return $report;
    }

    private function classifyRow(
        string $firmName,
        string $caName,
        string $city,
        OcrEntityClassificationService $entities,
        OcrCityHeadingDetector $headings,
    ): string {
        if ($firmName === '' && $caName === '' && $city === '') {
            return 'header_footer_noise';
        }
        if ($firmName === '' && $city !== '' && $headings->isHeading($city) && $caName === '') {
            return 'city_heading_incorrectly_counted';
        }
        if ($firmName !== '' && $headings->isHeading($firmName) && ! $entities->isFirmName($firmName)) {
            return 'city_heading_incorrectly_counted';
        }
        if ($firmName === '') {
            if ($caName !== '' && $entities->isPerson($caName)) {
                return 'ca_person_as_firm';
            }
            if ($entities->isAddress($caName) || $entities->isAddress($city)) {
                return 'address_only_row';
            }
            if (preg_match('/^\d{4,}$/', $caName.$city)) {
                return 'number_only_row';
            }

            return 'missing_firm_name';
        }
        if ($entities->isPerson($firmName) && ! $entities->isFirmName($firmName)) {
            return 'ca_person_as_firm';
        }
        if ($entities->isAddress($firmName) && ! $entities->isFirmName($firmName)) {
            return 'address_only_row';
        }
        if (preg_match('/^\d{4,}$/', $firmName)) {
            return 'number_only_row';
        }
        if ($caName !== '' && $city !== '') {
            return 'valid_complete';
        }
        if ($caName === '') {
            return 'missing_ca_name';
        }

        return 'missing_city';
    }
}
