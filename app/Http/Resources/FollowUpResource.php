<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FollowUpResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'followup_id' => $this->followup_id,
            'ca_id' => $this->ca_id,
            'employee_id' => $this->employee_id,
            'followup_type' => $this->followup_type,
            'outcome' => $this->outcome,
            'priority' => $this->priority,
            'team_size' => $this->resolveTeamSize(),
            'demo_provider_name' => $this->demo_provider_name,
            'demo_provider_employee_id' => $this->demo_provider_employee_id,
            'meeting_link' => $this->meeting_link,
            'source' => $this->source,
            'is_auto_generated' => $this->is_auto_generated,
            'is_rescheduled' => $this->is_rescheduled,
            'sequence_step' => $this->sequence_step,
            'rescheduled_at' => $this->rescheduled_at,
            'reschedule_reason' => $this->reschedule_reason,
            'firm_name' => $this->caMaster?->firm_name,
            'mobile_no' => $this->caMaster?->mobile_no,
            'city' => $this->resolveLeadCityName(),
            'city_name' => $this->resolveLeadCityName(),
            'executive' => $this->employee?->name,
            'employee_name' => $this->employee?->name,
            'remarks' => $this->remarks,
            'scheduled_date' => $this->scheduled_date,
            'next_followup_date' => $this->next_followup_date,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function resolveLeadCityName(): ?string
    {
        $fromMaster = trim((string) ($this->caMaster?->city?->city_name ?? ''));
        if ($fromMaster !== '') {
            return $fromMaster;
        }

        $ocrText = trim((string) ($this->caMaster?->ocr_city_text ?? ''));

        return $ocrText !== '' ? $ocrText : null;
    }

    private function resolveTeamSize(): ?int
    {
        if ($this->team_size !== null && (int) $this->team_size > 0) {
            return (int) $this->team_size;
        }

        $fromLead = $this->caMaster?->team_size;

        return $fromLead !== null && (int) $fromLead > 0 ? (int) $fromLead : null;
    }
}
