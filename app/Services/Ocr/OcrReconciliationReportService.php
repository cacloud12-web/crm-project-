<?php

namespace App\Services\Ocr;

use App\Http\Resources\OcrParsedFirmResource;
use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use Illuminate\Http\Request;

/**
 * Single source of truth for OCR document reconciliation counters.
 * Both Structured Firms and Master CA summary panels must use this report.
 */
class OcrReconciliationReportService
{
    /**
     * @return array{
     *     detected_rows: int,
     *     parsed_rows: int,
     *     valid_three_field_rows: int,
     *     exact_verified: int,
     *     needs_review: int,
     *     conflicts: int,
     *     invalid: int,
     *     rejected: int,
     *     failed: int,
     *     row_coverage_percent: float|int,
     *     accounted_for: int,
     *     every_source_row_accounted: bool
     * }
     */
    public function buildForDocument(OcrDocument $document): array
    {
        $firms = OcrParsedFirm::query()
            ->where('ocr_document_id', $document->id)
            ->orderBy('sequence_no')
            ->get();

        $structured = is_array($document->structured_data) ? $document->structured_data : [];
        $quality = is_array($structured['parsed']['quality_report'] ?? null)
            ? $structured['parsed']['quality_report']
            : (is_array($structured['quality_report'] ?? null) ? $structured['quality_report'] : []);

        $detected = (int) ($quality['total_rows_detected']
            ?? $quality['total_source_rows']
            ?? $document->parsed_firm_count
            ?? $firms->count());
        $parsed = $firms->count();

        $exact = 0;
        $review = 0;
        $conflict = 0;
        $invalid = 0;
        $rejected = 0;
        $failed = 0;
        $validThree = 0;

        $request = Request::create('/');
        foreach ($firms as $firm) {
            $payload = (new OcrParsedFirmResource($firm))->toArray($request);
            $status = (string) ($payload['status'] ?? 'Needs Review');
            $firmName = trim((string) ($payload['firm_name'] ?? ''));
            $caName = trim((string) ($payload['ca_name'] ?? ''));
            $city = trim((string) ($payload['city'] ?? ''));
            if ($firmName !== '' && $caName !== '' && $city !== '' && $status !== 'Invalid') {
                $validThree++;
            }
            match ($status) {
                'Verified' => $exact++,
                'Conflict' => $conflict++,
                'Invalid' => $invalid++,
                'Rejected' => $rejected++,
                default => $review++,
            };
            if (in_array((string) $firm->match_status, ['failed'], true)) {
                $failed++;
            }
        }

        $accounted = $parsed;
        $coverage = $detected > 0 ? round(($accounted / $detected) * 100, 2) : 0;

        return [
            'detected_rows' => $detected,
            'parsed_rows' => $parsed,
            'valid_three_field_rows' => $validThree,
            'exact_verified' => $exact,
            'needs_review' => $review,
            'conflicts' => $conflict,
            'invalid' => $invalid,
            'rejected' => $rejected,
            'failed' => $failed,
            'row_coverage_percent' => $coverage,
            'accounted_for' => $accounted,
            'every_source_row_accounted' => $accounted === $detected,
        ];
    }

    /**
     * Persist canonical reconciliation onto document structured_data (after match/import).
     */
    public function refreshDocumentReport(OcrDocument $document): array
    {
        $report = $this->buildForDocument($document);
        $structured = is_array($document->structured_data) ? $document->structured_data : [];
        $parsed = is_array($structured['parsed'] ?? null) ? $structured['parsed'] : [];
        $quality = is_array($parsed['quality_report'] ?? null) ? $parsed['quality_report'] : [];
        $quality['reconciliation'] = $report;
        $quality['valid_three_field_rows'] = $report['valid_three_field_rows'];
        $quality['invalid_scoped_rows'] = $report['invalid'];
        $quality['row_coverage'] = $report['row_coverage_percent'];
        $quality['pipeline_counts'] = array_merge($quality['pipeline_counts'] ?? [], [
            'detected_rows' => $report['detected_rows'],
            'parsed_rows' => $report['parsed_rows'],
            'valid_three_field_rows' => $report['valid_three_field_rows'],
            'exact_matches' => $report['exact_verified'],
            'exact_verified' => $report['exact_verified'],
            'needs_review' => $report['needs_review'],
            'conflicts' => $report['conflicts'],
            'invalid' => $report['invalid'],
            'rejected' => $report['rejected'],
            'failed' => $report['failed'],
            'accounted_for' => $report['accounted_for'],
            'row_coverage' => $report['row_coverage_percent'],
        ]);
        $parsed['quality_report'] = $quality;
        $parsed['reconciliation'] = $report;
        $structured['parsed'] = $parsed;
        $structured['reconciliation'] = $report;
        $structured['master_import'] = array_merge(
            is_array($structured['master_import'] ?? null) ? $structured['master_import'] : [],
            [
                'processed' => $report['parsed_rows'],
                'verified' => $report['exact_verified'],
                'review' => $report['needs_review'],
                'duplicates' => $report['conflicts'],
                'failed' => $report['failed'],
                'canonical_report' => $report,
            ],
        );
        $document->update(['structured_data' => $structured]);

        return $report;
    }
}
