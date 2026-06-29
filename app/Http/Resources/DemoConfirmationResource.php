<?php

namespace App\Http\Resources;

use App\Services\DemoConfirmation\DemoConfirmationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DemoConfirmationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $service = app(DemoConfirmationService::class);

        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,
            'followup_id' => $this->followup_id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->employee?->name,
            'demo_date' => $this->demo_date?->toDateString(),
            'demo_time' => $this->demo_time,
            'demo_slot' => $service->formatDemoSlotFromConfirmation($this->resource),
            'confirmation_status' => $this->confirmation_status,
            'status_label' => $service->statusLabel((string) $this->confirmation_status),
            'sms_log_id' => $this->sms_log_id,
            'customer_reply' => $this->customer_reply,
            'confirmation_source' => $this->confirmation_source,
            'confirmed_at' => $this->confirmed_at,
            'last_sms_sent_at' => $this->last_sms_sent_at,
            'is_reschedule' => (bool) $this->is_reschedule,
            'previous_confirmation_id' => $this->previous_confirmation_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
