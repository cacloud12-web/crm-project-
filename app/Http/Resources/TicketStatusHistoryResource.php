<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketStatusHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'support_ticket_id' => $this->support_ticket_id,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'from_priority' => $this->from_priority,
            'to_priority' => $this->to_priority,
            'from_assigned_to_employee_id' => $this->from_assigned_to_employee_id,
            'to_assigned_to_employee_id' => $this->to_assigned_to_employee_id,
            'changed_by_user_id' => $this->changed_by_user_id,
            'change_source' => $this->change_source,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'changed_by_name' => $this->changedByUser?->name,
            'from_assignee_name' => $this->fromAssignee?->name,
            'to_assignee_name' => $this->toAssignee?->name,
            'created_at' => $this->created_at,
        ];
    }
}
