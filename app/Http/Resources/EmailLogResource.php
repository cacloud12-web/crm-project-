<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'campaign_id' => $this->campaign_id,
            'ca_id' => $this->ca_id,
            'lead_id' => $this->ca_id,
            'employee_id' => $this->employee_id,
            'firm_name' => $this->caMaster?->firm_name,
            'recipient_email' => $this->recipient_email,
            'subject' => $this->subject,
            'body' => $this->body,
            'message' => $this->body,
            'email_status' => $this->email_status,
            'status' => $this->email_status,
            'queued_at' => $this->queued_at,
            'sent_at' => $this->sent_at,
            'delivered_at' => $this->delivered_at,
            'opened_at' => $this->opened_at,
            'clicked_at' => $this->clicked_at,
            'bounced_at' => $this->bounced_at,
            'failed_reason' => $this->failed_reason,
            'smtp_error' => $this->smtp_error,
            'provider_response' => $this->provider_response,
            'error_message' => $this->error_message ?? $this->failed_reason,
            'reply_received_at' => $this->reply_received_at,
            'reply_from' => $this->reply_from,
            'reply_preview' => $this->reply_preview,
            'created_at' => $this->created_at,
        ];
    }
}
