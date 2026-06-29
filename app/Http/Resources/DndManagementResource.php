<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DndManagementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ca_id' => $this->ca_id,
            'firm_name' => $this->caMaster?->firm_name,
            'mobile_no' => $this->mobile_no,
            'email_id' => $this->email_id,
            'dnd_type' => $this->dnd_type,
            'reason' => $this->reason,
            'added_by' => $this->added_by,
            'added_at' => $this->added_at,
            'created_at' => $this->created_at,
        ];
    }
}
