<?php

namespace App\Services\Ocr;

use App\Contracts\Ocr\OcrProcessorInterface;
use App\Exceptions\DocumentAi\DocumentAiConfigurationException;
use App\Exceptions\Ocr\OcrFileException;
use App\Exceptions\Ocr\OcrProviderException;
use App\Jobs\CheckBatchOcrStatusJob;
use App\Jobs\FinalizeBatchOcrResultJob;
use App\Jobs\ImportMasterCaOcrJob;
use App\Jobs\ParseOcrStructureJob;
use App\Jobs\ProcessOcrDocumentJob;
use App\Jobs\StartBatchOcrJob;
use App\Models\CaMaster;
use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Models\OcrParsedMember;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use App\Services\Rbac\EmployeeDataScopeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class OcrDocumentService
{
    public function __construct(
        private readonly OcrProcessorInterface $documentAiService,
        private readonly ActivityLogService $activityLogService,
        private readonly EmployeeDataScopeService $employeeDataScope,
        private readonly OcrProcessingModeSelector $modeSelector,
        private readonly GoogleDocumentAiBatchService $batchService,
        private readonly OcrStructurePersistService $structurePersistService,
    ) {}

    public function listForCa(int $caId, int $perPage = 10): LengthAwarePaginator
    {
        $this->employeeDataScope->ensureCanAccessCaMaster($caId);

        return OcrDocument::query()
            ->select($this->listColumns())
            ->with(['uploader:id,name,email'])
            ->where('ca_id', $caId)
            ->latest('created_at')
            ->paginate($perPage);
    }

    /**
     * @param  array{search?: string, status?: string, ca_id?: int|null, mime_type?: string, uploaded_by?: int, date_from?: string, date_to?: string, page?: int, per_page?: int}  $params
     */
    public function searchHistory(User $user, array $params = []): LengthAwarePaginator
    {
        $perPage = min(50, max(1, (int) ($params['per_page'] ?? 15)));
        $search = trim((string) ($params['search'] ?? ''));
        $status = strtolower(trim((string) ($params['status'] ?? '')));
        $mimeType = trim((string) ($params['mime_type'] ?? ''));
        $uploadedBy = isset($params['uploaded_by']) && $params['uploaded_by'] !== '' && $params['uploaded_by'] !== null
            ? (int) $params['uploaded_by']
            : null;
        $dateFrom = trim((string) ($params['date_from'] ?? ''));
        $dateTo = trim((string) ($params['date_to'] ?? ''));
        $caId = isset($params['ca_id']) && $params['ca_id'] !== '' && $params['ca_id'] !== null
            ? (int) $params['ca_id']
            : null;

        $query = OcrDocument::query()
            ->select($this->listColumns())
            ->with(['uploader:id,name,email', 'caMaster:ca_id,firm_name,ca_name']);

        $this->applyVisibilityScope($query, $user);

        if ($caId !== null && $caId > 0) {
            $this->employeeDataScope->ensureCanAccessCaMaster($caId);
            $query->where('ca_id', $caId);
        }

        if ($status !== '' && in_array($status, OcrDocument::STATUSES, true)) {
            $query->where('status', $status);
        }

        if ($mimeType !== '') {
            $query->where('mime_type', $mimeType);
        }

        if ($uploadedBy !== null && $uploadedBy > 0) {
            $query->where('uploaded_by', $uploadedBy);
        }

        if ($dateFrom !== '') {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo !== '') {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($q) use ($like) {
                $this->whereLike($q, 'original_filename', $like);
                $this->whereLike($q, 'error_message', $like, 'or');
                $q->orWhereHas('caMaster', function ($ca) use ($like) {
                    $this->whereLike($ca, 'firm_name', $like);
                    $this->whereLike($ca, 'ca_name', $like, 'or');
                });
            });
        }

        return $query->latest('created_at')->paginate($perPage);
    }

    public function store(UploadedFile $file, ?int $caId, User $user, bool $forceReimport = false, string $importType = OcrDocument::IMPORT_SALES_TEAM): OcrDocument
    {
        if (! in_array($importType, OcrDocument::IMPORT_TYPES, true)) {
            throw new OcrFileException('Select Master CA Data or Sales Team Data before uploading.', 'invalid_import_type');
        }

        $route = app(OcrImportRouterService::class)->classify($file);
        if ($route['bypass_ocr'] && $route['route'] === OcrImportRouterService::ROUTE_STRUCTURED_BULK) {
            throw new OcrFileException(
                $route['reason'].' Use Bulk Import (Excel/CSV) instead.',
                'spreadsheet_bypass_ocr',
            );
        }
        if ($route['route'] === OcrImportRouterService::ROUTE_REJECTED) {
            throw new OcrFileException($route['reason'], 'unsupported_file_type');
        }

        if ($caId !== null && $caId > 0) {
            $this->employeeDataScope->ensureCanAccessCaMaster($caId);
            CaMaster::query()->findOrFail($caId);
        } else {
            $caId = null;
        }

        $disk = (string) config('document-ai.storage_disk', 'local');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $storedFilename = (string) Str::uuid().'.'.$extension;
        $storagePath = sprintf(
            'ocr-documents/%s/%s/%s',
            now()->format('Y'),
            now()->format('m'),
            $storedFilename,
        );

        $stored = false;
        try {
            $contents = $file->getContent();
            if ($contents === '') {
                throw new OcrFileException('The uploaded document is empty.', 'empty_file');
            }

            $checksum = hash('sha256', $contents);
            if (! $forceReimport) {
                $duplicate = OcrDocument::query()
                    ->where('checksum', $checksum)
                    ->whereIn('status', [
                        OcrDocument::STATUS_COMPLETED,
                        OcrDocument::STATUS_QUEUED,
                        OcrDocument::STATUS_PROCESSING,
                    ])
                    ->latest('id')
                    ->first(['id', 'original_filename', 'created_at', 'status']);
                if ($duplicate) {
                    throw new OcrFileException(
                        'This file has already been imported (document #'.$duplicate->id
                        .' · '.$duplicate->original_filename
                        .'). Confirm re-import if you want to process it again.',
                        'duplicate_file',
                    );
                }
            }

            $mimeType = $file->getMimeType() ?: 'application/octet-stream';
            $decision = $this->modeSelector->decide($mimeType, (int) $file->getSize(), $contents);

            if ($decision['mode'] === OcrProcessingModeSelector::MODE_BATCH) {
                $this->modeSelector->assertBatchConfigured();
            }

            $stored = Storage::disk($disk)->put($storagePath, $contents);
            if (! $stored) {
                throw new \RuntimeException('Unable to store the uploaded document.');
            }

            $createAttrs = [
                'ca_id' => $caId,
                'uploaded_by' => $user->id,
                'original_filename' => $file->getClientOriginalName(),
                'stored_filename' => $storedFilename,
                'storage_disk' => $disk,
                'storage_path' => $storagePath,
                'mime_type' => $mimeType,
                'file_size' => (int) $file->getSize(),
                'checksum' => $checksum,
                'status' => OcrDocument::STATUS_QUEUED,
                'provider' => 'google_document_ai',
                'import_type' => $importType,
                'processing_mode' => $decision['mode'],
                'page_count' => $decision['page_count'],
                'total_pages' => $decision['page_count'],
                'processing_progress' => $decision['mode'] === OcrProcessingModeSelector::MODE_BATCH
                    ? 'Queued for batch OCR'
                    : 'Queued for online OCR',
            ];
            if (! Schema::hasColumn('ocr_documents', 'import_type')) {
                unset($createAttrs['import_type']);
            }
            $document = null;
            $requestId = (string) Str::uuid();
            $startedMs = (int) round(microtime(true) * 1000);

            DB::transaction(function () use (
                &$document,
                $createAttrs,
                $user,
                $checksum,
                $disk,
                $importType,
                $decision,
                $file,
                $requestId,
            ) {
                $document = OcrDocument::query()->create($createAttrs);
                $this->logPipelineStep('document_created', $document, [
                    'request_id' => $requestId,
                    'uploaded_by' => $user->id,
                    'checksum' => $checksum,
                    'storage_disk' => $disk,
                    'import_type' => $importType,
                    'processing_mode' => $decision['mode'],
                    'page_count' => $decision['page_count'],
                    'file_size' => (int) $file->getSize(),
                    'database' => (string) config('database.default'),
                    'transaction_committed' => false,
                ]);
            });

            // Refresh after commit so the API returns the real persisted row.
            $document = $document?->fresh(['uploader:id,name,email', 'caMaster:ca_id,firm_name,ca_name']) ?? $document;
            Log::info('ocr.pipeline.step', [
                'step' => 'document_persisted',
                'request_id' => $requestId,
                'ocr_document_id' => $document->id,
                'filename' => $document->original_filename,
                'file_hash' => $document->checksum,
                'import_type' => $document->import_type,
                'user_id' => $user->id,
                'database' => (string) config('database.default'),
                'transaction_committed' => true,
                'status' => $document->status,
                'created_at' => optional($document->created_at)?->toIso8601String(),
                'duration_ms' => ((int) round(microtime(true) * 1000)) - $startedMs,
            ]);

            $this->logActivity('OCR Document Uploaded', $document, 'OCR document uploaded.');

            if ($this->shouldProcessSmallFileSynchronously($document)) {
                $this->logActivity('OCR Processing Started', $document, 'Small-file online OCR fast path.');
                $timeout = (int) config('document-ai.sync_timeout_seconds', 60);
                if (function_exists('set_time_limit')) {
                    @set_time_limit($timeout + 15);
                }

                try {
                    $this->logPipelineStep('ocr_sync_start', $document, ['request_id' => $requestId]);
                    $this->processOnline($document->fresh() ?? $document);
                } catch (Throwable $exception) {
                    $fresh = $document->fresh() ?? $document;
                    if ($fresh && in_array($fresh->status, OcrDocument::ACTIVE_STATUSES, true)) {
                        $this->markFailed(
                            $fresh,
                            $this->exceptionErrorCode($exception, 'sync_ocr_failed'),
                            $this->exceptionMessage($exception, 'Synchronous OCR failed.'),
                            true,
                        );
                    }
                    Log::error('ocr.pipeline.sync_fast_path_failed', [
                        'request_id' => $requestId,
                        'ocr_document_id' => $document->id,
                        'error_code' => $fresh->error_code ?? 'sync_ocr_failed',
                        'error_message' => $exception->getMessage(),
                        'exception' => $exception::class,
                    ]);
                }

                return $document->fresh(['uploader:id,name,email', 'caMaster:ca_id,firm_name,ca_name']);
            }

            $this->logActivity('OCR Processing Queued', $document, 'OCR processing queued ('.$decision['mode'].').');
            $this->dispatchProcessingJob($document->id, $requestId);
            $this->logPipelineStep('ocr_job_dispatched', $document, [
                'request_id' => $requestId,
                'job_class' => ProcessOcrDocumentJob::class,
                'queue' => (string) config('document-ai.queue', 'default'),
            ]);

            return $document->fresh(['uploader:id,name,email', 'caMaster:ca_id,firm_name,ca_name']);
        } catch (Throwable $exception) {
            if ($stored) {
                Storage::disk($disk)->delete($storagePath);
            }
            Log::error('ocr.pipeline.upload_failed', [
                'error_message' => $exception->getMessage(),
                'exception' => $exception::class,
                'storage_path' => $storagePath,
            ]);

            throw $exception;
        }
    }

    public function updateCorrectedText(OcrDocument $document, string $correctedText, User $user): OcrDocument
    {
        $this->ensureCanAccessDocument($document);

        $document->update([
            'corrected_text' => $correctedText,
            'corrected_by' => $user->id,
            'corrected_at' => now(),
        ]);

        $this->logActivity('OCR Text Corrected', $document, 'OCR corrected text saved.');

        $fresh = $document->fresh(['uploader:id,name,email']);
        $this->queueOrRunStructureParse($fresh);

        return $fresh->fresh(['uploader:id,name,email', 'parsedFirms.members']);
    }

    public function retry(OcrDocument $document, User $user): OcrDocument
    {
        $this->ensureCanAccessDocument($document);

        if (! $document->isFailed()) {
            abort(422, 'Only failed OCR documents can be retried.');
        }

        if ($document->isActiveProcessing()) {
            abort(422, 'This OCR document is already processing.');
        }

        $binary = '';
        if (Storage::disk($document->storage_disk)->exists($document->storage_path)) {
            $binary = (string) Storage::disk($document->storage_disk)->get($document->storage_path);
        }

        $decision = $this->modeSelector->decide(
            (string) $document->mime_type,
            (int) $document->file_size,
            $binary,
        );

        if ($decision['mode'] === OcrProcessingModeSelector::MODE_BATCH) {
            $this->modeSelector->assertBatchConfigured();
        }

        $document->update([
            'status' => OcrDocument::STATUS_QUEUED,
            'processing_mode' => $decision['mode'],
            'page_count' => $decision['page_count'] ?? $document->page_count,
            'total_pages' => $decision['page_count'] ?? $document->total_pages,
            'error_code' => null,
            'error_message' => null,
            'processing_started_at' => null,
            'processed_at' => null,
            'failed_at' => null,
            'provider_operation_name' => null,
            'gcs_input_uri' => null,
            'gcs_output_uri' => null,
            'processing_progress' => $decision['mode'] === OcrProcessingModeSelector::MODE_BATCH
                ? 'Queued for batch OCR'
                : 'Queued for online OCR',
            'processed_pages' => null,
            'batch_started_at' => null,
            'batch_completed_at' => null,
            'result_checksum' => null,
        ]);

        $this->logActivity('OCR Retry Requested', $document, 'OCR retry requested ('.$decision['mode'].').');

        $this->dispatchProcessingJob($document->id);

        return $document->fresh(['uploader:id,name,email']);
    }

    public function destroy(OcrDocument $document, User $user): void
    {
        $this->ensureCanAccessDocument($document);

        if ($document->isActiveProcessing()) {
            abort(409, 'This OCR document is still processing and cannot be deleted yet.');
        }

        $disk = $document->storage_disk;
        $path = $document->storage_path;
        $gcsInput = $document->gcs_input_uri;
        $gcsOutput = $document->gcs_output_uri;

        DB::transaction(function () use ($document) {
            $this->logActivity('OCR Document Deleted', $document, 'OCR document deleted.');

            // Soft-delete does not fire FK cascade — remove structured children explicitly.
            $firmIds = OcrParsedFirm::query()
                ->where('ocr_document_id', $document->id)
                ->pluck('id');
            if ($firmIds->isNotEmpty()) {
                OcrParsedMember::query()->whereIn('ocr_parsed_firm_id', $firmIds)->delete();
                OcrParsedFirm::query()->whereIn('id', $firmIds)->delete();
            }

            $document->delete();
        });

        if ($path && Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }

        // Best-effort cloud cleanup outside the DB transaction.
        try {
            if ($gcsInput || $gcsOutput) {
                $this->batchService->cleanupAfterSuccess(new OcrDocument([
                    'gcs_input_uri' => $gcsInput,
                    'gcs_output_uri' => $gcsOutput,
                ]));
            }
        } catch (Throwable $exception) {
            Log::warning('ocr.document.gcs_cleanup_failed', [
                'error' => class_basename($exception),
            ]);
        }
    }

    public function downloadOriginal(OcrDocument $document): StreamedResponse
    {
        return $this->streamOriginal($document, asAttachment: false);
    }

    public function streamOriginal(OcrDocument $document, bool $asAttachment = false): StreamedResponse
    {
        $this->ensureCanAccessDocument($document);

        if (! Storage::disk($document->storage_disk)->exists($document->storage_path)) {
            abort(404, 'Original document not found.');
        }

        $this->logActivity(
            $asAttachment ? 'OCR Document Downloaded' : 'OCR Original Viewed',
            $document,
            $asAttachment ? 'OCR document downloaded.' : 'Original OCR document accessed.',
        );

        $disposition = $asAttachment ? 'attachment' : 'inline';

        return Storage::disk($document->storage_disk)->response(
            $document->storage_path,
            $document->original_filename,
            [
                'Content-Type' => $document->mime_type,
                'Content-Disposition' => $disposition.'; filename="'.addslashes($document->original_filename).'"',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function processQueuedDocument(int $ocrDocumentId): void
    {
        $document = OcrDocument::query()->find($ocrDocumentId);
        if (! $document) {
            Log::warning('ocr.pipeline.missing_document', ['ocr_document_id' => $ocrDocumentId, 'step' => 'process_queued']);

            return;
        }

        $this->logPipelineStep('process_queued_enter', $document);

        if (in_array($document->status, [OcrDocument::STATUS_COMPLETED, OcrDocument::STATUS_CANCELLED], true)) {
            $this->logPipelineStep('process_queued_skip_terminal', $document);

            return;
        }

        if ($document->status === OcrDocument::STATUS_FAILED) {
            $this->logPipelineStep('process_queued_skip_failed', $document);

            return;
        }

        // Resume / heal stuck active statuses instead of silently exiting (root cause of "stuck on Processing").
        if (in_array($document->status, [
            OcrDocument::STATUS_UPLOADING_TO_CLOUD,
            OcrDocument::STATUS_PROCESSING,
            OcrDocument::STATUS_FINALIZING,
        ], true)) {
            if ($document->processing_mode === OcrProcessingModeSelector::MODE_BATCH && filled($document->provider_operation_name)) {
                if ($document->status === OcrDocument::STATUS_FINALIZING) {
                    $this->logPipelineStep('batch_finalize_resume', $document);
                    $this->finalizeBatchProcessing($document->id);
                } else {
                    $this->logPipelineStep('batch_status_resume', $document);
                    $this->dispatchBatchStatusCheck($document->id);
                }

                return;
            }

            if (filled($document->extracted_text)) {
                $this->logPipelineStep('heal_processing_with_text', $document);
                $document->update([
                    'status' => OcrDocument::STATUS_COMPLETED,
                    'processing_progress' => 'Completed',
                    'processed_at' => $document->processed_at ?? now(),
                    'error_code' => null,
                    'error_message' => null,
                ]);
                $this->queueOrRunStructureParse($document->fresh());

                return;
            }

            $this->logPipelineStep('resume_stuck_online_ocr', $document, [
                'previous_status' => $document->status,
            ]);
            $document->update([
                'status' => OcrDocument::STATUS_QUEUED,
                'processing_progress' => 'Resuming OCR after interrupted processing',
            ]);
            $document->refresh();
        }

        if ($document->status !== OcrDocument::STATUS_QUEUED && $document->status !== OcrDocument::STATUS_PENDING) {
            $this->logPipelineStep('process_queued_skip_unexpected_status', $document);

            return;
        }

        $mode = $document->processing_mode;
        if (! in_array($mode, [OcrProcessingModeSelector::MODE_ONLINE, OcrProcessingModeSelector::MODE_BATCH], true)) {
            $binary = Storage::disk($document->storage_disk)->exists($document->storage_path)
                ? (string) Storage::disk($document->storage_disk)->get($document->storage_path)
                : '';
            $decision = $this->modeSelector->decide((string) $document->mime_type, (int) $document->file_size, $binary);
            $mode = $decision['mode'];
            $document->update([
                'processing_mode' => $mode,
                'page_count' => $decision['page_count'] ?? $document->page_count,
                'total_pages' => $decision['page_count'] ?? $document->total_pages,
            ]);
        }

        if ($mode === OcrProcessingModeSelector::MODE_BATCH) {
            $this->logPipelineStep('batch_job_dispatch', $document);
            StartBatchOcrJob::dispatch($document->id);

            return;
        }

        $this->processOnline($document);
    }

    public function startBatchProcessing(int $ocrDocumentId): void
    {
        $document = OcrDocument::query()->find($ocrDocumentId);
        if (! $document) {
            return;
        }

        if ($document->status === OcrDocument::STATUS_COMPLETED) {
            return;
        }

        // Idempotent: already submitted.
        if ($document->provider_operation_name && in_array($document->status, [
            OcrDocument::STATUS_PROCESSING,
            OcrDocument::STATUS_FINALIZING,
        ], true)) {
            $this->dispatchBatchStatusCheck($document->id);

            return;
        }

        $claimed = OcrDocument::query()
            ->whereKey($document->id)
            ->whereIn('status', [OcrDocument::STATUS_QUEUED, OcrDocument::STATUS_PENDING, OcrDocument::STATUS_FAILED])
            ->where(function ($query) {
                $query->whereNull('provider_operation_name')->orWhere('provider_operation_name', '');
            })
            ->update([
                'status' => OcrDocument::STATUS_UPLOADING_TO_CLOUD,
                'processing_mode' => OcrProcessingModeSelector::MODE_BATCH,
                'processing_started_at' => now(),
                'batch_started_at' => now(),
                'processing_progress' => 'Uploading to cloud storage',
                'error_code' => null,
                'error_message' => null,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            $fresh = $document->fresh();
            if ($fresh?->provider_operation_name) {
                $this->dispatchBatchStatusCheck($document->id);
            }

            return;
        }

        $document->refresh();
        $document->increment('processing_attempts');
        $this->logActivity('OCR Batch Upload Started', $document, 'Uploading document to Cloud Storage for batch OCR.');

        try {
            $this->modeSelector->assertBatchConfigured();

            if (! Storage::disk($document->storage_disk)->exists($document->storage_path)) {
                throw new OcrFileException('Stored document file is missing.', 'storage_missing');
            }

            $binary = (string) Storage::disk($document->storage_disk)->get($document->storage_path);
            $submitted = $this->batchService->submit($document, $binary);

            $document->update([
                'status' => OcrDocument::STATUS_PROCESSING,
                'provider_operation_name' => $submitted['operation_name'],
                'gcs_input_uri' => $submitted['gcs_input_uri'],
                'gcs_output_uri' => $submitted['gcs_output_uri'],
                'processing_progress' => 'Batch OCR operation running',
                'provider_reference' => $submitted['operation_name'],
            ]);

            $this->logActivity('OCR Batch Submitted', $document, 'Batch OCR operation submitted.');
            $this->dispatchBatchStatusCheck($document->id);
        } catch (DocumentAiConfigurationException $exception) {
            $this->markFailed($document, 'configuration_error', $exception->getMessage(), false);
            throw $exception;
        } catch (OcrProviderException $exception) {
            $this->markFailed($document, $exception->errorCode, $exception->getMessage(), $exception->retryable);
            throw $exception;
        } catch (Throwable $exception) {
            $this->markFailed(
                $document,
                'batch_start_failed',
                'Unable to start batch OCR. Please retry or verify Cloud Storage configuration.',
                true,
            );
            throw $exception;
        }
    }

    public function checkBatchProcessing(int $ocrDocumentId): void
    {
        $document = OcrDocument::query()->find($ocrDocumentId);
        if (! $document || ! $document->provider_operation_name) {
            return;
        }

        if (in_array($document->status, [
            OcrDocument::STATUS_COMPLETED,
            OcrDocument::STATUS_FAILED,
            OcrDocument::STATUS_CANCELLED,
            OcrDocument::STATUS_FINALIZING,
        ], true)) {
            return;
        }

        $timeoutMinutes = max(5, (int) config('document-ai.batch_timeout_minutes', 60));
        $startedAt = $document->batch_started_at ?? $document->processing_started_at;
        if ($startedAt && $startedAt->lt(now()->subMinutes($timeoutMinutes))) {
            $this->markFailed(
                $document,
                'batch_timeout',
                'Batch OCR timed out before completion. Please retry.',
                true,
            );

            return;
        }

        try {
            $status = $this->batchService->checkOperation((string) $document->provider_operation_name);

            if (! $status['done']) {
                $document->update([
                    'status' => OcrDocument::STATUS_PROCESSING,
                    'processing_progress' => 'Batch OCR operation running',
                ]);
                $this->dispatchBatchStatusCheck($document->id);

                return;
            }

            if ($status['error']) {
                $this->markFailed($document, 'batch_failed', $status['error'], true);

                return;
            }

            $document->update([
                'status' => OcrDocument::STATUS_FINALIZING,
                'processing_progress' => 'Finalizing OCR results',
            ]);

            FinalizeBatchOcrResultJob::dispatch($document->id)
                ->onQueue((string) config('document-ai.queue', 'ocr'));
        } catch (OcrProviderException $exception) {
            if ($this->isTransientBatchStatusError($exception)) {
                $this->requeueBatchStatusCheck($document, $ocrDocumentId, $exception->getMessage());

                return;
            }

            $this->markFailed($document, $exception->errorCode, $exception->getMessage(), $exception->retryable);
            throw $exception;
        } catch (Throwable $exception) {
            if ($this->isTransientBatchStatusError($exception)) {
                $this->requeueBatchStatusCheck($document, $ocrDocumentId, $exception->getMessage());

                return;
            }

            $this->markFailed(
                $document,
                'batch_status_failed',
                $this->exceptionMessage($exception, 'Unable to check batch OCR status. Please retry shortly.'),
                true,
            );
            throw $exception;
        }
    }

    private function isTransientBatchStatusError(Throwable $exception): bool
    {
        $parts = [$exception->getMessage()];
        $previous = $exception->getPrevious();
        while ($previous) {
            $parts[] = $previous->getMessage();
            $previous = $previous->getPrevious();
        }
        $message = Str::lower(implode(' ', $parts));

        if ($exception instanceof OcrProviderException && $exception->errorCode === 'batch_status_failed' && $exception->retryable) {
            return true;
        }

        return str_contains($message, 'descriptor pool')
            || str_contains($message, 'unavailable')
            || str_contains($message, 'deadline')
            || str_contains($message, 'rate')
            || str_contains($message, 'unable to check batch ocr status');
    }

    private function requeueBatchStatusCheck(OcrDocument $document, int $ocrDocumentId, string $message): void
    {
        \Illuminate\Support\Facades\Log::warning('ocr.pipeline.batch_status_transient', [
            'ocr_document_id' => $ocrDocumentId,
            'error_message' => $message,
        ]);
        $document->update([
            'status' => OcrDocument::STATUS_PROCESSING,
            'processing_progress' => 'Batch OCR operation running',
            'error_code' => null,
            'error_message' => null,
        ]);
        $this->dispatchBatchStatusCheck($document->id);
    }

    public function finalizeBatchProcessing(int $ocrDocumentId): void
    {
        $document = OcrDocument::query()->find($ocrDocumentId);
        if (! $document) {
            return;
        }

        if ($document->status === OcrDocument::STATUS_COMPLETED && $document->result_checksum) {
            return;
        }

        if (! $document->gcs_output_uri) {
            $this->markFailed($document, 'batch_output_missing', 'Batch OCR output location is missing.', true);

            return;
        }

        $document->update([
            'status' => OcrDocument::STATUS_FINALIZING,
            'processing_progress' => 'Finalizing OCR results',
        ]);

        try {
            $result = $this->batchService->finalizeFromGcs(
                (string) $document->gcs_output_uri,
                (int) ($document->page_count ?? $document->total_pages ?? 0) ?: null,
            );

            if ($document->result_checksum && $document->result_checksum === $result['result_checksum']) {
                $document->update([
                    'status' => OcrDocument::STATUS_COMPLETED,
                    'processing_progress' => 'Completed',
                    'batch_completed_at' => now(),
                    'processed_at' => now(),
                ]);

                return;
            }

            $document->update([
                'status' => OcrDocument::STATUS_COMPLETED,
                'extracted_text' => $result['text'],
                'structured_data' => [
                    'pages' => $result['pages'],
                    'entities' => [],
                    'languages' => $result['languages'],
                    'metadata' => [
                        'provider' => 'google_document_ai',
                        'mode' => 'batch',
                        'shard_count' => $result['shard_count'],
                        'expected_pages' => $result['expected_pages'] ?? null,
                        'received_pages' => $result['received_pages'] ?? null,
                        'unique_pages' => $result['unique_pages'] ?? null,
                        'missing_pages' => $result['missing_pages'] ?? [],
                        'duplicate_pages' => $result['duplicate_pages'] ?? [],
                        'page_reconciliation_ok' => $result['page_reconciliation_ok'] ?? true,
                    ],
                ],
                'page_count' => $result['page_count'],
                'total_pages' => $result['page_count'],
                'processed_pages' => $result['page_count'],
                'detected_languages' => $result['languages'],
                'average_confidence' => $result['average_confidence'],
                'result_checksum' => $result['result_checksum'],
                'processing_progress' => 'Completed',
                'batch_completed_at' => now(),
                'processed_at' => now(),
                'error_code' => null,
                'error_message' => null,
                'provider' => 'google_document_ai',
            ]);

            $this->batchService->cleanupAfterSuccess($document->fresh());
            $this->logActivity('OCR Completed', $document->fresh(), 'Batch OCR processing completed.');
            $this->queueOrRunStructureParse($document->fresh());
        } catch (OcrProviderException $exception) {
            $this->markFailed($document, $exception->errorCode, $exception->getMessage(), $exception->retryable);
            throw $exception;
        } catch (Throwable $exception) {
            $this->markFailed(
                $document,
                'batch_finalize_failed',
                'Batch OCR finished but results could not be saved. Please retry.',
                true,
            );
            throw $exception;
        }
    }

    public function ensureCanAccessDocument(OcrDocument $document): void
    {
        if ($document->ca_id) {
            $this->employeeDataScope->ensureCanAccessCaMaster($document->ca_id);

            return;
        }

        $user = auth()->user();
        if (! $user) {
            abort(403, 'You do not have access to this OCR document.');
        }

        $role = app(\App\Services\Rbac\RbacService::class)->roleKey($user);
        if (in_array($role, ['super_admin', 'admin', 'manager'], true)) {
            return;
        }

        if ((int) $document->uploaded_by === (int) $user->id) {
            return;
        }

        abort(403, 'You do not have access to this OCR document.');
    }

    private function processOnline(OcrDocument $document): void
    {
        // Persist "processing" before the Google call so UI polling sees progress immediately.
        // Do not wrap the Google HTTP call in a database transaction.
        $document->refresh();
        $document->update([
            'status' => OcrDocument::STATUS_PROCESSING,
            'processing_mode' => OcrProcessingModeSelector::MODE_ONLINE,
            'processing_attempts' => ((int) $document->processing_attempts) + 1,
            'processing_started_at' => now(),
            'processing_progress' => 'Online OCR processing',
            'error_code' => null,
            'error_message' => null,
        ]);
        $document->refresh();
        $this->logPipelineStep('ocr_provider_start', $document);
        $this->registerOnlineProcessingShutdownGuard((int) $document->id);

        $this->logActivity('OCR Processing Started', $document, 'Online OCR processing started.');

        try {
            if (! Storage::disk($document->storage_disk)->exists($document->storage_path)) {
                throw new OcrFileException('Stored document file is missing.', 'storage_missing');
            }

            $binary = Storage::disk($document->storage_disk)->get($document->storage_path);
            $this->logPipelineStep('ocr_file_loaded', $document, ['bytes' => strlen((string) $binary)]);
            $started = microtime(true);
            $result = $this->documentAiService->processBinary($binary, $document->mime_type);
            unset($binary);
            $googleMs = (int) round((microtime(true) - $started) * 1000);
            $this->logPipelineStep('ocr_provider_response', $document, [
                'google_duration_ms' => $googleMs,
                'page_count' => $result['page_count'] ?? null,
                'text_length' => mb_strlen((string) ($result['text'] ?? '')),
            ]);

            $structured = $this->leanStructuredPayload($result, [
                'mode' => 'online',
                'google_duration_ms' => $googleMs,
            ]);

            $document->update([
                'status' => OcrDocument::STATUS_COMPLETED,
                'extracted_text' => $result['text'],
                'structured_data' => $structured,
                'page_count' => $result['page_count'] ?? null,
                'total_pages' => $result['page_count'] ?? null,
                'processed_pages' => $result['page_count'] ?? null,
                'detected_languages' => $result['languages'] ?? $result['detected_languages'] ?? [],
                'average_confidence' => $result['average_confidence'] ?? null,
                'processor_name' => $result['processor_name'] ?? null,
                'provider' => 'google_document_ai',
                'provider_reference' => $result['provider_reference'] ?? ($result['processor_name'] ?? null),
                'processing_progress' => 'OCR complete — parsing structure',
                'processed_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ]);
            unset($result, $structured);

            $this->logPipelineStep('ocr_text_saved', $document->fresh() ?? $document, [
                'google_duration_ms' => $googleMs,
                'page_count' => $document->page_count,
            ]);

            $this->logActivity('OCR Completed', $document, 'OCR processing completed.');
            $this->queueOrRunStructureParse($document->fresh());
        } catch (DocumentAiConfigurationException $exception) {
            $this->markFailed($document, 'configuration_error', $exception->getMessage(), false);
            throw $exception;
        } catch (OcrProviderException $exception) {
            $this->markFailed($document, $exception->errorCode, $exception->getMessage(), $exception->retryable);
            throw $exception;
        } catch (Throwable $exception) {
            $this->markFailed(
                $document,
                $this->exceptionErrorCode($exception, 'processing_failed'),
                $this->exceptionMessage($exception, 'The document could not be processed. Please verify the OCR configuration or retry.'),
                true,
            );

            throw $exception;
        }
    }

    private function shouldProcessSmallFileSynchronously(OcrDocument $document): bool
    {
        if (! filter_var(config('document-ai.sync_small_files', true), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        // Master CA imports parse hundreds of firms and must never block the upload HTTP request.
        if ($document->isMasterCaImport()) {
            return false;
        }

        if ($document->processing_mode !== OcrProcessingModeSelector::MODE_ONLINE) {
            return false;
        }

        $maxPages = max(1, (int) config('document-ai.sync_max_pages', 5));
        $maxBytes = max(1, (int) config('document-ai.sync_max_file_mb', 5)) * 1024 * 1024;
        $pages = $document->page_count;

        if ($pages !== null && (int) $pages > $maxPages) {
            return false;
        }

        // Unknown page count: only sync when the payload is clearly tiny.
        if ($pages === null && (int) $document->file_size > (512 * 1024)) {
            return false;
        }

        return (int) $document->file_size <= $maxBytes;
    }

    private function dispatchProcessingJob(int $ocrDocumentId, ?string $requestId = null): void
    {
        $queue = (string) config('document-ai.queue', 'default');
        // Dispatch only after the create transaction above has committed (production).
        // Avoid afterCommit here: PHPUnit DatabaseTransactions would delay the job until tearDown.
        ProcessOcrDocumentJob::dispatch($ocrDocumentId)->onQueue($queue);

        Log::info('ocr.pipeline.step', [
            'step' => 'ocr_job_queued',
            'request_id' => $requestId,
            'ocr_document_id' => $ocrDocumentId,
            'job_class' => ProcessOcrDocumentJob::class,
            'queue' => $queue,
            'queue_connection' => (string) config('queue.default'),
        ]);

        // Local/Hostinger: kick OCR without blocking the upload HTTP response.
        // - php-fpm: optional afterResponse drain (max 1 short job)
        // - php artisan serve: spawn a background queue:work (non-blocking)
        if ($this->shouldAutoDrainOcrQueue()) {
            $drainQueue = (string) config('document-ai.drain_queue', 'ocr');
            dispatch(function () use ($drainQueue) {
                try {
                    \Illuminate\Support\Facades\Artisan::call('queue:work', [
                        '--stop-when-empty' => true,
                        '--max-jobs' => 1,
                        '--max-time' => 45,
                        '--tries' => 2,
                        '--timeout' => 60,
                        '--queue' => $drainQueue,
                    ]);
                } catch (Throwable $exception) {
                    Log::warning('ocr.queue.inline_drain_failed', [
                        'error' => class_basename($exception),
                    ]);
                }
            })->afterResponse();
        } elseif ($this->shouldSpawnBackgroundQueueWorker()) {
            $this->spawnBackgroundQueueWorker();
        }
    }

    private function shouldAutoDrainOcrQueue(): bool
    {
        if (! filter_var(config('document-ai.auto_drain_after_dispatch', false), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }
        if (config('queue.default') === 'sync') {
            return false;
        }
        // php artisan serve cannot finish the HTTP body until afterResponse ends.
        if (PHP_SAPI === 'cli-server') {
            return false;
        }

        return true;
    }

    private function shouldSpawnBackgroundQueueWorker(): bool
    {
        if (config('queue.default') === 'sync') {
            return false;
        }

        // Always kick a background worker for local `artisan serve` so Master CA
        // jobs do not sit on "Queued for online OCR" with no queue:work running.
        if (PHP_SAPI === 'cli-server') {
            return true;
        }

        return filter_var(config('document-ai.auto_drain_after_dispatch', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function spawnBackgroundQueueWorker(): void
    {
        $queues = (string) config('document-ai.queue_worker_list', 'ocr,ocr-import,default');
        $php = PHP_BINARY ?: 'php';
        $artisan = base_path('artisan');
        $log = storage_path('logs/ocr-queue-drain.log');

        try {
            if (PHP_OS_FAMILY === 'Windows') {
                pclose(popen(
                    'start /B "" '.escapeshellarg($php).' '.escapeshellarg($artisan)
                    .' queue:work --queue='.escapeshellarg($queues)
                    .' --stop-when-empty --max-jobs=3 --max-time=180 --tries=2 --timeout=120',
                    'r'
                ));
            } else {
                $cmd = sprintf(
                    'nohup %s %s queue:work --queue=%s --stop-when-empty --max-jobs=3 --max-time=180 --tries=2 --timeout=120 >> %s 2>&1 &',
                    escapeshellarg($php),
                    escapeshellarg($artisan),
                    escapeshellarg($queues),
                    escapeshellarg($log),
                );
                exec($cmd);
            }

            Log::info('ocr.pipeline.step', [
                'step' => 'background_queue_worker_spawned',
                'queues' => $queues,
                'sapi' => PHP_SAPI,
            ]);
        } catch (Throwable $exception) {
            Log::warning('ocr.queue.background_spawn_failed', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Recover records stuck in queued/processing with no active progress.
     *
     * @return array{redispatched: int, timed_out: int, skipped: int, released_reserved: int}
     */
    public function recoverStuckDocuments(?int $minutes = null): array
    {
        $redispatched = 0;
        $timedOut = 0;
        $skipped = 0;
        $releasedReserved = $this->releaseStaleReservedJobs($minutes);

        $queuedStuckMinutes = $minutes ?? (int) config('document-ai.queued_stuck_minutes', 5);
        $processingStuckMinutes = $minutes ?? (int) config('document-ai.processing_stuck_minutes', 15);

        $queued = OcrDocument::query()
            ->where('status', OcrDocument::STATUS_QUEUED)
            ->where('updated_at', '<', now()->subMinutes($queuedStuckMinutes))
            ->limit(50)
            ->get();

        foreach ($queued as $document) {
            if ($document->processing_mode === OcrProcessingModeSelector::MODE_BATCH
                && $document->provider_operation_name) {
                $skipped++;
                continue;
            }

            ProcessOcrDocumentJob::dispatch($document->id)->onQueue((string) config('document-ai.queue', 'default'));
            $document->update([
                'processing_progress' => 'Re-queued after stuck recovery',
            ]);
            $redispatched++;
        }

        $processing = OcrDocument::query()
            ->whereIn('status', [
                OcrDocument::STATUS_PROCESSING,
                OcrDocument::STATUS_UPLOADING_TO_CLOUD,
                OcrDocument::STATUS_FINALIZING,
            ])
            ->where(function ($query) use ($processingStuckMinutes) {
                $query->where('processing_started_at', '<', now()->subMinutes($processingStuckMinutes))
                    ->orWhere(function ($inner) use ($processingStuckMinutes) {
                        $inner->whereNull('processing_started_at')
                            ->where('updated_at', '<', now()->subMinutes($processingStuckMinutes));
                    });
            })
            ->limit(50)
            ->get();

        foreach ($processing as $document) {
            if ($document->processing_mode === OcrProcessingModeSelector::MODE_BATCH
                && $document->provider_operation_name
                && $document->status === OcrDocument::STATUS_PROCESSING) {
                CheckBatchOcrStatusJob::dispatch($document->id);
                $skipped++;
                continue;
            }

            // Online / interrupted jobs: re-queue once more before failing permanently.
            if ((int) $document->processing_attempts < 3 && ! filled($document->extracted_text)) {
                $document->update([
                    'status' => OcrDocument::STATUS_QUEUED,
                    'processing_progress' => 'Re-queued after stuck recovery',
                    'error_code' => null,
                    'error_message' => null,
                ]);
                ProcessOcrDocumentJob::dispatch($document->id)->onQueue((string) config('document-ai.queue', 'default'));
                $this->logPipelineStep('stuck_online_redispatched', $document);
                $redispatched++;
                continue;
            }

            $this->markFailed(
                $document,
                'processing_timeout',
                'OCR processing timed out. Please retry.',
                true,
            );
            $timedOut++;
        }

        $masterImportStuckMinutes = $minutes ?? (int) config('document-ai.queued_stuck_minutes', 5);
        $masterStuck = OcrDocument::query()
            ->where('status', OcrDocument::STATUS_COMPLETED)
            ->where('import_type', OcrDocument::IMPORT_MASTER_CA)
            ->where('parse_status', 'completed')
            ->where('updated_at', '<', now()->subMinutes($masterImportStuckMinutes))
            ->where(function ($q) {
                $q->where('processing_progress', 'like', '%Queued for Master CA%')
                    ->orWhere('processing_progress', 'like', '%Importing official%')
                    ->orWhere('processing_progress', 'like', '%Validating official%');
            })
            ->limit(20)
            ->get();

        foreach ($masterStuck as $document) {
            $pending = OcrParsedFirm::query()
                ->where('ocr_document_id', $document->id)
                ->where(function ($q) {
                    $q->whereNull('match_status')
                        ->orWhereIn('match_status', ['pending', 'unmatched']);
                })
                ->exists();
            if (! $pending) {
                app(MasterCaDirectImportService::class)->refreshDocumentCompletion($document->fresh());
                $skipped++;
                continue;
            }

            $document->update(['processing_progress' => 'Queued for Master CA import']);
            ImportMasterCaOcrJob::dispatch((int) $document->id, null)
                ->onQueue((string) config('document-ai.import_queue', 'ocr-import'));
            $redispatched++;
        }

        // OCR finished but structure parse never ran (worker was busy/hung).
        $parseStuck = OcrDocument::query()
            ->where('status', OcrDocument::STATUS_COMPLETED)
            ->whereIn('parse_status', ['queued', 'processing'])
            ->where('updated_at', '<', now()->subMinutes(max(1, (int) ($minutes ?? 2))))
            ->limit(20)
            ->get();

        foreach ($parseStuck as $document) {
            try {
                $this->structurePersistService->parseAndPersist($document);
                $redispatched++;
            } catch (Throwable $exception) {
                Log::warning('ocr.pipeline.stuck_parse_recovery_failed', [
                    'ocr_document_id' => $document->id,
                    'error_message' => $exception->getMessage(),
                ]);
                $skipped++;
            }
        }

        return [
            'redispatched' => $redispatched,
            'timed_out' => $timedOut,
            'skipped' => $skipped,
            'released_reserved' => $releasedReserved,
        ];
    }

    /**
     * Release database-queue jobs that died mid-reserve so the worker can claim them again.
     */
    private function releaseStaleReservedJobs(?int $minutes = null): int
    {
        if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
            return 0;
        }

        $retryAfter = max(60, (int) config('queue.connections.database.retry_after', 90));
        $staleSeconds = max($retryAfter, ($minutes ?? 10) * 60);
        $cutoff = now()->subSeconds($staleSeconds)->getTimestamp();

        return (int) DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<', $cutoff)
            ->update([
                'reserved_at' => null,
                'available_at' => now()->getTimestamp(),
            ]);
    }

    private function dispatchBatchStatusCheck(int $ocrDocumentId): void
    {
        $delay = max(5, (int) config('document-ai.batch_poll_seconds', 10));
        CheckBatchOcrStatusJob::dispatch($ocrDocumentId)
            ->onQueue((string) config('document-ai.queue', 'ocr'))
            ->delay(now()->addSeconds($delay));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\OcrDocument>  $query
     */
    private function applyVisibilityScope($query, User $user): void
    {
        $role = app(\App\Services\Rbac\RbacService::class)->roleKey($user);
        $rbac = app(\App\Services\Rbac\RbacService::class);

        if (in_array($role, ['super_admin', 'admin', 'manager'], true) || $rbac->can($user, 'ocr', 'view_all')) {
            return;
        }

        $scopedEmployeeId = $this->employeeDataScope->scopedEmployeeId($user);

        $query->where(function ($q) use ($user, $scopedEmployeeId) {
            $q->where('uploaded_by', $user->id);

            if ($scopedEmployeeId && $scopedEmployeeId > 0) {
                $q->orWhereIn('ca_id', function ($sub) use ($scopedEmployeeId) {
                    $sub->select('ca_id')
                        ->from('lead_assignment_engines')
                        ->where('employee_id', $scopedEmployeeId);
                });
            }
        });
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|\Illuminate\Database\Query\Builder  $query
     */
    private function whereLike($query, string $column, string $likeValue, string $boolean = 'and'): void
    {
        $driver = $query->getConnection()->getDriverName();
        $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

        if ($driver === 'pgsql') {
            $query->{$method}($column.' ILIKE ?', [$likeValue]);

            return;
        }

        $query->{$method}('LOWER('.$column.') LIKE LOWER(?)', [$likeValue]);
    }

    /**
     * Force a fresh structure parse (used after corrections or manual rebuild).
     */
    public function reparseStructure(OcrDocument $document): OcrDocument
    {
        $this->ensureCanAccessDocument($document);

        $structured = is_array($document->structured_data) ? $document->structured_data : [];
        if (isset($structured['parsed']) && is_array($structured['parsed'])) {
            $structured['parsed']['error'] = null;
        }

        $document->update([
            'parse_status' => null,
            'parsed_firm_count' => null,
            'parsed_at' => null,
            'structured_data' => $structured,
            'error_code' => null,
            'error_message' => null,
            'processing_progress' => 'Structuring OCR results',
        ]);

        // Drop stale match/import counters so UI cannot mix old and new runs.
        $structured = is_array($document->structured_data) ? $document->structured_data : [];
        unset($structured['master_import'], $structured['mapping'], $structured['reconciliation']);
        if (isset($structured['parsed']) && is_array($structured['parsed'])) {
            unset($structured['parsed']['quality_report'], $structured['parsed']['reconciliation'], $structured['parsed']['completeness']);
        }
        $document->update(['structured_data' => $structured]);

        return $this->structurePersistService->parseAndPersist($document->fresh());
    }

    /**
     * Structure parse runs in the OCR worker process (already async).
     * Do not enqueue a second ParseOcrStructureJob — a busy/hung import worker
     * leaves documents stuck on "Structuring OCR results" forever.
     */
    private function queueOrRunStructureParse(?OcrDocument $document): void
    {
        if (! $document || ! $document->isCompleted()) {
            return;
        }

        $text = (string) ($document->displayText() ?? '');
        if (trim($text) === '') {
            $document->update([
                'parse_status' => 'completed',
                'parsed_firm_count' => 0,
                'parsed_at' => now(),
                'processing_progress' => 'Completed',
            ]);
            $this->logPipelineStep('parse_skipped_empty_text', $document);

            return;
        }

        // Extremely large extracts only — keep HTTP/OCR job timeout headroom.
        $pageCount = (int) ($document->page_count ?? $document->total_pages ?? 0);
        $mustQueue = mb_strlen($text) > 200000 || $pageCount > 40;

        if ($mustQueue) {
            $document->update([
                'parse_status' => 'queued',
                'processing_progress' => 'Structuring OCR results',
            ]);
            ParseOcrStructureJob::dispatch($document->id)->onQueue((string) config('document-ai.queue', 'default'));
            $this->logPipelineStep('parse_job_dispatched', $document, [
                'text_length' => mb_strlen($text),
                'page_count' => $pageCount,
                'import_type' => $document->import_type,
                'queue' => (string) config('document-ai.queue', 'default'),
            ]);

            return;
        }

        try {
            $this->logPipelineStep('parse_inline_start', $document);
            $this->structurePersistService->parseAndPersist($document);
        } catch (Throwable $exception) {
            // OCR text is already saved — keep status=completed, surface parse error for retry.
            $fresh = $document->fresh() ?? $document;
            $fresh->update([
                'parse_status' => 'failed',
                'error_code' => $this->exceptionErrorCode($exception, 'structure_parse_failed'),
                'error_message' => $this->exceptionMessage($exception, 'Structure parsing failed.'),
                'processing_progress' => 'Parsing failed — retry available',
            ]);
            Log::error('ocr.pipeline.structure_inline_failed', [
                'ocr_document_id' => $document->id,
                'error_code' => $fresh->error_code,
                'error_message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }
    }

    private function markFailed(OcrDocument $document, string $errorCode, string $message, bool $retryable): void
    {
        $document->update([
            'status' => OcrDocument::STATUS_FAILED,
            'error_code' => $errorCode,
            'error_message' => mb_substr($message, 0, 2000),
            'processing_progress' => null,
            'failed_at' => now(),
            'processed_at' => now(),
        ]);

        $this->logActivity('OCR Failed', $document, 'OCR processing failed.');

        Log::error('ocr.pipeline.failed', [
            'step' => 'mark_failed',
            'ocr_document_id' => $document->id,
            'ca_id' => $document->ca_id,
            'error_code' => $errorCode,
            'error_message' => $message,
            'retryable' => $retryable,
            'status' => OcrDocument::STATUS_FAILED,
        ]);
    }

    /**
     * List/history endpoints never need full OCR payloads in memory.
     *
     * @return list<string>
     */
    private function listColumns(): array
    {
        return [
            'id', 'ca_id', 'uploaded_by', 'import_type', 'original_filename', 'stored_filename',
            'storage_disk', 'storage_path', 'mime_type', 'file_size', 'checksum',
            'status', 'processing_mode', 'processing_progress', 'processing_attempts',
            'parse_status', 'parsed_firm_count', 'parsed_at',
            'page_count', 'total_pages', 'processed_pages', 'average_confidence', 'detected_languages',
            'error_code', 'error_message',
            'processing_started_at', 'batch_started_at', 'batch_completed_at', 'processed_at', 'failed_at',
            'provider', 'provider_reference', 'provider_operation_name',
            'created_at', 'updated_at',
        ];
    }

    /**
     * Persist only layout fields the structure parsers need — never page images or duplicate nests.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $extraMeta
     * @return array<string, mixed>
     */
    private function leanStructuredPayload(array $result, array $extraMeta = []): array
    {
        $tables = $result['tables'] ?? ($result['structured_data']['tables'] ?? []);
        $entities = $result['entities'] ?? ($result['structured_data']['entities'] ?? []);
        $pagesIn = $result['pages'] ?? ($result['structured_data']['pages'] ?? []);
        $pages = [];

        foreach (is_array($pagesIn) ? $pagesIn : [] as $page) {
            if (! is_array($page)) {
                continue;
            }
            $paragraphs = [];
            foreach ($page['paragraphs'] ?? [] as $paragraph) {
                if (! is_array($paragraph)) {
                    continue;
                }
                $lean = ['text' => (string) ($paragraph['text'] ?? '')];
                if (array_key_exists('x', $paragraph)) {
                    $lean['x'] = $paragraph['x'];
                }
                if (array_key_exists('y', $paragraph)) {
                    $lean['y'] = $paragraph['y'];
                }
                if (array_key_exists('confidence', $paragraph)) {
                    $lean['confidence'] = $paragraph['confidence'];
                }
                if (! empty($paragraph['bounding_box']) && is_array($paragraph['bounding_box'])) {
                    $lean['bounding_box'] = $paragraph['bounding_box'];
                }
                $paragraphs[] = $lean;
            }

            $pages[] = [
                'page_number' => $page['page_number'] ?? null,
                'languages' => $page['languages'] ?? [],
                'paragraph_count' => count($paragraphs),
                'paragraphs' => $paragraphs,
                'confidence' => $page['confidence'] ?? null,
                'line_count' => $page['line_count'] ?? count($paragraphs),
                'table_count' => $page['table_count'] ?? 0,
                'has_text' => $page['has_text'] ?? ($paragraphs !== []),
                'text_length' => $page['text_length'] ?? null,
            ];
        }

        return [
            'pages' => $pages,
            'entities' => is_array($entities) ? $entities : [],
            'tables' => is_array($tables) ? $tables : [],
            'extraction_mode' => $result['structured_data']['extraction_mode']
                ?? (($tables !== [] && $tables !== null) ? 'document_ai_tables_and_paragraphs' : 'document_ai_paragraphs'),
            'languages' => $result['languages'] ?? $result['detected_languages'] ?? [],
            'metadata' => array_merge($result['metadata'] ?? [], $extraMeta, [
                'table_count' => is_array($tables) ? count($tables) : 0,
                'imageless_mode' => filter_var(config('document-ai.imageless_mode', true), FILTER_VALIDATE_BOOLEAN),
            ]),
        ];
    }

    /**
     * FatalError/OOM bypasses catch(Throwable). Mark the document failed so the UI can Retry.
     */
    private function registerOnlineProcessingShutdownGuard(int $ocrDocumentId): void
    {
        register_shutdown_function(static function () use ($ocrDocumentId): void {
            $error = error_get_last();
            if ($error === null || ! is_array($error)) {
                return;
            }

            $message = (string) ($error['message'] ?? '');
            $type = (int) ($error['type'] ?? 0);
            $isFatal = in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)
                || str_contains($message, 'Allowed memory size')
                || str_contains($message, 'Out of memory');
            if (! $isFatal) {
                return;
            }

            try {
                $document = OcrDocument::query()->find($ocrDocumentId);
                if (! $document || ! in_array($document->status, OcrDocument::ACTIVE_STATUSES, true)) {
                    return;
                }

                $code = str_contains($message, 'Allowed memory size') || str_contains($message, 'Out of memory')
                    ? 'memory_exhausted'
                    : 'processing_fatal';
                $document->update([
                    'status' => OcrDocument::STATUS_FAILED,
                    'error_code' => $code,
                    'error_message' => mb_substr(
                        $code === 'memory_exhausted'
                            ? 'OCR processing ran out of memory while decoding the Document AI response. Please retry.'
                            : 'OCR processing stopped unexpectedly. Please retry.',
                        0,
                        2000,
                    ),
                    'processing_progress' => null,
                    'failed_at' => now(),
                    'processed_at' => now(),
                ]);
                Log::error('ocr.pipeline.shutdown_marked_failed', [
                    'ocr_document_id' => $ocrDocumentId,
                    'error_code' => $code,
                    'php_error' => mb_substr($message, 0, 500),
                ]);
            } catch (Throwable) {
                // Best-effort only — process is already dying.
            }
        });
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logPipelineStep(string $step, OcrDocument $document, array $context = []): void
    {
        Log::info('ocr.pipeline.step', array_merge([
            'step' => $step,
            'ocr_document_id' => $document->id,
            'status' => $document->status,
            'parse_status' => $document->parse_status,
            'processing_mode' => $document->processing_mode,
            'processing_progress' => $document->processing_progress,
            'original_filename' => $document->original_filename,
        ], $context));
    }

    private function exceptionErrorCode(Throwable $exception, string $fallback): string
    {
        if ($exception instanceof OcrFileException && filled($exception->errorCode)) {
            return (string) $exception->errorCode;
        }
        if ($exception instanceof OcrProviderException && filled($exception->errorCode)) {
            return (string) $exception->errorCode;
        }

        return $fallback;
    }

    private function exceptionMessage(Throwable $exception, string $fallback): string
    {
        $message = trim($exception->getMessage());

        return $message !== '' ? mb_substr($message, 0, 2000) : $fallback;
    }

    private function logActivity(string $action, OcrDocument $document, string $description): void
    {
        $this->activityLogService->log(
            moduleName: 'OCR',
            action: $action,
            recordId: (string) $document->id,
            description: $description.' Status: '.$document->status.($document->ca_id ? ' · CA #'.$document->ca_id : ''),
        );
    }
}
