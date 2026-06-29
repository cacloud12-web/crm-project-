<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'task_id' => $this->task_id,
            'followup_id' => $this->followup_id,
            'ca_id' => $this->ca_id,
            'employee_id' => $this->employee_id,
            'firm_name' => $this->caMaster?->firm_name,
            'task_type' => $this->task_type,
            'due_date' => $this->due_date?->toDateString(),
            'due_time' => $this->due_time,
            'priority' => $this->priority,
            'status' => $this->status,
            'task_source' => $this->task_source,
            'remarks' => $this->remarks,
            'followup_type' => $this->followUp?->followup_type,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
        ];
    }
}
