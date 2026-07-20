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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
        private readonly OcrDocumentAiTableParser $tableParser,
        private readonly OcrLayoutDirectoryParser $layoutParser,
        private readonly OcrSourceVerificationService $sourceVerifier,
    ) {}

    public function parseAndPersist(OcrDocument $document): OcrDocument
    {
        $text = (string) ($document->displayText() ?? '');
        $structuredExisting = is_array($document->structured_data) ? $document->structured_data : [];
        $layoutBlockCount = $this->countLayoutBlocks($structuredExisting);

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
                $quality = ['status' => 'empty', 'warnings' => ['Raw OCR text missing.']];
                $completeness = ['needs_review' => true, 'warnings' => ['Raw OCR text missing.'], 'firm_count' => 0];
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

            $structuredExisting = is_array($document->structured_data) ? $document->structured_data : [];
            $directoryProfile = (new OcrDirectoryProfileDetector)->detect($document, $structuredExisting, $text);
            $docAiTables = is_array($structuredExisting['tables'] ?? null) ? $structuredExisting['tables'] : [];
            if ($docAiTables === [] && is_array($structuredExisting['pages'] ?? null)) {
                foreach ($structuredExisting['pages'] as $page) {
                    if (is_array($page['tables'] ?? null)) {
                        foreach ($page['tables'] as $table) {
                            $docAiTables[] = $table;
                        }
                    }
                }
            }

            $tableParsed = $docAiTables !== [] ? $this->tableParser->parseTables($docAiTables) : null;
            if ($tableParsed !== null && ($tableParsed['firms'] ?? []) !== []) {
                $parsed = [
                    'parser_version' => OcrStructureParserService::PARSER_VERSION.'+tables',
                    'parse_mode' => $tableParsed['parse_mode'],
                    'directory_profile' => $directoryProfile,
                    'firm_count' => count($tableParsed['firms']),
                    'firms' => $tableParsed['firms'],
                    'rows_detected' => $tableParsed['rows_detected'],
                    'heading_count' => null,
                    'skipped_blocks' => 0,
                    'skipped_details' => [],
                    'missing_serials' => [],
                    'duplicate_serials' => [],
                    'duplicate_firms' => [],
                ];
            } elseif ($this->layoutParser->canParse($structuredExisting)) {
                $layoutParsed = $this->layoutParser->parse($structuredExisting, $directoryProfile);
                if ($layoutParsed !== null && ($layoutParsed['firms'] ?? []) !== []) {
                    $parsed = $layoutParsed;
                } else {
                    $parsed = $this->parser->parse($text, $structuredExisting);
                }
            } else {
                $parsed = $this->parser->parse($text, $structuredExisting);
            }
            $parsed['directory_profile'] = $parsed['directory_profile'] ?? $directoryProfile;

            $quality = $this->buildQualityReport($document, $parsed, $text);
            $completeness = $this->evaluateCompleteness($document, $parsed, $text, $quality);
            $candidateCount = count($parsed['firms'] ?? []);
            $this->replaceParsedRecords($document, $parsed['firms']);
            $document->refresh();
            $persistedCount = (int) ($document->parsed_firm_count ?? 0);
            $validFirmCount = Schema::hasColumn('ocr_documents', 'valid_firm_count')
                ? (int) ($document->valid_firm_count ?? $persistedCount)
                : $persistedCount;

            if ($persistedCount === 0 && trim($text) !== '') {
                $completeness['needs_review'] = true;
                $completeness['warnings'][] = 'No candidate firm headings produced structured firms.';
            }

            $structured = is_array($document->structured_data) ? $document->structured_data : $structuredExisting;
            // Preserve Document AI tables/entities while attaching parse quality.
            $structured['tables'] = $docAiTables;
            $reconciliation = $this->buildReconciliationReport($parsed['firms'] ?? [], $quality, $persistedCount, $validFirmCount);
            $quality['reconciliation'] = $reconciliation;
            $quality['pipeline_counts'] = array_merge($quality['pipeline_counts'] ?? [], [
                'exact_matches' => 0,
                'needs_review' => $reconciliation['needs_review'],
                'conflicts' => 0,
                'rejected' => 0,
                'failed' => $reconciliation['failed'],
                'accounted_for' => $reconciliation['accounted_for'],
                'candidate_blocks' => $candidateCount,
                'valid_unique_firms' => $validFirmCount,
                'persisted_firms' => $persistedCount,
            ]);

            $structured['directory_profile'] = $parsed['directory_profile'] ?? $directoryProfile;
            $structured['parsed'] = [
                'parser_version' => $parsed['parser_version'],
                'parse_mode' => $parsed['parse_mode'] ?? null,
                'directory_profile' => $parsed['directory_profile'] ?? $directoryProfile,
                'firm_count' => $persistedCount,
                'partner_count' => $parsed['partner_count'] ?? null,
                'candidate_firm_count' => $candidateCount,
                'valid_firm_count' => $validFirmCount,
                'heading_count' => $parsed['heading_count'] ?? null,
                'rows_detected' => $parsed['rows_detected'] ?? $candidateCount,
                'skipped_blocks' => $parsed['skipped_blocks'] ?? 0,
                'skipped_details' => $parsed['skipped_details'] ?? [],
                'missing_serials' => $parsed['missing_serials'] ?? [],
                'duplicate_serials' => $parsed['duplicate_serials'] ?? [],
                'duplicate_firms' => $parsed['duplicate_firms'] ?? [],
                'parsed_at' => now()->toIso8601String(),
                'completeness' => $completeness,
                'quality_report' => $quality,
                'reconciliation' => $reconciliation,
                'used_document_ai_tables' => $tableParsed !== null,
                'workflow_mode' => config('ocr_workflow.mode', 'firm_ca_city'),
            ];

            $isMaster = $document->isMasterCaImport();
            $document->update([
                'parse_status' => 'completed',
                'parsed_firm_count' => $persistedCount,
                'parsed_at' => now(),
                'structured_data' => $structured,
                'processing_progress' => $isMaster
                    ? (! empty($completeness['needs_review'])
                        ? 'Completed with extraction warnings — validating next'
                        : 'Validating official Master records')
                    : (! empty($completeness['needs_review'])
                        ? 'Completed with extraction warnings — mapping next'
                        : 'Mapping to Master Data'),
            ]);

            Log::info('ocr.document.structured', [
                'ocr_document_id' => $document->id,
                'import_type' => $document->import_type,
                'firm_count' => $persistedCount,
                'candidate_firm_count' => $candidateCount,
                'valid_firm_count' => $validFirmCount,
                'rows_detected' => $parsed['rows_detected'] ?? $candidateCount,
                'heading_count' => $parsed['heading_count'] ?? null,
                'skipped_blocks' => $parsed['skipped_blocks'] ?? 0,
                'skipped_details' => $parsed['skipped_details'] ?? [],
                'parse_mode' => $parsed['parse_mode'] ?? null,
                'parser_version' => $parsed['parser_version'],
                'used_document_ai_tables' => $tableParsed !== null,
                'quality_report' => $quality,
                'completeness' => $completeness,
            ]);

            $this->dispatchPostParse($document->fresh(), $persistedCount);
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
            $progress = $isMaster
                ? (! empty($completeness['needs_review'])
                    ? 'Completed with extraction warnings — validating next'
                    : 'Validating official Master records')
                : (! empty($completeness['needs_review'])
                    ? 'Completed with extraction warnings — mapping next'
                    : 'Mapping to Master Data');
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
        $syncMax = (int) config('crm_mapping.master_ca_sync_max_firms', 50);
        $queue = (string) config('queue.default', 'sync');
        // database/redis need `php artisan queue:work` — never block HTTP/upload with 200+ firm imports.
        $asyncQueueNeedsWorker = in_array($queue, ['database', 'redis', 'beanstalkd', 'sqs'], true);
        $runInline = ! $asyncQueueNeedsWorker && $firmCount <= $syncMax;

        Log::info('ocr.pipeline.step', [
            'step' => 'master_ca_import_start',
            'ocr_document_id' => $document->id,
            'firm_count' => $firmCount,
            'inline' => $runInline,
            'queue' => $queue,
            'sync_max' => $syncMax,
        ]);

        if ($runInline) {
            try {
                if (function_exists('set_time_limit')) {
                    @set_time_limit(max(180, min(900, 60 + ($firmCount * 2))));
                }
                $document->update(['processing_progress' => 'Importing official Master CA records']);
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
                    'exception' => $e::class,
                ]);
                $document->update([
                    'error_code' => 'master_import_failed',
                    'error_message' => mb_substr($e->getMessage(), 0, 2000),
                    'processing_progress' => 'Import failed — '.$e->getMessage(),
                ]);

                return;
            }
        }

        $document->update([
            'processing_progress' => sprintf(
                'Completed — %d firms ready for review (Master CA import queued; auto-write off)',
                $firmCount,
            ),
        ]);
        ImportMasterCaOcrJob::dispatch((int) $document->id, $actorId ? (int) $actorId : null)
            ->onQueue((string) config('document-ai.import_queue', 'ocr-import'));

        // Do not auto-drain ImportMasterCaOcrJob here. A long import (100–900s) inside
        // afterResponse + a separate queue:work causes reserved-job fights and SQLite locks.
        // Keep `php artisan queue:work --queue=ocr,default` running instead.

        Log::info('ocr.pipeline.step', [
            'step' => 'master_ca_import_job_dispatched',
            'ocr_document_id' => $document->id,
            'firm_count' => $firmCount,
            'queue' => $queue,
        ]);
    }

    /**
     * Finish Master CA imports left on "Importing…" when the queue worker never ran.
     */
    public function resumeStuckMasterCaImport(OcrDocument $document, ?int $actorId = null): ?array
    {
        if (! $document->isMasterCaImport() || $document->parse_status !== 'completed') {
            return null;
        }

        $progress = mb_strtolower((string) ($document->processing_progress ?? ''));
        $stuckImporting = str_contains($progress, 'importing') || str_contains($progress, 'queued for master ca');
        if (! $stuckImporting && ! str_contains($progress, 'import failed')) {
            return null;
        }

        $pending = OcrParsedFirm::query()
            ->where('ocr_document_id', $document->id)
            ->whereNull('crm_ca_id')
            ->where(function ($q) {
                $q->whereNull('match_status')
                    ->orWhereIn('match_status', ['pending', 'unmatched', 'needs_review', 'failed']);
            })
            ->count();

        if ($pending < 1) {
            $document->update(['processing_progress' => 'Completed']);

            return ['processed' => 0, 'resumed' => false];
        }

        Log::info('ocr.pipeline.step', [
            'step' => 'master_ca_import_resume',
            'ocr_document_id' => $document->id,
            'pending_firms' => $pending,
        ]);

        if (function_exists('set_time_limit')) {
            @set_time_limit(max(180, min(900, 60 + ($pending * 2))));
        }

        return $this->masterCaImporter->processDocument((int) $document->id, $actorId);
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
            'parsing_accuracy' => $quality['row_coverage'] ?? ($quality['parsing_accuracy'] ?? null),
            'row_coverage' => $quality['row_coverage'] ?? ($quality['parsing_accuracy'] ?? null),
            'valid_three_field_rows' => $quality['valid_three_field_rows'] ?? null,
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
        $rowCoverage = $expected > 0
            ? round(min(100, ($firmsParsed / $expected) * 100), 2)
            : ($firmsParsed > 0 ? 100.0 : 0.0);

        $mergeWarnings = 0;
        $splitWarnings = 0;
        $validThreeField = 0;
        $invalidRows = 0;
        foreach ($parsed['firms'] ?? [] as $firm) {
            if (! empty($firm['row_merge_suspected']) || ! empty($firm['merge_suspected'])) {
                if (! empty($firm['row_merge_evidence'])) {
                    $mergeWarnings++;
                }
            }
            if (! empty($firm['row_split_suspected']) || ! empty($firm['split_suspected'])) {
                $splitWarnings++;
            }
            $missing = is_array($firm['missing_required_fields'] ?? null) ? $firm['missing_required_fields'] : [];
            if ($missing === [] && ($firm['firm_name'] ?? null) && ($firm['ca_name'] ?? null) && ($firm['city'] ?? null)) {
                $validThreeField++;
            } else {
                $invalidRows++;
            }
        }

        $report = [
            'total_pages' => $pageCount,
            'pages_with_text' => $pagesWithText,
            'total_source_rows' => $rowsDetected,
            'total_rows_detected' => $rowsDetected,
            'total_ocr_rows' => $firmsParsed,
            'total_firms_parsed' => $firmsParsed,
            'valid_three_field_rows' => $validThreeField,
            'invalid_scoped_rows' => $invalidRows,
            'missing_rows' => $missingSerials,
            'missing_row_count' => count($missingSerials),
            'duplicate_rows' => $duplicateSerials,
            'duplicate_row_count' => count($duplicateSerials),
            'duplicate_firms' => $duplicateFirms,
            'unique_firm_estimate' => (int) ($parsed['unique_firm_estimate'] ?? max(0, $firmsParsed - count($duplicateFirms))),
            'ocr_confidence' => $document->average_confidence,
            // Row coverage = parsed/detected source rows. NEVER call this OCR accuracy.
            'row_coverage' => $rowCoverage,
            'parsing_accuracy' => $rowCoverage, // legacy key kept for older clients; UI must label as Row Coverage
            'parse_mode' => $parsed['parse_mode'] ?? null,
            'skipped_details' => $parsed['skipped_details'] ?? [],
            'per_page' => array_values($perPage),
            'merged_row_warnings' => $mergeWarnings,
            'split_row_warnings' => $splitWarnings,
            'debug_report' => $this->buildParseDebugReport($parsed, $validThreeField, $invalidRows),
            'pipeline_counts' => [
                'source_pages' => $pageCount,
                'pdf_pages' => $pageCount,
                'ocr_pages_with_text' => $pagesWithText,
                'source_rows' => $rowsDetected,
                'rows_detected' => $rowsDetected,
                'ocr_rows' => $firmsParsed,
                'parsed_rows' => $firmsParsed,
                'firms_parsed' => $firmsParsed,
                'valid_three_field_rows' => $validThreeField,
                'invalid_scoped_rows' => $invalidRows,
                'missing_rows' => count($missingSerials),
                'merged_row_warnings' => $mergeWarnings,
                'split_row_warnings' => $splitWarnings,
                'ready_for_mapping' => $firmsParsed,
                'row_coverage' => $rowCoverage,
                'reconcile_note' => 'row_coverage = accounted_source_rows / detected_source_rows; not OCR field accuracy',
            ],
        ];

        Log::info('ocr.document.quality_report', array_merge(
            ['ocr_document_id' => $document->id],
            $report['pipeline_counts'],
            [
                'row_coverage' => $rowCoverage,
                'missing_rows' => $missingSerials,
                'duplicate_rows' => $duplicateSerials,
            ],
        ));

        return $report;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function buildParseDebugReport(array $parsed, int $validThreeField, int $invalidRows): array
    {
        $entities = new OcrEntityClassificationService;
        $headings = new OcrCityHeadingDetector($entities);
        $missingCity = 0;
        $missingCa = 0;
        $missingFirm = 0;
        $addressAsFirm = 0;
        $cityAsFirm = 0;
        $mistakes = [];

        foreach ($parsed['firms'] ?? [] as $firm) {
            $firmName = trim((string) ($firm['firm_name'] ?? ''));
            $caName = trim((string) ($firm['ca_name'] ?? ''));
            $city = trim((string) ($firm['city'] ?? ''));
            if ($firmName === '') {
                $missingFirm++;
            }
            if ($caName === '') {
                $missingCa++;
            }
            if ($city === '') {
                $missingCity++;
            }
            if ($firmName !== '' && $entities->isAddressShape($firmName) && ! $entities->isFirmName($firmName)) {
                $addressAsFirm++;
                if (count($mistakes) < 100) {
                    $mistakes[] = ['type' => 'address_as_firm', 'firm_name' => $firmName];
                }
            }
            if ($firmName !== '' && $headings->isHeading($firmName) && ! $entities->isFirmName($firmName)) {
                $cityAsFirm++;
                if (count($mistakes) < 100) {
                    $mistakes[] = ['type' => 'city_heading_as_firm', 'firm_name' => $firmName];
                }
            }
            if ($caName !== '' && ($entities->isAddressShape($caName) || $entities->isAddress($caName))) {
                if (count($mistakes) < 100) {
                    $mistakes[] = ['type' => 'address_as_ca', 'ca_name' => $caName, 'firm_name' => $firmName];
                }
            }
        }

        foreach (array_slice($parsed['skipped_details'] ?? [], 0, 100) as $detail) {
            if (count($mistakes) >= 100) {
                break;
            }
            if (! is_array($detail)) {
                continue;
            }
            $mistakes[] = [
                'type' => (string) ($detail['reason'] ?? 'rejected_block'),
                'snippet' => (string) ($detail['snippet'] ?? ''),
            ];
        }

        return [
            'expected_visual_firms' => null,
            'parsed_firms' => (int) ($parsed['firm_count'] ?? 0),
            'duplicates_removed' => (int) ($parsed['duplicates_removed'] ?? count($parsed['duplicate_firms'] ?? [])),
            'address_blocks_rejected' => $addressAsFirm,
            'city_headings_rejected' => (int) ($parsed['heading_count'] ?? 0),
            'city_headings_as_firm' => $cityAsFirm,
            'missing_city_records' => $missingCity,
            'records_without_ca_name' => $missingCa,
            'records_without_firm_name' => $missingFirm,
            'records_without_city' => $missingCity,
            'valid_three_field_rows' => $validThreeField,
            'invalid_scoped_rows' => $invalidRows,
            'skipped_blocks' => (int) ($parsed['skipped_blocks'] ?? 0),
            'top_100_parsing_mistakes' => $mistakes,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $firms
     */
    private function replaceParsedRecords(OcrDocument $document, array $firms): void
    {
        $parseRunId = (string) Str::uuid();
        $candidateCount = count($firms);
        $seenSource = [];
        $toPersist = [];

        foreach ($firms as $firmData) {
            $firmData = $this->sanitizeFirmData($firmData);
            $firmName = trim((string) ($firmData['firm_name'] ?? ''));
            if ($firmName === '') {
                continue;
            }
            $caName = trim((string) ($firmData['ca_name'] ?? ''));
            $city = trim((string) ($firmData['city'] ?? ''));
            $page = (int) ($firmData['page_number'] ?? 0);
            $column = (int) ($firmData['column_number'] ?? ($firmData['column'] ?? 0));
            $bbox = $firmData['bounding_boxes'] ?? ($firmData['bounding_box'] ?? null);
            $sourceFp = hash('sha256', implode('|', [
                $document->id,
                $page,
                $column,
                is_array($bbox) ? json_encode($bbox) : '',
                mb_strtolower($firmName.'|'.$caName.'|'.$city),
            ]));
            if (isset($seenSource[$sourceFp])) {
                continue;
            }
            $seenSource[$sourceFp] = true;
            $firmData['parse_run_id'] = $parseRunId;
            $firmData['source_fingerprint'] = $sourceFp;
            $firmData['business_fingerprint'] = hash('sha256', mb_strtolower($firmName).'|'.mb_strtolower($caName).'|'.mb_strtolower($city));
            $firmData['column_number'] = $column > 0 ? $column : null;
            $toPersist[] = $firmData;
        }

        // Replace-on-retry keeps staging idempotent (no duplicate firm rows).
        DB::transaction(function () use ($document, $toPersist, $parseRunId, $candidateCount) {
            OcrParsedMember::query()
                ->whereIn('ocr_parsed_firm_id', OcrParsedFirm::query()
                    ->where('ocr_document_id', $document->id)
                    ->select('id'))
                ->delete();

            OcrParsedFirm::query()->where('ocr_document_id', $document->id)->delete();

            $validComplete = 0;
            foreach (array_chunk($toPersist, self::FIRM_INSERT_CHUNK) as $chunk) {
                foreach ($chunk as $firmData) {
                    $isPartnership = $this->isPartnershipFirmData($firmData);
                    $members = ($this->isThreeFieldMode() && ! $isPartnership)
                        ? []
                        : (is_array($firmData['members'] ?? null) ? $firmData['members'] : []);
                    if ($isPartnership && $members === [] && is_array($firmData['partners'] ?? null)) {
                        foreach ($firmData['partners'] as $i => $partnerName) {
                            $name = $this->rawString($partnerName);
                            if ($name === null) {
                                continue;
                            }
                            $members[] = [
                                'sequence_no' => $i + 2,
                                'ca_name' => $name,
                                'raw_ca_name' => $name,
                                'role' => 'Partner',
                                'is_primary' => false,
                            ];
                        }
                        if ($this->rawString($firmData['ca_name'] ?? null) !== null) {
                            array_unshift($members, [
                                'sequence_no' => 1,
                                'ca_name' => $this->rawString($firmData['ca_name']),
                                'raw_ca_name' => $this->rawString($firmData['raw_ca_name'] ?? null) ?? $this->rawString($firmData['ca_name']),
                                'role' => 'Partner',
                                'is_primary' => true,
                            ]);
                        }
                    }
                    unset($firmData['members'], $firmData['partners']);

                    $parsedFirmName = $this->rawString($firmData['firm_name'] ?? null);
                    $rawFirmName = $this->rawString($firmData['raw_firm_name'] ?? null) ?? $parsedFirmName;
                    $rawFrn = $this->isThreeFieldMode() ? null : $this->rawString($firmData['frn'] ?? null);
                    $rawGst = $this->isThreeFieldMode() ? null : $this->rawString($firmData['gst_no'] ?? null);
                    $rawPan = $this->isThreeFieldMode() ? null : $this->rawString($firmData['pan_no'] ?? null);
                    $rawPhone = $this->isThreeFieldMode() ? null : $this->rawString($firmData['phone'] ?? null);
                    $rawEmail = $this->isThreeFieldMode() ? null : $this->rawString($firmData['email'] ?? null);
                    $rawAddress = $this->isThreeFieldMode() ? null : $this->rawString($firmData['address'] ?? null);
                    $parsedCity = $this->rawString($firmData['city'] ?? null);
                    $rawCity = $this->rawString($firmData['raw_city'] ?? null) ?? $parsedCity;
                    $cityResolver = new OcrCityResolverService;
                    $parsedCity = $cityResolver->sanitizeCity($parsedCity);
                    if ($parsedCity === null && $cityResolver->isForbiddenLocalityShape((string) $rawCity)) {
                        $rawCity = null;
                    }
                    $rawState = $this->isThreeFieldMode() ? null : $this->rawString($firmData['state'] ?? null);
                    $rawPincode = $this->isThreeFieldMode() ? null : $this->rawString($firmData['pincode'] ?? null);
                    $parsedCaName = $this->rawString($firmData['ca_name'] ?? ($members[0]['ca_name'] ?? null));
                    $rawCaName = $this->rawString($firmData['raw_ca_name'] ?? ($members[0]['raw_ca_name'] ?? null)) ?? $parsedCaName;
                    $rawMembership = $this->isThreeFieldMode() ? null : $this->rawString($firmData['membership_no'] ?? ($members[0]['membership_no'] ?? null));

                    $rawPayload = $this->isThreeFieldMode()
                        ? array_filter([
                            'firm_name' => $rawFirmName,
                            'ca_name' => $rawCaName,
                            'city' => $rawCity,
                            'partners' => $isPartnership ? array_values(array_map(
                                fn ($m) => $this->rawString($m['ca_name'] ?? null),
                                array_values(array_filter($members, fn ($m) => empty($m['is_primary']))),
                            )) : null,
                        ], static fn ($v) => $v !== null)
                        : [
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
                            'ca_name' => $rawCaName,
                            'membership_no' => $rawMembership,
                        ];
                    $parsedPayload = $this->isThreeFieldMode()
                        ? array_filter([
                            'firm_name' => $parsedFirmName,
                            'ca_name' => $parsedCaName,
                            'city' => $parsedCity,
                            'partners' => $isPartnership ? array_values(array_filter(array_map(
                                fn ($m) => $this->rawString($m['ca_name'] ?? null),
                                array_values(array_filter($members, fn ($m) => empty($m['is_primary']))),
                            ))) : null,
                            'directory_profile' => $isPartnership ? OcrDirectoryProfileDetector::PARTNERSHIP : null,
                        ], static fn ($v) => $v !== null)
                        : [
                            'firm_name' => $rawFirmName,
                            'frn' => $rawFrn,
                            'gst_no' => $rawGst,
                            'pan_no' => $rawPan,
                            'phone' => $rawPhone,
                            'pincode' => $rawPincode,
                            'membership_no' => $rawMembership,
                            'ca_name' => $rawCaName,
                            'city' => $rawCity,
                        ];
                    $validation = $this->sourceVerifier->verify(array_merge($firmData, [
                        'firm_name' => $parsedFirmName ?? $rawFirmName,
                        'ca_name' => $parsedCaName ?? $rawCaName,
                        'city' => $parsedCity ?? $rawCity,
                        'membership_no' => $rawMembership,
                        'raw' => $rawPayload,
                        'parsed' => $parsedPayload,
                    ]));

                    $overallConfidence = $firmData['overall_confidence'] ?? $validation['overall_confidence'];
                    $scopedCodes = [];
                    if ($this->isThreeFieldMode()) {
                        $blocking = array_flip(config('ocr_workflow.blocking_codes', []));
                        $ignored = array_flip(config('ocr_workflow.ignored_decision_codes', []));
                        foreach ($validation['collision_codes'] ?? [] as $code) {
                            if (isset($ignored[$code])) {
                                continue;
                            }
                            if (isset($blocking[$code]) || str_starts_with((string) $code, 'MISSING_')) {
                                $scopedCodes[] = $code;
                            }
                        }
                        $hardFail = $validation['errors'] !== [] || empty($validation['ok']);
                        // Do not quarantine solely because require_verification prevents auto-apply.
                        $quarantine = $hardFail || $scopedCodes !== [];
                        $validationErrors = array_values(array_unique(array_merge(
                            $validation['errors'],
                            $scopedCodes,
                        )));
                    } else {
                        $hardFail = $validation['errors'] !== [] || empty($validation['ok']);
                        $quarantine = $hardFail || empty($validation['verified']);
                        $validationErrors = array_values(array_unique(array_merge(
                            $validation['errors'],
                            $validation['collision_codes'] ?? [],
                        )));
                    }

                    $displayFirmName = $this->isThreeFieldMode() ? ($parsedFirmName ?? $rawFirmName) : $rawFirmName;
                    $displayCity = $this->isThreeFieldMode() ? ($parsedCity ?? $rawCity) : $rawCity;
                    $displayCaName = $this->isThreeFieldMode() ? ($parsedCaName ?? $rawCaName) : $rawCaName;

                    // Populated city must never retain stale "City is required" / MISSING_CITY.
                    if (trim((string) $displayCity) !== '') {
                        $validation['collision_codes'] = array_values(array_filter(
                            $validation['collision_codes'] ?? [],
                            static fn ($c) => ! in_array((string) $c, ['MISSING_CITY', 'MISSING_REQUIRED_FIELD'], true),
                        ));
                        $validation['errors'] = array_values(array_filter(
                            $validation['errors'] ?? [],
                            static fn ($m) => ! preg_match('/city is required/i', (string) $m),
                        ));
                        $scopedCodes = array_values(array_filter(
                            $scopedCodes,
                            static fn ($c) => (string) $c !== 'MISSING_CITY',
                        ));
                        $validationErrors = array_values(array_filter(
                            $validationErrors,
                            static fn ($m) => ! preg_match('/city is required/i', (string) $m) && (string) $m !== 'MISSING_CITY',
                        ));
                        $missingFields = is_array($firmData['missing_required_fields'] ?? null)
                            ? $firmData['missing_required_fields']
                            : [];
                        $firmData['missing_required_fields'] = array_values(array_filter(
                            $missingFields,
                            static fn ($f) => $f !== 'city',
                        ));
                    }

                    $completeThree = $this->isThreeFieldMode()
                        && trim((string) $displayFirmName) !== ''
                        && trim((string) $displayCaName) !== ''
                        && trim((string) $displayCity) !== '';
                    // Any firm row with a firm name is verified for review UI (CA/city may still be corrected).
                    $hasFirm = $this->isThreeFieldMode() && trim((string) $displayFirmName) !== '';
                    if ($this->isThreeFieldMode() && $hasFirm && ! $hardFail) {
                        // Drop missing-field quarantine so complete-enough rows are Verified.
                        $missingOnly = $scopedCodes !== [] && array_values(array_filter(
                            $scopedCodes,
                            static fn ($c) => str_starts_with((string) $c, 'MISSING_'),
                        )) === $scopedCodes;
                        if ($missingOnly || $scopedCodes === []) {
                            $quarantine = false;
                            $scopedCodes = [];
                            $validationErrors = $validation['errors'] ?? [];
                        }
                    }
                    $matchStatus = $quarantine
                        ? 'needs_review'
                        : ($hasFirm ? 'verified' : ($firmData['match_status'] ?? 'pending'));
                    $matchReason = $quarantine
                        ? ($validation['errors'][0] ?? ($validation['collision_codes'][0] ?? 'awaiting_verification'))
                        : ($completeThree ? 'complete_firm_ca_city' : ($hasFirm ? 'verified_firm_present' : ($firmData['match_reason'] ?? null)));

                    $firmPayload = [
                        'ocr_document_id' => $document->id,
                        'sequence_no' => (int) ($firmData['sequence_no'] ?? 1),
                        'raw_firm_name' => $rawFirmName,
                        'firm_name' => $displayFirmName,
                        'normalized_firm_name' => $this->normalizer->firmName($displayFirmName),
                        'firm_type' => $this->isThreeFieldMode() ? null : ($firmData['firm_type'] ?? null),
                        'frn' => $rawFrn,
                        'gst_no' => $rawGst,
                        'pan_no' => $rawPan,
                        'address' => $rawAddress,
                        'city' => $displayCity,
                        'district' => $this->isThreeFieldMode() ? null : $this->rawString($firmData['district'] ?? null),
                        'state' => $rawState,
                        'pincode' => $rawPincode,
                        'phone' => $rawPhone,
                        'email' => $rawEmail,
                        'website' => $this->isThreeFieldMode() ? null : $this->rawString($firmData['website'] ?? null),
                        'partner_count' => ($this->isThreeFieldMode() && ! $isPartnership)
                            ? null
                            : (count($members) > 0
                                ? max(0, count($members) - ($this->rawString($firmData['ca_name'] ?? ($members[0]['ca_name'] ?? null)) !== null ? 1 : 0))
                                : ($firmData['partner_count'] ?? null)),
                        'review_status' => $firmData['review_status'] ?? OcrParsedFirm::REVIEW_PENDING,
                        'match_status' => $matchStatus,
                        'match_reason' => $matchReason,
                        'overall_confidence' => $overallConfidence,
                        'page_number' => $firmData['page_number'] ?? null,
                        'row_number' => $firmData['row_number'] ?? ($firmData['row_serial'] ?? $firmData['sequence_no'] ?? null),
                        'bounding_box' => $this->isThreeFieldMode() ? null : ($firmData['bounding_boxes'] ?? ($firmData['bounding_box'] ?? null)),
                        'validation_errors' => $validationErrors !== [] ? $validationErrors : null,
                        'source_data' => [
                            'raw' => $rawPayload,
                            'parsed' => $parsedPayload,
                            'normalized' => $this->isThreeFieldMode()
                                ? [
                                    'firm_name' => $this->normalizer->firmName($displayFirmName),
                                    'ca_name' => $this->normalizer->caName($displayCaName),
                                    'city' => $this->normalizer->city($displayCity),
                                ]
                                : [
                                    'firm_name' => $this->normalizer->firmName($rawFirmName),
                                    'frn' => $this->normalizer->frn($rawFrn),
                                    'gst_no' => $this->normalizer->gst($rawGst),
                                    'pan_no' => $this->normalizer->pan($rawPan),
                                    'phone' => $this->normalizer->phone($rawPhone),
                                    'email' => $this->normalizer->email($rawEmail),
                                    'pincode' => $this->normalizer->postalCode($rawPincode),
                                    'ca_name' => $this->normalizer->caName($rawCaName),
                                    'membership_no' => $this->normalizer->membershipNumber($rawMembership),
                                    'city' => $this->normalizer->city($rawCity),
                                ],
                            'validation' => [
                                'ok' => $validation['ok'],
                                'verified' => $validation['verified'],
                                'auto_apply_ok' => $validation['auto_apply_ok'],
                                'errors' => $validation['errors'],
                                'warnings' => $validation['warnings'],
                                'collision_codes' => $validation['collision_codes'],
                                'collision_messages' => $validation['collision_messages'],
                                'fields' => $validation['fields'],
                                'require_verification' => $validation['require_verification'],
                            ],
                            'unclassified_lines' => $this->isThreeFieldMode() ? [] : ($firmData['unclassified_lines'] ?? []),
                            'field_meta' => $this->isThreeFieldMode()
                                ? array_intersect_key(
                                    is_array($firmData['field_meta'] ?? null) ? $firmData['field_meta'] : [],
                                    array_flip(['firm_name', 'ca_name', 'city']),
                                )
                                : ($firmData['field_meta'] ?? null),
                            'source_lines' => $this->isThreeFieldMode() ? null : ($firmData['source_lines'] ?? null),
                            'row_number' => $firmData['row_number'] ?? ($firmData['row_serial'] ?? $firmData['sequence_no'] ?? null),
                            'bounding_boxes' => $this->isThreeFieldMode() ? null : ($firmData['bounding_boxes'] ?? null),
                            'extraction_source' => $firmData['extraction_source'] ?? ($firmData['parse_mode'] ?? 'text_parser'),
                            'entity_classifications' => $this->isThreeFieldMode() ? [] : ($firmData['entity_classifications'] ?? []),
                            'unknown_tokens' => $this->isThreeFieldMode() ? [] : ($firmData['unknown_tokens'] ?? []),
                            'ignored_tokens' => $this->isThreeFieldMode() ? [] : ($firmData['ignored_tokens'] ?? []),
                            'field_confidences' => $this->isThreeFieldMode() ? null : ($firmData['field_confidences'] ?? null),
                            'ca_name' => $displayCaName,
                            'ca_role' => ($this->isThreeFieldMode() && ! $isPartnership) ? null : ($firmData['ca_role'] ?? ($isPartnership ? 'Partner' : null)),
                            'directory_profile' => $isPartnership ? OcrDirectoryProfileDetector::PARTNERSHIP : ($firmData['directory_profile'] ?? null),
                            'partners' => $isPartnership ? array_values(array_filter(array_map(
                                fn ($m) => $this->rawString($m['ca_name'] ?? null),
                                array_values(array_filter($members, fn ($m) => empty($m['is_primary']))),
                            ))) : [],
                            'structural_confidence' => $firmData['structural_confidence'] ?? null,
                            'parser_confidence' => $firmData['parser_confidence'] ?? null,
                            'reconstructed_text' => $this->isThreeFieldMode() ? null : ($firmData['reconstructed_text'] ?? null),
                            'column_number' => $firmData['column_number'] ?? null,
                        ],
                        'field_meta' => $this->isThreeFieldMode()
                            ? array_intersect_key(
                                is_array($firmData['field_meta'] ?? null) ? $firmData['field_meta'] : [],
                                array_flip(['firm_name', 'ca_name', 'city']),
                            )
                            : ($firmData['field_meta'] ?? null),
                    ];
                    foreach ([
                        'parse_run_id' => $firmData['parse_run_id'] ?? null,
                        'source_fingerprint' => $firmData['source_fingerprint'] ?? null,
                        'business_fingerprint' => $firmData['business_fingerprint'] ?? null,
                        'column_number' => $firmData['column_number'] ?? null,
                    ] as $col => $val) {
                        if (Schema::hasColumn('ocr_parsed_firms', $col)) {
                            $firmPayload[$col] = $val;
                        }
                    }
                    $firm = OcrParsedFirm::query()->create($firmPayload);
                    if ($displayFirmName && $displayCaName && $displayCity) {
                        $validComplete++;
                    }

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

            $updates = [
                'parsed_firm_count' => count($toPersist),
                'active_parse_run_id' => $parseRunId,
                'valid_firm_count' => $validComplete,
                'candidate_firm_count' => $candidateCount,
            ];
            $payload = [];
            foreach ($updates as $key => $value) {
                if (Schema::hasColumn('ocr_documents', $key)) {
                    $payload[$key] = $value;
                }
            }
            if ($payload === []) {
                $payload = ['parsed_firm_count' => count($toPersist)];
            }
            $document->update($payload);
        });
    }


    private function isThreeFieldMode(): bool
    {
        return config('ocr_workflow.mode', 'firm_ca_city') === 'firm_ca_city';
    }

    /** @param  array<string, mixed>  $firmData */
    private function isPartnershipFirmData(array $firmData): bool
    {
        $profile = (string) ($firmData['directory_profile'] ?? '');
        $source = (string) ($firmData['extraction_source'] ?? '');

        return $profile === OcrDirectoryProfileDetector::PARTNERSHIP
            || $source === 'partnership_directory'
            || (is_array($firmData['partners'] ?? null) && $firmData['partners'] !== [])
            || ((string) ($firmData['firm_type'] ?? '') === 'Partnership'
                && is_array($firmData['members'] ?? null)
                && count($firmData['members']) > 1);
    }

    /**
     * @param  array<string, mixed>  $firmData
     * @return array<string, mixed>
     */
    private function sanitizeFirmData(array $firmData): array
    {
        if (! $this->isThreeFieldMode()) {
            return $firmData;
        }

        $isPartnership = $this->isPartnershipFirmData($firmData);
        $parsedCa = $this->rawString($firmData['ca_name'] ?? null);
        $rawCa = $this->rawString($firmData['raw_ca_name'] ?? null) ?? $parsedCa;
        if ($parsedCa === null && is_array($firmData['members'] ?? null) && ($firmData['members'][0]['ca_name'] ?? null)) {
            $parsedCa = $this->rawString($firmData['members'][0]['ca_name']);
            $rawCa = $this->rawString($firmData['members'][0]['raw_ca_name'] ?? null) ?? $parsedCa;
        }
        $parsedFirm = $this->rawString($firmData['firm_name'] ?? null);
        $rawFirm = $this->rawString($firmData['raw_firm_name'] ?? null) ?? $parsedFirm;
        $parsedCity = $this->rawString($firmData['city'] ?? null);
        $rawCity = $this->rawString($firmData['raw_city'] ?? null) ?? $parsedCity;
        $cityResolver = new OcrCityResolverService;
        $parsedCity = $cityResolver->sanitizeCity($parsedCity);
        // Keep raw for audit, but never display a street/district as city.
        if ($parsedCity === null && $cityResolver->isForbiddenLocalityShape((string) $rawCity)) {
            $rawCity = null;
        } elseif ($parsedCity !== null) {
            $rawCity = $rawCity ?? $parsedCity;
        }
        $fieldMeta = is_array($firmData['field_meta'] ?? null) ? $firmData['field_meta'] : [];
        $fieldMeta = array_intersect_key($fieldMeta, array_flip(['firm_name', 'ca_name', 'city']));
        $members = $isPartnership && is_array($firmData['members'] ?? null) ? $firmData['members'] : [];
        $partners = $isPartnership && is_array($firmData['partners'] ?? null) ? $firmData['partners'] : [];
        $partnerCount = $isPartnership
            ? (count($partners) > 0 ? count($partners) : max(0, count($members) - ($parsedCa !== null ? 1 : 0)))
            : null;

        return array_merge($firmData, [
            'firm_name' => $parsedFirm,
            'raw_firm_name' => $rawFirm,
            'ca_name' => $parsedCa,
            'raw_ca_name' => $rawCa,
            'city' => $parsedCity,
            'raw_city' => $rawCity,
            'firm_type' => $isPartnership ? 'Partnership' : null,
            'frn' => null,
            'gst_no' => null,
            'pan_no' => null,
            'address' => null,
            'state' => null,
            'pincode' => null,
            'phone' => null,
            'email' => null,
            'website' => null,
            'membership_no' => null,
            'ca_role' => $isPartnership ? ($firmData['ca_role'] ?? 'Partner') : null,
            'members' => $members,
            'partners' => $partners,
            'directory_profile' => $isPartnership
                ? OcrDirectoryProfileDetector::PARTNERSHIP
                : ($firmData['directory_profile'] ?? null),
            'field_meta' => $fieldMeta,
            'partner_count' => $partnerCount,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $firms
     * @return array<string, int|list|bool>
     */
    private function buildReconciliationReport(array $firms, array $quality, int $persistedCount = 0, int $validFirmCount = 0): array
    {
        $sourceRows = (int) ($quality['total_source_rows'] ?? count($firms));
        $candidateBlocks = count($firms);
        $needsReview = 0;
        $exactReady = 0;
        $missingCity = 0;
        $missingCa = 0;
        $missingFirm = 0;
        $rejectedNoise = 0;
        foreach ($firms as $firm) {
            $missing = is_array($firm['missing_required_fields'] ?? null) ? $firm['missing_required_fields'] : [];
            $hasFirm = trim((string) ($firm['firm_name'] ?? '')) !== '';
            if (! $hasFirm) {
                $missingFirm++;
                $rejectedNoise++;
            }
            if (in_array('city', $missing, true) || empty($firm['city'])) {
                $missingCity++;
            }
            if (in_array('ca_name', $missing, true) || empty($firm['ca_name'])) {
                $missingCa++;
            }
            if ($missing !== [] || ! empty($firm['ambiguous_layout']) || ! empty($firm['low_confidence_fields']) || ! $hasFirm) {
                $needsReview++;
            } else {
                $exactReady++;
            }
        }

        $invalidCandidates = max(0, $candidateBlocks - $persistedCount - $rejectedNoise);
        $accounted = $persistedCount + $invalidCandidates + $rejectedNoise;

        return [
            'source_rows' => $sourceRows,
            'candidate_blocks' => $candidateBlocks,
            'parsed_rows' => $persistedCount,
            'valid_unique_firms' => $validFirmCount > 0 ? $validFirmCount : $persistedCount,
            'duplicate_candidates' => max(0, $candidateBlocks - $persistedCount - $rejectedNoise),
            'rejected_noise' => $rejectedNoise,
            'exact_match_candidates' => $exactReady,
            'needs_review' => $needsReview,
            'conflicts' => 0,
            'rejected' => $rejectedNoise,
            'failed' => max(0, $sourceRows - $candidateBlocks),
            'missing_firm_name' => $missingFirm,
            'missing_ca_name' => $missingCa,
            'missing_city' => $missingCity,
            'accounted_for' => $accounted,
            'every_source_row_accounted' => $accounted === $candidateBlocks,
            'source_record_candidates' => $candidateBlocks,
            'valid_complete_records' => $validFirmCount,
            'invalid_candidates' => $invalidCandidates,
            'duplicate_source_records' => max(0, $candidateBlocks - $persistedCount - $rejectedNoise),
            'final_unique_records' => $validFirmCount > 0 ? $validFirmCount : $persistedCount,
        ];
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
