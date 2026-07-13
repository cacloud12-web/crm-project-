<?php

namespace App\Services\Ocr;

use App\Exceptions\DocumentAi\DocumentAiConfigurationException;
use App\Exceptions\DocumentAi\DocumentAiProcessingException;
use App\Jobs\ProcessOcrDocumentJob;
use App\Models\CaMaster;
use App\Models\OcrDocument;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use App\Services\DocumentAi\GoogleDocumentAiService;
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
        private readonly GoogleDocumentAiService $documentAiService,
        private readonly ActivityLogService $activityLogService,
        private readonly EmployeeDataScopeService $employeeDataScope,
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

    public function store(UploadedFile $file, int $caId, User $user): OcrDocument
    {
        $this->employeeDataScope->ensureCanAccessCaMaster($caId);
        CaMaster::query()->findOrFail($caId);

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
                throw new DocumentAiProcessingException('The uploaded document is empty.', 'empty_file', false);
            }

            $stored = Storage::disk($disk)->put($storagePath, $contents);
            if (! $stored) {
                throw new \RuntimeException('Unable to store the uploaded document.');
            }

            $checksum = hash('sha256', $contents);

            $document = OcrDocument::query()->create([
                'ca_id' => $caId,
                'uploaded_by' => $user->id,
                'original_filename' => $file->getClientOriginalName(),
                'stored_filename' => $storedFilename,
                'storage_disk' => $disk,
                'storage_path' => $storagePath,
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'file_size' => (int) $file->getSize(),
                'checksum' => $checksum,
                'status' => OcrDocument::STATUS_PENDING,
            ]);

            $this->logActivity('OCR Document Uploaded', $document, 'OCR document uploaded.');

            ProcessOcrDocumentJob::dispatch($document->id)->afterResponse();

            return $document->fresh(['uploader:id,name,email']);
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
        ]);

        $this->logActivity('OCR Text Corrected', $document, 'OCR corrected text saved.');

        return $document->fresh(['uploader:id,name,email']);
    }

    public function retry(OcrDocument $document, User $user): OcrDocument
    {
        $this->ensureCanAccessDocument($document);

        if (! $document->isFailed()) {
            abort(422, 'Only failed OCR documents can be retried.');
        }

        if ($document->isProcessing()) {
            abort(422, 'This OCR document is already processing.');
        }

        $document->update([
            'status' => OcrDocument::STATUS_PENDING,
            'error_code' => null,
            'error_message' => null,
            'processing_started_at' => null,
            'processed_at' => null,
        ]);

        $this->logActivity('OCR Retry Requested', $document, 'OCR retry requested.');

        ProcessOcrDocumentJob::dispatch($document->id)->afterResponse();

        return $document->fresh(['uploader:id,name,email']);
    }

    public function destroy(OcrDocument $document, User $user): void
    {
        $this->ensureCanAccessDocument($document);

        DB::transaction(function () use ($document) {
            $disk = $document->storage_disk;
            $path = $document->storage_path;

            $this->logActivity('OCR Document Deleted', $document, 'OCR document deleted.');

            $document->delete();

            if ($path && Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        });
    }

    public function downloadOriginal(OcrDocument $document): StreamedResponse
    {
        $this->ensureCanAccessDocument($document);

        if (! Storage::disk($document->storage_disk)->exists($document->storage_path)) {
            abort(404, 'Original document not found.');
        }

        $this->logActivity('OCR Original Viewed', $document, 'Original OCR document accessed.');

        return Storage::disk($document->storage_disk)->response(
            $document->storage_path,
            $document->original_filename,
            [
                'Content-Type' => $document->mime_type,
                'Content-Disposition' => 'inline; filename="'.addslashes($document->original_filename).'"',
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

        if ($document->status === OcrDocument::STATUS_PROCESSING) {
            return;
        }

        if ($document->status === OcrDocument::STATUS_COMPLETED) {
            return;
        }

        $document->update([
            'status' => OcrDocument::STATUS_PROCESSING,
            'processing_attempts' => $document->processing_attempts + 1,
            'processing_started_at' => now(),
            'error_code' => null,
            'error_message' => null,
        ]);

        $this->logActivity('OCR Processing Started', $document, 'OCR processing started.');

        try {
            if (! Storage::disk($document->storage_disk)->exists($document->storage_path)) {
                throw new DocumentAiProcessingException('Stored document file is missing.', 'storage_missing', false);
            }

            $binary = Storage::disk($document->storage_disk)->get($document->storage_path);
            $result = $this->documentAiService->processBinary($binary, $document->mime_type);

            $document->update([
                'status' => OcrDocument::STATUS_COMPLETED,
                'extracted_text' => $result['text'],
                'structured_data' => [
                    'pages' => $result['pages'],
                    'entities' => $result['entities'],
                ],
                'page_count' => $result['page_count'],
                'detected_languages' => $result['detected_languages'],
                'average_confidence' => $result['average_confidence'],
                'processor_name' => $result['processor_name'],
                'processed_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ]);

            $this->logActivity('OCR Completed', $document, 'OCR processing completed.');
        } catch (DocumentAiConfigurationException $exception) {
            $this->markFailed($document, 'configuration_error', $exception->getMessage(), false);
            throw $exception;
        } catch (DocumentAiProcessingException $exception) {
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
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function ensureCanAccessDocument(OcrDocument $document): void
    {
        if ($document->ca_id) {
            $this->employeeDataScope->ensureCanAccessCaMaster($document->ca_id);
        }
    }

    private function markFailed(OcrDocument $document, string $errorCode, string $message, bool $retryable): void
    {
        $document->update([
            'status' => OcrDocument::STATUS_FAILED,
            'error_code' => $errorCode,
            'error_message' => $message,
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
            moduleName: 'CA_MASTER',
            action: $action,
            recordId: (string) ($document->ca_id ?? $document->id),
            description: $description.' OCR #'.$document->id.' · Status: '.$document->status,
        );
    }
}
