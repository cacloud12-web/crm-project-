<?php

namespace App\Http\Resources;

use App\Services\Email\EmailCampaignService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailCampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $statistics = app(EmailCampaignService::class)->campaignStatistics($this->resource);

        return [
            'id' => $this->id,
            'campaign_name' => $this->campaign_name,
            'campaign_type' => $this->campaign_type,
            'audience_mode' => $this->audience_mode,
            'audience_label' => $this->audience_label,
            'audience_filters' => $this->audience_filters,
            'selected_ca_ids' => $this->selected_ca_ids,
            'subject' => $this->subject,
            'body_template' => $this->body_template,
            'scheduled_at' => $this->scheduled_at,
            'status' => $this->status,
            'performed_by' => $this->performed_by,
            'total_emails' => $this->total_emails,
            'valid_emails_count' => $this->valid_emails_count,
            'invalid_emails_count' => $this->invalid_emails_count,
            'duplicate_emails_count' => $this->duplicate_emails_count,
            'invalid_domain_count' => $this->invalid_domain_count,
            'sent_count' => $this->sent_count,
            'delivered_count' => $this->delivered_count,
            'failed_count' => $this->failed_count,
            'queued_count' => $this->queued_count,
            'skipped_count' => $this->skipped_count,
            'statistics' => $statistics,
            'delivery_started_at' => $this->delivery_started_at,
            'delivery_completed_at' => $this->delivery_completed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
