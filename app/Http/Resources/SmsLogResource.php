<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SmsLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'campaign_id' => $this->campaign_id,
            'campaign_name' => $this->campaign?->campaign_name,
            'ca_id' => $this->ca_id,
            'lead_id' => $this->ca_id,
            'employee_id' => $this->employee_id,
            'firm_name' => $this->caMaster?->firm_name,
            'mobile_no' => $this->mobile_no,
            'sender_id' => $this->sender_id,
            'message' => $this->message,
            'sms_status' => $this->sms_status,
            'status' => $this->sms_status,
            'queued_at' => $this->queued_at,
            'sent_at' => $this->sent_at,
            'delivered_at' => $this->delivered_at,
            'failed_reason' => $this->failed_reason,
            'provider_response' => $this->provider_response,
            'error_message' => $this->error_message ?? $this->failed_reason,
            'created_at' => $this->created_at,
        ];
    }
}
