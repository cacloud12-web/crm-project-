<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'support_ticket_id' => $this->support_ticket_id,
            'user_id' => $this->user_id,
            'author_name' => $this->author_name,
            'author_type' => $this->author_type,
            'comment_type' => $this->comment_type,
            'body' => $this->body,
            'visibility' => $this->visibility,
            'is_internal' => $this->is_internal,
            'source_system' => $this->source_system,
            'external_comment_id' => $this->external_comment_id,
            'metadata' => $this->metadata,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'email' => $this->user?->email,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
