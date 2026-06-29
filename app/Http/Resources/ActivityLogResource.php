<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'module_name' => $this->module_name,
            'module' => $this->module_name,
            'action' => $this->action,
            'record_id' => $this->record_id,
            'performed_by' => $this->performed_by,
            'timestamp' => $this->created_at,
            'description' => $this->description,
            'before_value' => $this->before_value,
            'after_value' => $this->after_value,
            'ip_address' => $this->ip_address,
        ];
    }
}
