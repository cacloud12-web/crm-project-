<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DemoCalendarEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = is_array($this->resource) ? $this->resource : (array) $this->resource;

        return [
            'id' => $row['id'] ?? null,
            'ca_id' => $row['ca_id'] ?? null,
            'followup_id' => $row['followup_id'] ?? null,
            'demo_provider_id' => $row['demo_provider_id'] ?? null,
            'demo_provider_name' => $row['demo_provider_name'] ?? null,
            'employee_id' => $row['employee_id'] ?? null,
            'employee_name' => $row['employee_name'] ?? null,
            'firm_name' => $row['firm_name'] ?? null,
            'ca_name' => $row['ca_name'] ?? null,
            'team_size' => $row['team_size'] ?? null,
            'status' => $row['status'] ?? null,
            'status_label' => $row['status_label'] ?? null,
            'meeting_link' => $row['meeting_link'] ?? null,
            'notes' => $row['notes'] ?? null,
            'demo_at' => $row['demo_at'] ?? null,
            'demo_end_at' => $row['demo_end_at'] ?? null,
            'time_label' => $row['time_label'] ?? null,
            'date_label' => $row['date_label'] ?? null,
        ];
    }
}
