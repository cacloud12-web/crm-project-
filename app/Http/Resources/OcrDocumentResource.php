<?php

namespace App\Http\Resources;

use App\Models\OcrDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OcrDocument */
class OcrDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $textPreview = $this->displayText();
        if (is_string($textPreview) && mb_strlen($textPreview) > 500) {
            $textPreview = mb_substr($textPreview, 0, 500).'…';
        }

        return [
            'id' => $this->id,
            'ca_id' => $this->ca_id,
            'status' => $this->status,
            'status_label' => ucfirst($this->status),
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'file_size_label' => $this->formatFileSize((int) $this->file_size),
            'page_count' => $this->page_count,
            'average_confidence' => $this->average_confidence,
            'detected_languages' => $this->detected_languages ?? [],
            'extracted_text' => $this->when($this->shouldIncludeFullText($request), $this->extracted_text),
            'corrected_text' => $this->when($this->shouldIncludeFullText($request), $this->corrected_text),
            'text_preview' => $textPreview,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'processing_attempts' => $this->processing_attempts,
            'processing_started_at' => $this->processing_started_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'uploaded_by' => $this->whenLoaded('uploader', fn () => [
                'id' => $this->uploader?->id,
                'name' => $this->uploader?->name,
            ]),
            'can' => [
                'retry' => $request->user()?->can('retry', $this->resource) ?? false,
                'download' => $request->user()?->can('download', $this->resource) ?? false,
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'delete' => $request->user()?->can('delete', $this->resource) ?? false,
            ],
        ];
    }

    private function shouldIncludeFullText(Request $request): bool
    {
        return $request->routeIs('ocr-documents.show')
            || $request->boolean('include_text');
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
