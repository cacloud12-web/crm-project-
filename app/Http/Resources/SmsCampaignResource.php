<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SmsCampaignResource extends JsonResource
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
            'sender_id' => $this->sender_id,
            'sms_template_id' => $this->sms_template_id,
            'message_template' => $this->message_template,
            'scheduled_at' => $this->scheduled_at,
            'status' => $this->status,
            'performed_by' => $this->performed_by,
            'total_sms' => $this->total_sms,
            'delivered_count' => $this->delivered_count,
            'failed_count' => $this->failed_count,
            'queued_count' => $this->queued_count,
            'skipped_count' => $this->skipped_count,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
