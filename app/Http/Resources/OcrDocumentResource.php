<?php

namespace App\Http\Resources;

use App\Models\MasterImportBatch;
use App\Models\OcrDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Schema;

/** @mixin OcrDocument */
class OcrDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $textPreview = $this->displayText();
        if (is_string($textPreview) && mb_strlen($textPreview) > 500) {
            $textPreview = mb_substr($textPreview, 0, 500).'…';
        }

        $durationSeconds = null;
        if ($this->processing_started_at && $this->processed_at) {
            $durationSeconds = max(0, $this->processing_started_at->diffInSeconds($this->processed_at));
        }

        return [
            'id' => $this->id,
            'ca_id' => $this->ca_id,
            'firm_name' => $this->whenLoaded('caMaster', fn () => $this->caMaster?->firm_name),
            'ca_name' => $this->whenLoaded('caMaster', fn () => $this->caMaster?->ca_name),
            'provider' => 'google_document_ai',
            'provider_label' => 'Google Document AI',
            'import_type' => $this->import_type ?: OcrDocument::IMPORT_SALES_TEAM,
            'import_type_label' => $this->import_type === OcrDocument::IMPORT_MASTER_CA
                ? 'Master CA Data'
                : 'Sales Team Data',
            'processing_mode' => $this->processing_mode,
            'processing_mode_label' => $this->processing_mode === 'batch' ? 'Batch OCR' : ($this->processing_mode === 'online' ? 'Online OCR' : null),
            'status' => $this->status,
            'status_label' => $this->resource->statusLabel(),
            'pipeline_stage' => $this->resource->pipelineStage(),
            'pipeline_stage_label' => $this->resource->statusLabel(),
            'processing_progress' => $this->processing_progress,
            'parse_status' => $this->parse_status,
            'parsed_firm_count' => $this->parsed_firm_count,
            'parsed_at' => $this->parsed_at?->toIso8601String(),
            'parse_error' => $this->parseErrorPayload(),
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'file_size_label' => $this->formatFileSize((int) $this->file_size),
            'page_count' => $this->page_count,
            'total_pages' => $this->total_pages,
            'processed_pages' => $this->processed_pages,
            'average_confidence' => $this->average_confidence,
            'detected_languages' => $this->detected_languages ?? [],
            'extracted_text' => $this->when($this->shouldIncludeFullText($request), $this->extracted_text),
            'corrected_text' => $this->when($this->shouldIncludeFullText($request), $this->corrected_text),
            'text_preview' => $textPreview,
            'parsed_firms' => $this->when(
                $this->shouldIncludeParsedFirms($request) && $this->relationLoaded('parsedFirms'),
                function () {
                    return $this->parsedFirms
                        ->map(fn ($firm) => (new OcrParsedFirmResource($firm))->resolve())
                        ->values()
                        ->all();
                },
            ),
            'has_structured_data' => (int) ($this->parsed_firm_count ?? 0) > 0
                || $this->parse_status === 'completed',
            'structured_data' => $this->when(
                $this->shouldIncludeParsedFirms($request),
                function () {
                    $data = is_array($this->structured_data) ? $this->structured_data : [];

                    $parsed = $data['parsed'] ?? null;

                    return [
                        'parsed' => $parsed,
                        'mapping' => $data['mapping'] ?? null,
                        'import_batch' => $this->latestImportBatchSummary(),
                        'quality_report' => is_array($parsed) ? ($parsed['quality_report'] ?? null) : null,
                    ];
                },
            ),
            'import_batch' => $this->when(
                $this->shouldIncludeParsedFirms($request),
                fn () => $this->latestImportBatchSummary(),
            ),
            'error_code' => $this->error_code,
            'error_message' => $this->safeErrorMessage($request),
            'processing_attempts' => $this->processing_attempts,
            'processing_started_at' => $this->processing_started_at?->toIso8601String(),
            'batch_started_at' => $this->batch_started_at?->toIso8601String(),
            'batch_completed_at' => $this->batch_completed_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'processing_duration_seconds' => $durationSeconds,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'batch_notice' => $this->processing_mode === 'batch' && in_array($this->status, OcrDocument::ACTIVE_STATUSES, true)
                ? 'This document is being processed in the background because it contains many pages. You may continue using the CRM.'
                : null,
            'uploaded_by' => $this->whenLoaded('uploader', fn () => [
                'id' => $this->uploader?->id,
                'name' => $this->uploader?->name,
            ]),
            'can' => [
                'retry' => $request->user()?->can('retry', $this->resource) ?? false,
                'download' => $request->user()?->can('download', $this->resource) ?? false,
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'delete' => ($request->user()?->can('delete', $this->resource) ?? false)
                    && ! in_array($this->status, OcrDocument::ACTIVE_STATUSES, true),
                'view' => $request->user()?->can('view', $this->resource) ?? false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestImportBatchSummary(): ?array
    {
        if (! Schema::hasTable('master_import_batches')) {
            return null;
        }

        $batch = MasterImportBatch::query()
            ->where('source_ref', (string) $this->id)
            ->whereIn('source_type', [
                'ocr',
                OcrDocument::IMPORT_SALES_TEAM,
                OcrDocument::IMPORT_MASTER_CA,
            ])
            ->latest('id')
            ->first();

        if (! $batch) {
            return null;
        }

        return [
            'id' => $batch->id,
            'status' => $batch->status,
            'progress_stage' => $batch->progress_stage,
            'progress_pct' => $batch->progress_pct,
            'total_records' => $batch->total_records,
            'created_count' => $batch->created_count,
            'updated_count' => $batch->updated_count,
            'duplicate_count' => $batch->duplicate_count,
            'review_count' => $batch->review_count,
            'conflict_count' => $batch->conflict_count,
            'failed_count' => $batch->failed_count,
            'rollbackable' => $batch->isRollbackable(),
        ];
    }

    private function shouldIncludeFullText(Request $request): bool
    {
        return $request->routeIs('ocr-documents.show')
            || $request->boolean('include_text');
    }

    private function shouldIncludeParsedFirms(Request $request): bool
    {
        return $request->routeIs('ocr-documents.show')
            || $request->routeIs('ocr-documents.reparse')
            || $request->boolean('include_parsed')
            || $request->boolean('include_text');
    }

    private function safeErrorMessage(Request $request): ?string
    {
        $message = $this->error_message;
        if (! is_string($message) || $message === '') {
            return $message;
        }

        $looksTechnical = str_contains($message, 'GOOGLE_')
            || str_contains($message, 'gs://')
            || str_contains($message, 'GOOGLE_CLOUD_STORAGE');

        if (! $looksTechnical && ! str_contains(strtolower($message), 'cloud storage input and output')) {
            return $message;
        }

        $user = $request->user();
        if ($user) {
            $role = app(\App\Services\Rbac\RbacService::class)->roleKey($user);
            if (in_array($role, ['super_admin', 'admin'], true)) {
                return $message;
            }
        }

        return 'Large-document processing is not configured. Please contact the administrator.';
    }

    /**
     * @return array{code: string, message: string}|null
     */
    private function parseErrorPayload(): ?array
    {
        $data = is_array($this->structured_data) ? $this->structured_data : [];
        $error = $data['parsed']['error'] ?? null;
        if (! is_array($error)) {
            return null;
        }

        $code = trim((string) ($error['code'] ?? ''));
        $message = trim((string) ($error['message'] ?? ''));
        if ($code === '' && $message === '') {
            return null;
        }

        return [
            'code' => $code !== '' ? $code : 'parser_exception',
            'message' => $message !== '' ? $message : 'Structured parsing failed.',
        ];
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }
}
