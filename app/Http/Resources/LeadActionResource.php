<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'action_id' => $this->action_id,
            'ca_id' => $this->ca_id,
            'employee_id' => $this->employee_id,
            'action_type' => $this->action_type,
            'action_at' => $this->action_at,
            'remarks' => $this->remarks,
            'firm_name' => $this->caMaster?->firm_name,
            'status' => $this->caMaster?->status,
        ];
    }
}
