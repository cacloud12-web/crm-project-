<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignmentHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ca_id' => $this->ca_id,
            'firm_name' => $this->caMaster?->firm_name,
            'previous_employee_id' => $this->previous_employee_id,
            'previous_employee' => $this->previousEmployee?->name,
            'new_employee_id' => $this->new_employee_id,
            'new_employee' => $this->newEmployee?->name,
            'assignment_type' => $this->assignment_type,
            'reason' => $this->reason,
            'assigned_by' => $this->assigned_by,
            'assigned_by_name' => $this->assignedByEmployee?->name,
            'assigned_at' => $this->assigned_at,
        ];
    }
}
