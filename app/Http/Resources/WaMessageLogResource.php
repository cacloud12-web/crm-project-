<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WaMessageLogResource extends JsonResource
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
            'mobile_no' => $this->mobile_no,
            'template_name' => $this->template_name,
            'language_code' => $this->language_code,
            'meta_message_id' => $this->meta_message_id,
            'message' => $this->message,
            'status' => $this->message_status,
            'message_status' => $this->message_status,
            'api_payload' => $this->when(
                in_array(auth()->user()?->crm_role, ['admin', 'super_admin', 'manager'], true),
                $this->api_payload,
            ),
            'provider_response' => $this->provider_response,
            'error_message' => $this->error_message ?? $this->failed_reason,
            'queued_at' => $this->queued_at,
            'sent_at' => $this->sent_at,
            'delivered_at' => $this->delivered_at,
            'read_at' => $this->read_at,
            'failed_reason' => $this->failed_reason,
            'created_at' => $this->created_at,
        ];
    }
}
