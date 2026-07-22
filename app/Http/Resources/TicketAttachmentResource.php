<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'support_ticket_id' => $this->support_ticket_id,
            'ticket_comment_id' => $this->ticket_comment_id,
            'uploaded_by' => $this->uploaded_by,
            'original_filename' => $this->original_filename,
            'stored_filename' => $this->stored_filename,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'checksum' => $this->checksum,
            'external_attachment_id' => $this->external_attachment_id,
            'metadata' => $this->metadata,
            'uploader' => $this->whenLoaded('uploader', fn () => [
                'id' => $this->uploader?->id,
                'name' => $this->uploader?->name,
                'email' => $this->uploader?->email,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
