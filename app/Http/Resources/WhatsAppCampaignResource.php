<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhatsAppCampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'campaign_name' => $this->campaign_name,
            'campaign_type' => $this->campaign_type,
            'audience_mode' => $this->audience_mode,
            'audience_label' => $this->audience_label,
            'audience_filters' => $this->audience_filters,
            'selected_ca_ids' => $this->selected_ca_ids,
            'template_name' => $this->template_name,
            'language_code' => $this->language_code,
            'message_template_id' => $this->message_template_id,
            'api_version' => $this->api_version,
            'payload_generated_at' => $this->payload_generated_at,
            'message_template' => $this->message_template,
            'scheduled_at' => $this->scheduled_at,
            'status' => $this->status,
            'performed_by' => $this->performed_by,
            'total_messages' => $this->total_messages,
            'sent_count' => $this->sent_messages_count ?? null,
            'delivered_count' => $this->delivered_messages_count ?? $this->delivered_count,
            'read_count' => $this->read_messages_count ?? null,
            'failed_count' => $this->failed_messages_count ?? $this->failed_count,
            'queued_count' => $this->queued_count,
            'pending_count' => $this->pending_messages_count ?? null,
            'skipped_count' => $this->skipped_messages_count ?? $this->skipped_count,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
