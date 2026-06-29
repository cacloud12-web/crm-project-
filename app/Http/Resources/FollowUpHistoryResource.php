<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FollowUpHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'history_id' => $this->history_id,
            'followup_id' => $this->followup_id,
            'ca_id' => $this->ca_id,
            'employee_id' => $this->employee_id,
            'event_type' => $this->event_type,
            'outcome' => $this->outcome,
            'remarks' => $this->remarks,
            'metadata' => $this->metadata,
            'performed_by' => $this->performed_by,
            'created_at' => $this->created_at,
            'date_label' => $this->created_at?->format('d M'),
            'time_label' => $this->created_at?->format('H:i'),
        ];
    }
}
