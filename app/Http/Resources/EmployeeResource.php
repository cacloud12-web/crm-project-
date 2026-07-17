<?php

namespace App\Http\Resources;

use App\Services\Employee\EmployeeCredentialService;
use App\Services\Presence\EmployeePresenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $credentialService = app(EmployeeCredentialService::class);
        $presence = app(EmployeePresenceService::class)
            ->payloadForEmployee($this->resource);

        return [
            'employee_id' => $this->employee_id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'email_id' => $this->email_id,
            'mobile_no' => $this->mobile_no,
            'city_id' => $this->city_id,
            'city' => $this->city?->city_name,
            'city_name' => $this->city?->city_name,
            'role' => $this->role,
            'crm_role' => $this->user?->crm_role,
            'crm_role_label' => $this->user?->crm_role
                ? config('rbac.roles.'.$this->user->crm_role, ucfirst($this->user->crm_role))
                : null,
            'login_status' => $credentialService->loginStatus($this->resource),
            'login_status_label' => $credentialService->loginStatusLabel($this->resource),
            'date_of_joining' => $this->date_of_joining,
            'status' => $this->status,
            'work_type' => $this->work_type ?? 'calling',
            'demo_meeting_link' => $this->demo_meeting_link,
            'demo_min_team_size' => $this->demo_min_team_size,
            'demo_max_team_size' => $this->demo_max_team_size,
            'active_for_demo' => (bool) ($this->active_for_demo ?? false),
            'is_online' => (bool) ($presence['is_online'] ?? false),
            'last_seen_at' => $presence['last_seen_at'] ?? null,
            'last_seen_human' => $presence['last_seen_human'] ?? 'Offline',
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
