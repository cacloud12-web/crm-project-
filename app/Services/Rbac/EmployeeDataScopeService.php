<?php

namespace App\Services\Rbac;

use App\Models\CaMaster;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\LeadAssignmentEngine;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class EmployeeDataScopeService
{
    private const ACTIVITY_LOG_DENIED_ACTIONS = [
        'ca_master_access',
        'follow_up_access',
        'assignment_access',
    ];

    public function __construct(
        private readonly RbacService $rbacService,
        private readonly ActivityLogService $activityLogService,
    ) {}

    public function shouldScopeToEmployee(?User $user): bool
    {
        return $this->rbacService->roleKey($user) === 'employee';
    }

    public function resolveEmployeeId(?User $user): ?int
    {
        if (! $user) {
            return null;
        }

        $employeeId = Employee::query()
            ->where('user_id', $user->id)
            ->value('employee_id');

        if ($employeeId) {
            return (int) $employeeId;
        }

        $employeeId = Employee::query()
            ->where('email_id', $user->email)
            ->value('employee_id');

        return $employeeId ? (int) $employeeId : null;
    }

    /**
     * Employee-role login accounts must have an employees row for scoping + dashboard.
     * Auto-heal orphan users (login without linked employee record) and restore soft-deleted matches.
     */
    public function ensureEmployeeProfile(?User $user): ?int
    {
        if (! $user || ! $this->shouldScopeToEmployee($user)) {
            return $this->resolveEmployeeId($user);
        }

        $existing = $this->resolveEmployeeId($user);
        if ($existing) {
            return $existing;
        }

        $candidate = Employee::withTrashed()
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhere('email_id', $user->email);
            })
            ->orderByDesc('employee_id')
            ->first();

        if ($candidate) {
            if ($candidate->trashed()) {
                $candidate->restore();
            }

            $candidate->forceFill([
                'user_id' => $user->id,
                'email_id' => $user->email,
                'name' => $candidate->name ?: ($user->name ?: 'Employee'),
                'status' => $candidate->status ?: 'Active',
                'role' => $candidate->role ?: 'Sales Executive',
            ])->save();

            Log::info('Restored employee profile link for CRM user', [
                'user_id' => $user->id,
                'email' => $user->email,
                'employee_id' => $candidate->employee_id,
            ]);

            return (int) $candidate->employee_id;
        }

        $employee = Employee::query()->create([
            'user_id' => $user->id,
            'name' => $user->name ?: 'Employee',
            'email_id' => $user->email,
            'role' => 'Sales Executive',
            'status' => 'Active',
        ]);

        Log::info('Provisioned missing employee profile for CRM user', [
            'user_id' => $user->id,
            'email' => $user->email,
            'employee_id' => $employee->employee_id,
        ]);

        return (int) $employee->employee_id;
    }

    public function scopedEmployeeId(?User $user): ?int
    {
        if (! $user) {
            return null;
        }

        if (! $this->shouldScopeToEmployee($user)) {
            return null;
        }

        $employeeId = $this->ensureEmployeeProfile($user);

        if (! $employeeId) {
            $this->logDenied('employee_record_missing', $user, []);

            return 0;
        }

        return $employeeId;
    }

    public function applyToListing(Builder $query, array $config): void
    {
        $employeeId = $this->scopedEmployeeId(auth()->user());
        if ($employeeId === null) {
            return;
        }

        if ($employeeId <= 0) {
            $query->whereRaw('1 = 0');

            return;
        }

        $scope = $config['employee_scope'] ?? null;

        match ($scope) {
            'assigned_active_leads' => $query->whereHas(
                'leadAssignments',
                fn (Builder $assignment) => $assignment
                    ->where('employee_id', $employeeId)
                    ->where('status', 'Active'),
            ),
            'assigned_lead_ca' => $query->whereHas(
                'caMaster',
                fn (Builder $lead) => $lead->whereHas(
                    'leadAssignments',
                    fn (Builder $assignment) => $assignment
                        ->where('employee_id', $employeeId)
                        ->where('status', 'Active'),
                ),
            ),
            'employee_id' => $query->where(
                $query->getModel()->getTable().'.employee_id',
                $employeeId,
            ),
            'activity_performed_by' => $this->applyActivityLogScope($query, auth()->user(), $employeeId),
            default => null,
        };
    }

    public function stripScopedParams(array $params, array $config): array
    {
        $employeeId = $this->scopedEmployeeId(auth()->user());
        if ($employeeId === null) {
            return $params;
        }

        $scope = $config['employee_scope'] ?? null;

        if (in_array($scope, ['employee_id', 'assigned_active_leads'], true)) {
            if ($employeeId > 0 && isset($params['employee_id']) && (int) $params['employee_id'] !== $employeeId) {
                $this->logDenied('listing_filter_override', auth()->user(), [
                    'listing' => $config['table'] ?? 'unknown',
                    'requested_employee_id' => $params['employee_id'],
                ]);
            }

            unset($params['employee_id']);
        }

        return $params;
    }

    public function scopeCaMasterQuery(Builder $query, ?int $employeeId): Builder
    {
        if ($employeeId === null) {
            return $query;
        }

        if ($employeeId <= 0) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'leadAssignments',
            fn (Builder $assignment) => $assignment
                ->where('employee_id', $employeeId)
                ->where('status', 'Active'),
        );
    }

    public function audienceCaMasterQuery(): Builder
    {
        return $this->scopeCaMasterQuery(
            CaMaster::query(),
            $this->scopedEmployeeId(auth()->user()),
        );
    }

    public function ensureCanViewManagerMetrics(?User $user): void
    {
        if ($this->shouldScopeToEmployee($user)) {
            abort(403, 'You do not have permission to view manager follow-up metrics.');
        }
    }

    public function scopeFollowUpQuery(Builder $query, ?int $employeeId): Builder
    {
        if ($employeeId === null) {
            return $query;
        }

        if ($employeeId <= 0) {
            return $query->whereRaw('1 = 0');
        }

        $userId = auth()->id();

        return $query->where(function (Builder $outer) use ($employeeId, $userId) {
            $outer->where('employee_id', $employeeId);

            // Include follow-ups the employee created before employee_id was auto-assigned.
            if ($userId) {
                $outer->orWhere(function (Builder $inner) use ($userId) {
                    $inner->whereNull('employee_id')
                        ->where('created_by_user_id', $userId);
                });
            }
        });
    }

    public function scopeLeadAssignmentQuery(Builder $query, ?int $employeeId): Builder
    {
        if ($employeeId === null) {
            return $query;
        }

        if ($employeeId <= 0) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('employee_id', $employeeId);
    }

    public function ensureCanAccessCaMaster(int|string $caId): void
    {
        $employeeId = $this->scopedEmployeeId(auth()->user());
        if ($employeeId === null) {
            return;
        }

        if ($employeeId <= 0) {
            $this->logDenied('ca_master_access', auth()->user(), ['ca_id' => $caId]);
            abort(403, 'You do not have access to this lead.');
        }

        $allowed = LeadAssignmentEngine::query()
            ->where('ca_id', $caId)
            ->where('employee_id', $employeeId)
            ->where('status', 'Active')
            ->exists();

        if (! $allowed) {
            $this->logDenied('ca_master_access', auth()->user(), ['ca_id' => $caId]);
            abort(403, 'You do not have access to this lead.');
        }
    }

    public function ensureCanAccessFollowUp(int|string $followupId): void
    {
        $employeeId = $this->scopedEmployeeId(auth()->user());
        if ($employeeId === null) {
            return;
        }

        if ($employeeId <= 0) {
            $this->logDenied('follow_up_access', auth()->user(), ['followup_id' => $followupId]);
            abort(403, 'You do not have access to this follow-up.');
        }

        $userId = auth()->id();
        $allowed = FollowUp::query()
            ->where('followup_id', $followupId)
            ->where(function (Builder $outer) use ($employeeId, $userId) {
                $outer->where('employee_id', $employeeId);
                if ($userId) {
                    $outer->orWhere(function (Builder $inner) use ($userId) {
                        $inner->whereNull('employee_id')
                            ->where('created_by_user_id', $userId);
                    });
                }
            })
            ->exists();

        if (! $allowed) {
            $this->logDenied('follow_up_access', auth()->user(), ['followup_id' => $followupId]);
            abort(403, 'You do not have access to this follow-up.');
        }
    }

    public function ensureCanAccessAssignment(int|string $assignmentId): void
    {
        $employeeId = $this->scopedEmployeeId(auth()->user());
        if ($employeeId === null) {
            return;
        }

        if ($employeeId <= 0) {
            $this->logDenied('assignment_access', auth()->user(), ['assignment_id' => $assignmentId]);
            abort(403, 'You do not have access to this assignment.');
        }

        $allowed = LeadAssignmentEngine::query()
            ->where('assignment_id', $assignmentId)
            ->where('employee_id', $employeeId)
            ->exists();

        if (! $allowed) {
            $this->logDenied('assignment_access', auth()->user(), ['assignment_id' => $assignmentId]);
            abort(403, 'You do not have access to this assignment.');
        }
    }

    public function cacheScopeKey(): string
    {
        $employeeId = $this->scopedEmployeeId(auth()->user());

        if ($employeeId === null) {
            return 'org';
        }

        if ($employeeId <= 0) {
            return 'employee:none';
        }

        return 'employee:'.$employeeId;
    }

    public function logDenied(string $action, ?User $user, array $context = []): void
    {
        Log::warning('CRM data scope denied', array_merge([
            'action' => $action,
            'user_id' => $user?->id,
            'email' => $user?->email,
            'role' => $this->rbacService->roleKey($user),
        ], $context));

        if (! in_array($action, self::ACTIVITY_LOG_DENIED_ACTIONS, true)) {
            return;
        }

        $recordId = isset($context['ca_id'])
            ? (string) $context['ca_id']
            : (isset($context['followup_id'])
                ? (string) $context['followup_id']
                : (isset($context['assignment_id']) ? (string) $context['assignment_id'] : null));

        $this->activityLogService->log(
            'SECURITY',
            'Access Denied',
            $recordId,
            'Access denied: '.$action,
            $this->performerName($user),
        );
    }

    private function performerName(?User $user): string
    {
        if ($user?->name) {
            return $user->name;
        }

        if ($user?->email) {
            return $user->email;
        }

        return 'System';
    }

    private function applyActivityLogScope(Builder $query, ?User $user, int $employeeId): void
    {
        $performedBy = array_filter([
            $user?->name,
            $user?->email,
            Employee::query()->where('employee_id', $employeeId)->value('name'),
        ]);

        if ($performedBy === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $outer) use ($performedBy) {
            foreach ($performedBy as $value) {
                $outer->orWhere('performed_by', 'ilike', '%'.$value.'%');
            }
        });
    }
}
