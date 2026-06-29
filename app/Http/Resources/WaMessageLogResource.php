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
            'firm_name' => $this->caMaster?->firm_name,
            'mobile_no' => $this->mobile_no,
            'message' => $this->message,
            'message_status' => $this->message_status,
            'queued_at' => $this->queued_at,
            'sent_at' => $this->sent_at,
            'delivered_at' => $this->delivered_at,
            'failed_reason' => $this->failed_reason,
            'created_at' => $this->created_at,
        ];
    }
}
