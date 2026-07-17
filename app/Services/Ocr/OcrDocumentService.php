<?php

namespace App\Services\Ocr;

use App\Contracts\Ocr\OcrProcessorInterface;
use App\Exceptions\DocumentAi\DocumentAiConfigurationException;
use App\Exceptions\Ocr\OcrFileException;
use App\Exceptions\Ocr\OcrProviderException;
use App\Jobs\CheckBatchOcrStatusJob;
use App\Jobs\FinalizeBatchOcrResultJob;
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

    public function store(UploadedFile $file, ?int $caId, User $user, bool $forceReimport = false): OcrDocument
    {
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

            $document = OcrDocument::query()->create([
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
                'processing_mode' => $decision['mode'],
                'page_count' => $decision['page_count'],
                'total_pages' => $decision['page_count'],
                'processing_progress' => $decision['mode'] === OcrProcessingModeSelector::MODE_BATCH
                    ? 'Queued for batch OCR'
                    : 'Queued for online OCR',
            ]);

            $this->logActivity('OCR Document Uploaded', $document, 'OCR document uploaded.');

            if ($this->shouldProcessSmallFileSynchronously($document)) {
                $this->logActivity('OCR Processing Started', $document, 'Small-file online OCR fast path.');
                $timeout = (int) config('document-ai.sync_timeout_seconds', 60);
                if (function_exists('set_time_limit')) {
                    @set_time_limit($timeout + 15);
                }

                try {
                    $this->processOnline($document->fresh() ?? $document);
                } catch (Throwable $exception) {
                    Log::warning('ocr.document.sync_fast_path_failed', [
                        'ocr_document_id' => $document->id,
                        'error' => class_basename($exception),
                    ]);
                }

                return $document->fresh(['uploader:id,name,email', 'caMaster:ca_id,firm_name,ca_name']);
            }

            $this->logActivity('OCR Processing Queued', $document, 'OCR processing queued ('.$decision['mode'].').');
            $this->dispatchProcessingJob($document->id);

            Log::info('ocr.document.queued', [
                'ocr_document_id' => $document->id,
                'uploaded_by' => $user->id,
                'mime_type' => $document->mime_type,
                'file_size' => $document->file_size,
                'processing_mode' => $document->processing_mode,
                'page_count' => $document->page_count,
            ]);

            return $document->fresh(['uploader:id,name,email', 'caMaster:ca_id,firm_name,ca_name']);
        } catch (Throwable $exception) {
            if ($stored) {
                Storage::disk($disk)->delete($storagePath);
            }

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
            return;
        }

        if (in_array($document->status, [
            OcrDocument::STATUS_COMPLETED,
            OcrDocument::STATUS_CANCELLED,
            OcrDocument::STATUS_UPLOADING_TO_CLOUD,
            OcrDocument::STATUS_PROCESSING,
            OcrDocument::STATUS_FINALIZING,
        ], true)) {
            return;
        }

        if ($document->status !== OcrDocument::STATUS_QUEUED && $document->status !== OcrDocument::STATUS_PENDING) {
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

            FinalizeBatchOcrResultJob::dispatch($document->id);
        } catch (OcrProviderException $exception) {
            $this->markFailed($document, $exception->errorCode, $exception->getMessage(), $exception->retryable);
        } catch (Throwable $exception) {
            $this->markFailed(
                $document,
                'batch_status_failed',
                'Unable to check batch OCR status. Please retry shortly.',
                true,
            );
            unset($exception);
        }
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
            $result = $this->batchService->finalizeFromGcs((string) $document->gcs_output_uri);

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

        $this->logActivity('OCR Processing Started', $document, 'Online OCR processing started.');

        try {
            if (! Storage::disk($document->storage_disk)->exists($document->storage_path)) {
                throw new OcrFileException('Stored document file is missing.', 'storage_missing');
            }

            $binary = Storage::disk($document->storage_disk)->get($document->storage_path);
            $started = microtime(true);
            $result = $this->documentAiService->processBinary($binary, $document->mime_type);
            $googleMs = (int) round((microtime(true) - $started) * 1000);

            $document->update([
                'status' => OcrDocument::STATUS_COMPLETED,
                'extracted_text' => $result['text'],
                'structured_data' => [
                    'pages' => $result['pages'] ?? [],
                    'entities' => $result['entities'] ?? [],
                    'languages' => $result['languages'] ?? $result['detected_languages'] ?? [],
                    'metadata' => array_merge($result['metadata'] ?? [], [
                        'mode' => 'online',
                        'google_duration_ms' => $googleMs,
                    ]),
                ],
                'page_count' => $result['page_count'] ?? null,
                'total_pages' => $result['page_count'] ?? null,
                'processed_pages' => $result['page_count'] ?? null,
                'detected_languages' => $result['languages'] ?? $result['detected_languages'] ?? [],
                'average_confidence' => $result['average_confidence'] ?? null,
                'processor_name' => $result['processor_name'] ?? null,
                'provider' => 'google_document_ai',
                'provider_reference' => $result['provider_reference'] ?? ($result['processor_name'] ?? null),
                'processing_progress' => 'Completed',
                'processed_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ]);

            Log::info('ocr.document.completed', [
                'ocr_document_id' => $document->id,
                'google_duration_ms' => $googleMs,
                'page_count' => $result['page_count'] ?? null,
                'mode' => 'online',
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
                'processing_failed',
                'The document could not be processed. Please verify the OCR configuration or retry.',
                true,
            );

            Log::warning('ocr.document.failed', [
                'ocr_document_id' => $document->id,
                'ca_id' => $document->ca_id,
                'error' => class_basename($exception),
            ]);

            throw $exception;
        }
    }

    private function shouldProcessSmallFileSynchronously(OcrDocument $document): bool
    {
        if (! filter_var(config('document-ai.sync_small_files', true), FILTER_VALIDATE_BOOLEAN)) {
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

    private function dispatchProcessingJob(int $ocrDocumentId): void
    {
        ProcessOcrDocumentJob::dispatch($ocrDocumentId);

        // Local/Hostinger: drain a few jobs right after the HTTP response so OCR
        // does not sit in the jobs table until the next cron minute.
        if (config('queue.default') !== 'sync') {
            dispatch(function () {
                try {
                    \Illuminate\Support\Facades\Artisan::call('queue:work', [
                        '--stop-when-empty' => true,
                        '--max-jobs' => 20,
                        '--max-time' => 90,
                        '--tries' => 3,
                        '--timeout' => 300,
                        '--queue' => 'default',
                    ]);
                } catch (Throwable $exception) {
                    Log::warning('ocr.queue.inline_drain_failed', [
                        'error' => class_basename($exception),
                    ]);
                }
            })->afterResponse();
        }
    }

    /**
     * Recover records stuck in queued/processing with no active progress.
     *
     * @return array{redispatched: int, timed_out: int, skipped: int}
     */
    public function recoverStuckDocuments(): array
    {
        $redispatched = 0;
        $timedOut = 0;
        $skipped = 0;

        $queuedStuckMinutes = (int) config('document-ai.queued_stuck_minutes', 5);
        $processingStuckMinutes = (int) config('document-ai.processing_stuck_minutes', 15);

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

            ProcessOcrDocumentJob::dispatch($document->id);
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
                // Let the batch status checker decide; only kick a check.
                CheckBatchOcrStatusJob::dispatch($document->id);
                $skipped++;
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

        return [
            'redispatched' => $redispatched,
            'timed_out' => $timedOut,
            'skipped' => $skipped,
        ];
    }
    private function dispatchBatchStatusCheck(int $ocrDocumentId): void
    {
        $delay = max(5, (int) config('document-ai.batch_poll_seconds', 10));
        CheckBatchOcrStatusJob::dispatch($ocrDocumentId)->delay(now()->addSeconds($delay));
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

        $document->update([
            'parse_status' => null,
            'parsed_firm_count' => null,
            'parsed_at' => null,
        ]);

        return $this->structurePersistService->parseAndPersist($document->fresh());
    }

    /**
     * Structure parse runs inline for modest documents; large texts use a queue job.
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
            ]);

            return;
        }

        // ~50KB threshold keeps sync HTTP paths responsive; large directories go async.
        if (mb_strlen($text) > 50000) {
            $document->update([
                'parse_status' => 'queued',
                'processing_progress' => 'Structuring OCR results',
            ]);
            ParseOcrStructureJob::dispatch($document->id);

            return;
        }

        try {
            $this->structurePersistService->parseAndPersist($document);
        } catch (Throwable $exception) {
            Log::warning('ocr.document.structure_inline_failed', [
                'ocr_document_id' => $document->id,
                'error' => class_basename($exception),
            ]);
        }
    }

    private function markFailed(OcrDocument $document, string $errorCode, string $message, bool $retryable): void
    {
        $document->update([
            'status' => OcrDocument::STATUS_FAILED,
            'error_code' => $errorCode,
            'error_message' => $message,
            'processing_progress' => 'Failed',
            'failed_at' => now(),
            'processed_at' => now(),
        ]);

        $this->logActivity('OCR Failed', $document, 'OCR processing failed.');

        Log::warning('ocr.document.failed', [
            'ocr_document_id' => $document->id,
            'ca_id' => $document->ca_id,
            'error_code' => $errorCode,
            'retryable' => $retryable,
        ]);
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
