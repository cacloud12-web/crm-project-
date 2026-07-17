<?php

namespace App\Services\Ocr;

use App\Jobs\ImportMasterCaOcrJob;
use App\Jobs\MapOcrParsedFirmsJob;
use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Models\OcrParsedMember;
use App\Services\Mapping\DataNormalizationService;
use App\Services\Mapping\MasterDataMappingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the structure parser and persists firms/members in memory-safe batches.
 * Staging only for extraction — Master mapping is delegated to MasterDataMappingService.
 */
class OcrStructurePersistService
{
    private const FIRM_INSERT_CHUNK = 100;

    public function __construct(
        private readonly OcrStructureParserService $parser,
        private readonly DataNormalizationService $normalizer,
        private readonly MasterDataMappingService $mappingService,
        private readonly MasterCaDirectImportService $masterCaImporter,
    ) {}

    public function parseAndPersist(OcrDocument $document): OcrDocument
    {
        $text = (string) ($document->displayText() ?? '');
        $layout = is_array($document->structured_data) ? $document->structured_data : [];
        $layoutBlockCount = $this->countLayoutBlocks($layout);

        $document->update([
            'parse_status' => 'processing',
            'processing_progress' => 'Structuring OCR results',
        ]);

        Log::info('ocr.document.structure_start', [
            'ocr_document_id' => $document->id,
            'raw_text_length' => mb_strlen($text),
            'layout_block_count' => $layoutBlockCount,
            'parser' => 'OcrStructureParserService',
        ]);

        try {
            if (trim($text) === '' && $layoutBlockCount < 1) {
                $this->replaceParsedRecords($document, []);
                $quality = [
                    'status' => 'empty',
                    'warnings' => ['Raw OCR text missing.'],
                ];
                $completeness = [
                    'needs_review' => true,
                    'warnings' => ['Raw OCR text missing.'],
                    'firm_count' => 0,
                ];
                $this->storeParseOutcome($document, [
                    'parser_version' => OcrStructureParserService::PARSER_VERSION,
                    'parse_mode' => 'none',
                    'firm_count' => 0,
                    'heading_count' => 0,
                    'rows_detected' => 0,
                    'skipped_blocks' => 0,
                    'strategy' => 'empty',
                    'error' => [
                        'code' => 'raw_ocr_text_missing',
                        'message' => 'Raw OCR text is missing. Re-run OCR or paste corrected text, then re-structure.',
                    ],
                    'quality_report' => $quality,
                ], $completeness, 'completed');

                $document->update(['processing_progress' => 'Completed']);

                return $document->fresh(['parsedFirms.members']);
            }

            $parsed = $this->parser->parse($text, $layout);
            $quality = $this->buildQualityReport($document, $parsed, $text);
            $completeness = $this->evaluateCompleteness($document, $parsed, $text, $quality);
            $this->replaceParsedRecords($document, $parsed['firms']);

            if ((int) ($parsed['firm_count'] ?? 0) === 0 && trim($text) !== '') {
                $completeness['needs_review'] = true;
                $completeness['warnings'][] = 'No candidate firm headings produced structured firms.';
            }

            $this->storeParseOutcome($document, [
                'parser_version' => $parsed['parser_version'],
                'parse_mode' => $parsed['parse_mode'] ?? null,
                'firm_count' => $parsed['firm_count'],
                'heading_count' => $parsed['heading_count'] ?? null,
                'rows_detected' => $parsed['rows_detected'] ?? $parsed['firm_count'],
                'skipped_blocks' => $parsed['skipped_blocks'] ?? 0,
                'skipped_details' => $parsed['skipped_details'] ?? [],
                'missing_serials' => $parsed['missing_serials'] ?? [],
                'duplicate_serials' => $parsed['duplicate_serials'] ?? [],
                'duplicate_firms' => $parsed['duplicate_firms'] ?? [],
                'strategy' => $parsed['strategy'] ?? ($parsed['parse_mode'] ?? 'line_based'),
                'candidate_firm_count' => $parsed['candidate_firm_count'] ?? ($parsed['heading_count'] ?? null),
                'page_stats' => $parsed['page_stats'] ?? null,
                'error' => null,
                'quality_report' => $quality,
            ], $completeness, 'completed');

            Log::info('ocr.document.structured', [
                'ocr_document_id' => $document->id,
                'import_type' => $document->import_type,
                'firm_count' => $parsed['firm_count'],
                'rows_detected' => $parsed['rows_detected'] ?? $parsed['firm_count'],
                'heading_count' => $parsed['heading_count'] ?? null,
                'candidate_firm_count' => $parsed['candidate_firm_count'] ?? null,
                'skipped_blocks' => $parsed['skipped_blocks'] ?? 0,
                'skipped_details' => $parsed['skipped_details'] ?? [],
                'parse_mode' => $parsed['parse_mode'] ?? null,
                'strategy' => $parsed['strategy'] ?? null,
                'parser_version' => $parsed['parser_version'],
                'quality_report' => $quality,
                'completeness' => $completeness,
            ]);

            $this->dispatchPostParse($document->fresh(), (int) $parsed['firm_count']);
        } catch (Throwable $exception) {
            $error = $this->classifyStructureException($exception, $text);

            Log::error('ocr.pipeline.structure_failed', [
                'step' => 'parse_and_persist',
                'ocr_document_id' => $document->id,
                'error_code' => $error['code'],
                'error_message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            $structured = is_array($document->fresh()->structured_data) ? $document->fresh()->structured_data : [];
            $structured['parsed'] = array_merge(
                is_array($structured['parsed'] ?? null) ? $structured['parsed'] : [],
                [
                    'parsed_at' => now()->toIso8601String(),
                    'error' => $error,
                ],
            );

            $document->update([
                'parse_status' => 'failed',
                'error_code' => $error['code'],
                'error_message' => mb_substr($error['message'].' | '.$exception->getMessage(), 0, 2000),
                'processing_progress' => 'Parsing failed — retry available',
                'structured_data' => $structured,
            ]);

            throw $exception;
        }

        return $document->fresh(['parsedFirms.members']);
    }

    /**
     * @param  array<string, mixed>  $parsedMeta
     * @param  array<string, mixed>  $completeness
     */
    private function storeParseOutcome(OcrDocument $document, array $parsedMeta, array $completeness, string $status): void
    {
        $structured = is_array($document->structured_data) ? $document->structured_data : [];
        $structured['parsed'] = array_merge($parsedMeta, [
            'parsed_at' => now()->toIso8601String(),
            'completeness' => $completeness,
        ]);

        $isMaster = $document->isMasterCaImport();
        $progress = 'Failed';
        if ($status !== 'failed') {
            if ($isMaster) {
                $progress = ! empty($completeness['needs_review'])
                    ? 'Completed with extraction warnings — validating next'
                    : 'Validating official Master records';
            } else {
                $progress = ! empty($completeness['needs_review'])
                    ? 'Completed with extraction warnings — mapping next'
                    : 'Mapping to Master Data';
            }
        }

        $document->update([
            'parse_status' => $status,
            'parsed_firm_count' => (int) ($parsedMeta['firm_count'] ?? 0),
            'parsed_at' => now(),
            'structured_data' => $structured,
            'processing_progress' => $progress,
        ]);
    }

    /**
     * @return array{code: string, message: string}
     */
    private function classifyStructureException(Throwable $exception, string $text): array
    {
        $message = trim($exception->getMessage());

        if ($exception instanceof QueryException || str_contains($message, 'no column named') || str_contains($message, 'Unknown column')) {
            return [
                'code' => 'schema_mismatch',
                'message' => 'Structured firm storage schema is missing required columns. Apply pending OCR staging migrations, then re-structure.',
            ];
        }

        if (trim($text) === '') {
            return [
                'code' => 'raw_ocr_text_missing',
                'message' => 'Raw OCR text is missing.',
            ];
        }

        if (str_contains(mb_strtolower($message), 'json')) {
            return [
                'code' => 'invalid_json_output',
                'message' => 'Parser produced invalid structured output.',
            ];
        }

        return [
            'code' => 'parser_exception',
            'message' => 'Structured parsing failed. You can retry structuring or review the raw text.',
        ];
    }

    /**
     * @param  array<string, mixed>  $layout
     */    /**
     * @param  array<string, mixed>  $layout
     */
    private function countLayoutBlocks(array $layout): int
    {
        $pages = $layout['pages'] ?? null;
        if (! is_array($pages)) {
            return 0;
        }

        $count = 0;
        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }
            if (isset($page['paragraphs']) && is_array($page['paragraphs'])) {
                $count += count($page['paragraphs']);
            } elseif (isset($page['blocks']) && is_array($page['blocks'])) {
                $count += count($page['blocks']);
            } else {
                $count += (int) ($page['paragraph_count'] ?? 0);
            }
        }

        return $count;
    }
    private function dispatchPostParse(OcrDocument $document, int $firmCount): void
    {
        if ($firmCount < 1) {
            $document->update(['processing_progress' => 'Completed']);

            return;
        }

        if ($document->isMasterCaImport()) {
            $this->dispatchMasterCaImport($document, $firmCount);

            return;
        }

        $this->dispatchSalesMapping($document, $firmCount);
    }

    private function dispatchMasterCaImport(OcrDocument $document, int $firmCount): void
    {
        $actorId = auth()->id();
        $syncMax = (int) config('crm_mapping.master_ca_sync_max_firms', 100);

        Log::info('ocr.pipeline.step', [
            'step' => 'master_ca_import_start',
            'ocr_document_id' => $document->id,
            'firm_count' => $firmCount,
            'inline' => $firmCount <= $syncMax,
        ]);

        if ($firmCount <= $syncMax) {
            try {
                $stats = $this->masterCaImporter->processDocument((int) $document->id, $actorId ? (int) $actorId : null);
                Log::info('ocr.pipeline.step', [
                    'step' => 'master_ca_import_completed',
                    'ocr_document_id' => $document->id,
                    'stats' => $stats,
                ]);

                return;
            } catch (Throwable $e) {
                Log::error('ocr.pipeline.inline_master_import_failed', [
                    'ocr_document_id' => $document->id,
                    'error_message' => $e->getMessage(),
                ]);
                $document->update([
                    'error_code' => 'master_import_failed',
                    'error_message' => mb_substr($e->getMessage(), 0, 2000),
                    'processing_progress' => 'Import failed — queued for retry',
                ]);
            }
        }

        ImportMasterCaOcrJob::dispatch((int) $document->id, $actorId ? (int) $actorId : null);
        $document->update(['processing_progress' => 'Importing official Master CA records']);
        Log::info('ocr.pipeline.step', [
            'step' => 'master_ca_import_job_dispatched',
            'ocr_document_id' => $document->id,
            'firm_count' => $firmCount,
        ]);
    }

    private function dispatchSalesMapping(OcrDocument $document, int $firmCount): void
    {
        if (! filter_var(config('crm_mapping.queue_after_ocr_parse', true), FILTER_VALIDATE_BOOLEAN)) {
            $document->update(['processing_progress' => 'Completed']);

            return;
        }

        $syncMax = (int) config('crm_mapping.sync_max_firms', 50);
        $actorId = auth()->id();

        Log::info('ocr.pipeline.step', [
            'step' => 'sales_mapping_start',
            'ocr_document_id' => $document->id,
            'import_type' => $document->import_type ?: OcrDocument::IMPORT_SALES_TEAM,
            'firm_count' => $firmCount,
            'inline' => $firmCount <= $syncMax,
        ]);

        if ($firmCount <= $syncMax) {
            try {
                $stats = $this->mappingService->processOcrDocument((int) $document->id, $actorId ? (int) $actorId : null);
                $this->storeMappingProgress($document, $stats);
                $document->update([
                    'processing_progress' => 'Completed',
                    'error_code' => null,
                    'error_message' => null,
                ]);
                Log::info('ocr.pipeline.step', [
                    'step' => 'sales_mapping_completed',
                    'ocr_document_id' => $document->id,
                    'stats' => collect($stats)->except('decisions')->all(),
                ]);

                return;
            } catch (Throwable $e) {
                Log::error('ocr.pipeline.inline_mapping_failed', [
                    'step' => 'mapping_inline',
                    'ocr_document_id' => $document->id,
                    'error_code' => 'mapping_failed',
                    'error_message' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
                $document->update([
                    'error_code' => 'mapping_failed',
                    'error_message' => mb_substr($e->getMessage(), 0, 2000),
                    'processing_progress' => 'Mapping failed — queued for retry',
                ]);
            }
        }

        MapOcrParsedFirmsJob::dispatch((int) $document->id, $actorId ? (int) $actorId : null);
        $document->update(['processing_progress' => 'Queued for sales-team Master mapping']);
        Log::info('ocr.pipeline.step', [
            'step' => 'sales_mapping_job_dispatched',
            'ocr_document_id' => $document->id,
            'firm_count' => $firmCount,
        ]);
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function storeMappingProgress(OcrDocument $document, array $stats): void
    {
        $structured = is_array($document->structured_data) ? $document->structured_data : [];
        $structured['mapping'] = [
            'processed' => (int) ($stats['processed'] ?? 0),
            'auto_created' => (int) ($stats['auto_created'] ?? 0),
            'auto_updated' => (int) ($stats['auto_updated'] ?? 0),
            'needs_review' => (int) ($stats['needs_review'] ?? 0),
            'conflicts' => (int) ($stats['conflicts'] ?? 0),
            'skipped' => (int) ($stats['skipped'] ?? 0),
            'mapped_at' => now()->toIso8601String(),
        ];
        $document->update(['structured_data' => $structured]);
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $quality
     * @return array<string, mixed>
     */
    private function evaluateCompleteness(OcrDocument $document, array $parsed, string $text, array $quality = []): array
    {
        $pageCount = (int) ($document->page_count ?? 0);
        $pagesWithText = (int) ($quality['pages_with_text'] ?? 0);
        if ($pagesWithText < 1 && trim($text) !== '') {
            $pagesWithText = max(1, $pageCount > 0 ? $pageCount : 1);
        }
        if ($pageCount < 1 && trim($text) !== '') {
            $pageCount = $pagesWithText;
        }

        $firmCount = (int) ($parsed['firm_count'] ?? 0);
        $rowsDetected = (int) ($parsed['rows_detected'] ?? ($parsed['heading_count'] ?? $firmCount));
        $skipped = (int) ($parsed['skipped_blocks'] ?? 0);
        $gapRatio = $rowsDetected > 0 ? max(0, $rowsDetected - $firmCount) / $rowsDetected : 0.0;

        $needsReview = false;
        $warnings = [];
        // Only warn when a page truly has zero OCR text (not missing "Page N" markers).
        if ($pageCount > 0 && $pagesWithText > 0 && $pagesWithText < $pageCount) {
            $needsReview = true;
            $warnings[] = ($pageCount - $pagesWithText).' page(s) produced zero OCR text.';
        }
        if ($skipped > 0) {
            $needsReview = true;
            $warnings[] = $skipped.' firm block(s) were skipped as ambiguous.';
            foreach (array_slice($parsed['skipped_details'] ?? [], 0, 10) as $detail) {
                if (! is_array($detail)) {
                    continue;
                }
                $warnings[] = 'Skipped: '.($detail['reason'] ?? 'unknown')
                    .(isset($detail['snippet']) && $detail['snippet'] !== '' ? ' — '.$detail['snippet'] : '');
            }
        }
        if ($rowsDetected >= 3 && $gapRatio >= 0.25) {
            $needsReview = true;
            $warnings[] = 'Parsed firm count ('.$firmCount.') is materially lower than detected rows ('.$rowsDetected.').';
        }
        if ($firmCount === 0 && trim($text) !== '') {
            $needsReview = true;
            $warnings[] = 'OCR text present but no firms were parsed.';
        }

        return [
            'page_count' => $pageCount,
            'pages_with_text' => $pagesWithText,
            'firm_count' => $firmCount,
            'rows_detected' => $rowsDetected,
            'heading_count' => $rowsDetected,
            'skipped_blocks' => $skipped,
            'parsing_accuracy' => $quality['parsing_accuracy'] ?? null,
            'needs_review' => $needsReview,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function buildQualityReport(OcrDocument $document, array $parsed, string $text): array
    {
        $pageCount = (int) ($document->page_count ?? 0);
        $structured = is_array($document->structured_data) ? $document->structured_data : [];
        $pageMetas = is_array($structured['pages'] ?? null) ? $structured['pages'] : [];

        $pagesWithText = 0;
        $perPage = [];
        foreach ($pageMetas as $pageMeta) {
            if (! is_array($pageMeta)) {
                continue;
            }
            $num = (int) ($pageMeta['page_number'] ?? 0);
            $hasText = (int) ($pageMeta['paragraph_count'] ?? 0) > 0
                || trim((string) ($pageMeta['text'] ?? '')) !== ''
                || (int) ($pageMeta['line_count'] ?? 0) > 0;
            if ($hasText) {
                $pagesWithText++;
            }
            if ($num > 0) {
                $perPage[$num] = [
                    'page_number' => $num,
                    'has_text' => $hasText,
                    'paragraph_count' => (int) ($pageMeta['paragraph_count'] ?? 0),
                    'firms_on_page' => 0,
                ];
            }
        }

        // Fallback: page markers in text, else assume all declared pages have text when OCR succeeded.
        if ($pagesWithText < 1) {
            $markerPages = preg_match_all('/(?:^|\n)\s*---\s*Page\s+(\d+)\s*---/i', $text);
            $pagesWithText = $markerPages > 0 ? (int) $markerPages : ($pageCount > 0 && trim($text) !== '' ? $pageCount : (trim($text) !== '' ? 1 : 0));
        }

        $rowsDetected = (int) ($parsed['rows_detected'] ?? ($parsed['heading_count'] ?? 0));
        $firmsParsed = (int) ($parsed['firm_count'] ?? 0);
        $missingSerials = is_array($parsed['missing_serials'] ?? null) ? $parsed['missing_serials'] : [];
        $duplicateSerials = is_array($parsed['duplicate_serials'] ?? null) ? $parsed['duplicate_serials'] : [];
        $duplicateFirms = is_array($parsed['duplicate_firms'] ?? null) ? $parsed['duplicate_firms'] : [];

        foreach ($parsed['firms'] ?? [] as $firm) {
            $page = (int) ($firm['page_number'] ?? 0);
            if ($page > 0 && isset($perPage[$page])) {
                $perPage[$page]['firms_on_page']++;
            }
        }

        $expected = max($rowsDetected, $firmsParsed);
        $accuracy = $expected > 0
            ? round(min(100, ($firmsParsed / $expected) * 100), 2)
            : ($firmsParsed > 0 ? 100.0 : 0.0);

        $report = [
            'total_pages' => $pageCount,
            'pages_with_text' => $pagesWithText,
            'total_rows_detected' => $rowsDetected,
            'total_firms_parsed' => $firmsParsed,
            'missing_rows' => $missingSerials,
            'missing_row_count' => count($missingSerials),
            'duplicate_rows' => $duplicateSerials,
            'duplicate_row_count' => count($duplicateSerials),
            'duplicate_firms' => $duplicateFirms,
            'unique_firm_estimate' => (int) ($parsed['unique_firm_estimate'] ?? max(0, $firmsParsed - count($duplicateFirms))),
            'ocr_confidence' => $document->average_confidence,
            'parsing_accuracy' => $accuracy,
            'parse_mode' => $parsed['parse_mode'] ?? null,
            'skipped_details' => $parsed['skipped_details'] ?? [],
            'per_page' => array_values($perPage),
            'pipeline_counts' => [
                'pdf_pages' => $pageCount,
                'ocr_pages_with_text' => $pagesWithText,
                'rows_detected' => $rowsDetected,
                'firms_parsed' => $firmsParsed,
                'ready_for_mapping' => $firmsParsed,
            ],
        ];

        Log::info('ocr.document.quality_report', array_merge(
            ['ocr_document_id' => $document->id],
            $report['pipeline_counts'],
            [
                'parsing_accuracy' => $accuracy,
                'missing_rows' => $missingSerials,
                'duplicate_rows' => $duplicateSerials,
            ],
        ));

        return $report;
    }

    /**
     * @param  list<array<string, mixed>>  $firms
     */
    private function replaceParsedRecords(OcrDocument $document, array $firms): void
    {
        // Replace-on-retry keeps staging idempotent (no duplicate firm rows).
        DB::transaction(function () use ($document, $firms) {
            OcrParsedMember::query()
                ->whereIn('ocr_parsed_firm_id', OcrParsedFirm::query()
                    ->where('ocr_document_id', $document->id)
                    ->select('id'))
                ->delete();

            OcrParsedFirm::query()->where('ocr_document_id', $document->id)->delete();

            foreach (array_chunk($firms, self::FIRM_INSERT_CHUNK) as $chunk) {
                foreach ($chunk as $firmData) {
                    $members = $firmData['members'] ?? [];
                    unset($firmData['members']);

                    $rawFirmName = $this->rawString($firmData['firm_name'] ?? ($firmData['raw_firm_name'] ?? null));
                    $rawFrn = $this->rawString($firmData['frn'] ?? null);
                    $rawGst = $this->rawString($firmData['gst_no'] ?? null);
                    $rawPan = $this->rawString($firmData['pan_no'] ?? null);
                    $rawPhone = $this->rawString($firmData['phone'] ?? null);
                    $rawEmail = $this->rawString($firmData['email'] ?? null);
                    $rawAddress = $this->rawString($firmData['address'] ?? null);
                    $rawCity = $this->rawString($firmData['city'] ?? null);
                    $rawState = $this->rawString($firmData['state'] ?? null);
                    $rawPincode = $this->rawString($firmData['pincode'] ?? null);

                    $firm = OcrParsedFirm::query()->create([
                        'ocr_document_id' => $document->id,
                        'sequence_no' => (int) ($firmData['sequence_no'] ?? 1),
                        'raw_firm_name' => $rawFirmName,
                        'firm_name' => $rawFirmName,
                        'normalized_firm_name' => $this->normalizer->firmName($rawFirmName),
                        'firm_type' => $firmData['firm_type'] ?? null,
                        'frn' => $rawFrn,
                        'gst_no' => $rawGst,
                        'pan_no' => $rawPan,
                        'address' => $rawAddress,
                        'city' => $rawCity,
                        'district' => $this->rawString($firmData['district'] ?? null),
                        'state' => $rawState,
                        'pincode' => $rawPincode,
                        'phone' => $rawPhone,
                        'email' => $rawEmail,
                        'website' => $this->rawString($firmData['website'] ?? null),
                        'partner_count' => count($members) > 0 ? count($members) : ($firmData['partner_count'] ?? null),
                        'review_status' => $firmData['review_status'] ?? OcrParsedFirm::REVIEW_PENDING,
                        'overall_confidence' => $firmData['overall_confidence'] ?? null,
                        'page_number' => $firmData['page_number'] ?? null,
                        'source_data' => [
                            'raw' => [
                                'firm_name' => $rawFirmName,
                                'frn' => $rawFrn,
                                'gst_no' => $rawGst,
                                'pan_no' => $rawPan,
                                'phone' => $rawPhone,
                                'email' => $rawEmail,
                                'address' => $rawAddress,
                                'city' => $rawCity,
                                'state' => $rawState,
                                'pincode' => $rawPincode,
                            ],
                            'normalized' => [
                                'firm_name' => $this->normalizer->firmName($rawFirmName),
                                'frn' => $this->normalizer->frn($rawFrn),
                                'gst_no' => $this->normalizer->gst($rawGst),
                                'pan_no' => $this->normalizer->pan($rawPan),
                                'phone' => $this->normalizer->phone($rawPhone),
                                'email' => $this->normalizer->email($rawEmail),
                                'pincode' => $this->normalizer->postalCode($rawPincode),
                            ],
                            'unclassified_lines' => $firmData['unclassified_lines'] ?? [],
                            'field_meta' => $firmData['field_meta'] ?? null,
                            'source_lines' => $firmData['source_lines'] ?? null,
                        ],
                        'field_meta' => $firmData['field_meta'] ?? null,
                    ]);

                    $memberRows = [];
                    foreach ($members as $index => $member) {
                        $rawCaName = $this->rawString($member['ca_name'] ?? ($member['raw_ca_name'] ?? null));
                        $rawMemNo = $this->rawString($member['membership_no'] ?? null);
                        $rawMemMobile = $this->rawString($member['mobile'] ?? null);
                        $rawMemEmail = $this->rawString($member['email'] ?? null);
                        $rawMemPan = $this->rawString($member['pan_no'] ?? null);
                        $memberRows[] = [
                            'ocr_parsed_firm_id' => $firm->id,
                            'sequence_no' => (int) ($member['sequence_no'] ?? ($index + 1)),
                            'raw_ca_name' => $rawCaName,
                            'ca_name' => $rawCaName,
                            'normalized_ca_name' => $this->normalizer->caName($rawCaName),
                            'membership_no' => $rawMemNo,
                            'mobile' => $rawMemMobile,
                            'email' => $rawMemEmail,
                            'pan_no' => $rawMemPan,
                            'role' => $member['role'] ?? null,
                            'is_primary' => (bool) ($member['is_primary'] ?? ($index === 0)),
                            'overall_confidence' => $member['overall_confidence'] ?? null,
                            'page_number' => $member['page_number'] ?? ($firmData['page_number'] ?? null),
                            'review_status' => 'pending',
                            'source_data' => json_encode([
                                'raw' => [
                                    'ca_name' => $rawCaName,
                                    'membership_no' => $rawMemNo,
                                    'mobile' => $rawMemMobile,
                                    'email' => $rawMemEmail,
                                    'pan_no' => $rawMemPan,
                                ],
                                'normalized' => [
                                    'ca_name' => $this->normalizer->caName($rawCaName),
                                    'membership_no' => $this->normalizer->membershipNumber($rawMemNo),
                                    'mobile' => $this->normalizer->phone($rawMemMobile),
                                    'email' => $this->normalizer->email($rawMemEmail),
                                    'pan_no' => $this->normalizer->pan($rawMemPan),
                                ],
                                'field_meta' => $member['field_meta'] ?? null,
                            ]),
                            'field_meta' => isset($member['field_meta']) ? json_encode($member['field_meta']) : null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }

                    if ($memberRows !== []) {
                        OcrParsedMember::query()->insert($memberRows);
                    }
                }
            }
        });
    }

    private function rawString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
