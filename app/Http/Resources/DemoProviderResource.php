<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DemoProviderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'default_meeting_link' => $this->default_meeting_link,
            'min_team_size' => $this->min_team_size,
            'max_team_size' => $this->max_team_size,
            'slot_duration_minutes' => $this->slot_duration_minutes,
            'buffer_minutes' => $this->buffer_minutes,
            'max_demos_per_day' => $this->max_demos_per_day,
            'work_start_time' => $this->work_start_time,
            'work_end_time' => $this->work_end_time,
            'break_start_time' => $this->break_start_time,
            'break_end_time' => $this->break_end_time,
            'working_days' => $this->working_days,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'leaves' => $this->whenLoaded('leaves', fn () => $this->leaves->map(fn ($leave) => [
                'id' => $leave->id,
                'leave_date' => $leave->leave_date?->toDateString(),
                'reason' => $leave->reason,
            ])),
        ];
    }
}
