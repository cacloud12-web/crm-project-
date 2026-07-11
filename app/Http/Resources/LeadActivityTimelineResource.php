<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadActivityTimelineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = is_array($this->resource) ? $this->resource : (array) $this->resource;

        return [
            'activity_id' => $row['activity_id'] ?? null,
            'source_table' => $row['source_table'] ?? null,
            'source_id' => $row['source_id'] ?? null,
            'history_id' => $row['history_id'] ?? null,
            'followup_id' => $row['followup_id'] ?? null,
            'ca_id' => $row['ca_id'] ?? null,
            'employee_id' => $row['employee_id'] ?? null,
            'activity_type' => $row['activity_type'] ?? null,
            'activity_label' => $row['activity_label'] ?? null,
            'icon' => $row['icon'] ?? 'activity',
            'firm_name' => $row['firm_name'] ?? null,
            'ca_name' => $row['ca_name'] ?? null,
            'call_status' => $row['call_status'] ?? null,
            'call_notes' => $row['call_notes'] ?? null,
            'status' => $row['status'] ?? null,
            'notes' => $row['notes'] ?? null,
            'employee_name' => $row['employee_name'] ?? null,
            'performed_by' => $row['performed_by'] ?? null,
            'created_by' => $row['created_by'] ?? null,
            'next_action' => $row['next_action'] ?? null,
            'followup_date' => $row['followup_date'] ?? null,
            'next_followup_date' => $row['next_followup_date'] ?? null,
            'metadata' => $row['metadata'] ?? [],
            'occurred_at' => $row['occurred_at'] ?? null,
            'date_label' => $row['date_label'] ?? null,
            'time_label' => $row['time_label'] ?? null,
        ];
    }
}
