<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'assignment_id' => $this->assignment_id,
            'ca_id' => $this->ca_id,
            'employee_id' => $this->employee_id,
            'firm_name' => $this->caMaster?->firm_name,
            'executive' => $this->employee?->name,
            'employee_name' => $this->employee?->name,
            'assigned_date' => $this->assigned_date,
            'assignment_type' => $this->assignment_type,
            'rotation_logic_used' => $this->rotation_logic_used,
            'reason' => $this->rotation_logic_used,
            'priority_score' => $this->priority_score,
            'target_leads' => $this->target_leads,
            'achieved_leads' => $this->achieved_leads,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
