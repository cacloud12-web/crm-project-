<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsentTrackingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ca_id' => $this->ca_id,
            'firm_name' => $this->caMaster?->firm_name,
            'consent_type' => $this->consent_type,
            'consent_status' => $this->consent_status,
            'consent_date' => $this->consent_date,
            'updated_at' => $this->updated_at,
        ];
    }
}
