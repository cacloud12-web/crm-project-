<?php

namespace App\Services\Rbac;

use App\Models\CaMaster;
use App\Models\LeadAssignmentEngine;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class EmployeeLeadFieldGuard
{
    /**
     * Lead attributes employees may attempt to update (subject to lock rules).
     *
     * @var list<string>
     */
    private const LEAD_FIELDS = [
        'firm_name',
        'ca_name',
        'mobile_no',
        'alternate_mobile_no',
        'email_id',
        'gst_no',
        'state_id',
        'city_id',
        'team_size',
        'existing_software',
        'website',
        'rating',
        'is_newly_established',
        'source_id',
        'status',
        'call_status',
        'demo_status',
        'lead_tags',
        'priority',
        'research_status',
    ];

    /**
     * Working fields employees may always update on assigned leads.
     *
     * @var list<string>
     */
    private const ALWAYS_EDITABLE_FOR_EMPLOYEE = [
        'alternate_mobile_no',
        'call_status',
        'demo_status',
        'rating',
        'is_newly_established',
        'status',
        'source_id',
    ];

    /**
     * @var list<string>
     */
    private const ALWAYS_LOCKED_FOR_EMPLOYEE = [
        'executive_id',
    ];

    public function isEmployee(User $user): bool
    {
        return $user->crm_role === 'employee';
    }

    /**
     * @return array{id: ?int, name: ?string}
     */
    public function resolveActiveExecutive(int $caId): array
    {
        $assignment = LeadAssignmentEngine::query()
            ->with('employee:employee_id,name')
            ->where('ca_id', $caId)
            ->where('status', 'Active')
            ->first();

        if ($assignment === null) {
            return ['id' => null, 'name' => null];
        }

        return [
            'id' => $assignment->employee_id !== null ? (int) $assignment->employee_id : null,
            'name' => $assignment->employee?->name,
        ];
    }

    public function resolveActiveExecutiveId(int $caId): ?int
    {
        return $this->resolveActiveExecutive($caId)['id'];
    }

    /**
     * @return list<string>
     */
    public function lockedFieldsForEmployee(User $user, CaMaster $lead, ?int $executiveId = null): array
    {
        if (! $this->isEmployee($user)) {
            return [];
        }

        $executiveId ??= $this->resolveActiveExecutiveId((int) $lead->ca_id);
        $locked = [];

        foreach (self::LEAD_FIELDS as $field) {
            if (in_array($field, self::ALWAYS_EDITABLE_FOR_EMPLOYEE, true)) {
                continue;
            }

            if (in_array($field, self::ALWAYS_LOCKED_FOR_EMPLOYEE, true)) {
                continue;
            }

            if ($this->isLeadFieldFilled($lead, $field)) {
                $locked[] = $field;
            }
        }

        foreach (self::ALWAYS_LOCKED_FOR_EMPLOYEE as $field) {
            $locked[] = $field;
        }

        return array_values(array_unique($locked));
    }

    public function isLeadFieldFilled(CaMaster $lead, string $field): bool
    {
        $value = $lead->{$field};

        return match ($field) {
            'lead_tags' => is_array($value) && $value !== [],
            'is_newly_established' => true,
            'rating', 'team_size', 'priority', 'status', 'ca_name', 'existing_software' => filled($value) || $value === 0 || $value === '0',
            default => filled($value),
        };
    }

    public function canEmployeeUpdateField(User $user, CaMaster $lead, string $field, ?int $executiveId = null): bool
    {
        if (! $this->isEmployee($user)) {
            return true;
        }

        if (in_array($field, self::ALWAYS_EDITABLE_FOR_EMPLOYEE, true)) {
            return true;
        }

        if (in_array($field, self::ALWAYS_LOCKED_FOR_EMPLOYEE, true)) {
            return false;
        }

        return ! $this->isLeadFieldFilled($lead, $field);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function filterUpdateData(User $user, CaMaster $lead, array $data, ?int $executiveId = null): array
    {
        if (! $this->isEmployee($user)) {
            return $data;
        }

        $executiveId ??= $this->resolveActiveExecutiveId((int) $lead->ca_id);
        $filtered = [];

        foreach ($data as $field => $value) {
            if ($field === 'executive_id') {
                if ($this->canEmployeeUpdateField($user, $lead, 'executive_id', $executiveId)) {
                    $filtered[$field] = $value;
                }

                continue;
            }

            if (! in_array($field, self::LEAD_FIELDS, true)) {
                continue;
            }

            if ($this->canEmployeeUpdateField($user, $lead, $field, $executiveId)) {
                $filtered[$field] = $value;
            }
        }

        return $filtered;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function filterContactUpdateData(User $user, CaMaster $lead, array $data): array
    {
        $contactFields = ['mobile_no', 'alternate_mobile_no', 'email_id', 'website'];

        return Arr::only(
            $this->filterUpdateData($user, $lead, Arr::only($data, $contactFields)),
            $contactFields,
        );
    }

    public function employeeCannotEditExistingMobile(User $user, CaMaster $lead): bool
    {
        return $this->isEmployee($user) && $this->isLeadFieldFilled($lead, 'mobile_no');
    }

    public function assertCanChangeStatus(User $user, CaMaster $lead, string $status): void
    {
        if (! $this->isEmployee($user)) {
            return;
        }

        $restricted = config('crm_leads.employee_sensitive_statuses', []);

        if (in_array($status, $restricted, true)) {
            throw ValidationException::withMessages([
                'status' => ['This status change requires manager approval. Submit an approval request instead.'],
            ]);
        }
    }

    public function assertCanApplyLeadAction(User $user, string $actionType): void
    {
        if (! $this->isEmployee($user)) {
            return;
        }

        $restricted = config('crm_leads.employee_sensitive_actions', []);

        if (in_array($actionType, $restricted, true)) {
            throw ValidationException::withMessages([
                'action_type' => ['This action requires manager approval. Submit an approval request instead.'],
            ]);
        }
    }
}
