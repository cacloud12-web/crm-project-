<?php

namespace App\Services\Ticket;

use App\Models\Employee;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Services\Rbac\RbacService;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TicketVisibilityService
{
    public function __construct(
        private readonly RbacService $rbacService,
        private readonly EmployeeDataScopeService $employeeDataScope,
    ) {}

    public function applyVisibilityScope(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $role = $this->rbacService->roleKey($user);
        if (in_array($role, ['super_admin', 'admin'], true)) {
            return $query;
        }

        if ($role === 'manager') {
            return $this->applyManagerScope($query, $user);
        }

        return $this->applyEmployeeScope($query, $user);
    }

    public function canView(SupportTicket $ticket, ?User $user = null): bool
    {
        $user ??= auth()->user();
        if (! $user) {
            return false;
        }

        $role = $this->rbacService->roleKey($user);
        if (in_array($role, ['super_admin', 'admin'], true)) {
            return true;
        }

        if ($role === 'manager') {
            return $this->managerCanView($ticket, $user);
        }

        return $this->employeeCanView($ticket, $user);
    }

    public function ensureCanView(SupportTicket $ticket, ?User $user = null): void
    {
        if (! SupportTicket::query()->whereKey($ticket->getKey())->exists()) {
            throw new NotFoundHttpException('Ticket not found.');
        }

        if (! $this->canView($ticket, $user)) {
            throw new AccessDeniedHttpException('You do not have access to this ticket.');
        }
    }

    public function canAssignToEmployee(int $employeeId, ?User $user = null): bool
    {
        $user ??= auth()->user();
        if (! $user) {
            return false;
        }

        $role = $this->rbacService->roleKey($user);
        if (in_array($role, ['super_admin', 'admin'], true)) {
            return Employee::query()
                ->where('employee_id', $employeeId)
                ->whereNull('deleted_at')
                ->exists();
        }

        if ($role === 'manager') {
            return $this->visibleEmployeesQuery($user)
                ->where('employee_id', $employeeId)
                ->exists();
        }

        $scopedEmployeeId = $this->employeeDataScope->scopedEmployeeId($user);

        return $scopedEmployeeId !== null && (int) $scopedEmployeeId === $employeeId;
    }

    public function visibleEmployeesQuery(User $user): Builder
    {
        $query = Employee::query()
            ->where('status', 'Active')
            ->whereNull('deleted_at');

        if ($this->rbacService->roleKey($user) === 'manager') {
            $query->where(function ($q) {
                $q->whereNull('role')
                    ->orWhere('role', 'ilike', '%executive%')
                    ->orWhere('role', 'ilike', '%employee%')
                    ->orWhere('role', 'ilike', '%sales%');
            });
        }

        return $query;
    }

    private function applyEmployeeScope(Builder $query, User $user): Builder
    {
        $employeeId = $this->employeeDataScope->scopedEmployeeId($user);

        return $query->where(function (Builder $scoped) use ($employeeId, $user) {
            $scoped->where('raised_by_user_id', $user->id);

            if ($employeeId !== null && $employeeId > 0) {
                $scoped->orWhere('assigned_to_employee_id', $employeeId);
            }
        });
    }

    private function applyManagerScope(Builder $query, User $user): Builder
    {
        $teamIds = $this->visibleEmployeesQuery($user)->pluck('employee_id')->all();
        $ownEmployeeId = $this->employeeDataScope->resolveEmployeeId($user);
        $teamUserIds = $teamIds === []
            ? []
            : Employee::query()
                ->whereIn('employee_id', $teamIds)
                ->whereNotNull('user_id')
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

        return $query->where(function (Builder $scoped) use ($teamIds, $teamUserIds, $user, $ownEmployeeId) {
            $scoped->where('raised_by_user_id', $user->id);

            if ($teamUserIds !== []) {
                $scoped->orWhereIn('raised_by_user_id', $teamUserIds);
            }

            if ($teamIds !== []) {
                $scoped->orWhereIn('assigned_to_employee_id', $teamIds);
            }

            if ($ownEmployeeId) {
                $scoped->orWhere('assigned_to_employee_id', (int) $ownEmployeeId);
            }
        });
    }

    private function employeeCanView(SupportTicket $ticket, User $user): bool
    {
        if ((int) $ticket->raised_by_user_id === (int) $user->id) {
            return true;
        }

        $employeeId = $this->employeeDataScope->scopedEmployeeId($user);
        if ($employeeId !== null && $employeeId > 0) {
            return (int) $ticket->assigned_to_employee_id === (int) $employeeId;
        }

        return false;
    }

    private function managerCanView(SupportTicket $ticket, User $user): bool
    {
        if ((int) $ticket->raised_by_user_id === (int) $user->id) {
            return true;
        }

        $teamIds = $this->visibleEmployeesQuery($user)->pluck('employee_id')->all();
        if ($teamIds !== []) {
            $teamUserIds = Employee::query()
                ->whereIn('employee_id', $teamIds)
                ->whereNotNull('user_id')
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (in_array((int) $ticket->raised_by_user_id, $teamUserIds, true)) {
                return true;
            }
        }

        $assigneeId = (int) ($ticket->assigned_to_employee_id ?? 0);
        if ($assigneeId <= 0) {
            return false;
        }

        return $this->visibleEmployeesQuery($user)
            ->where('employee_id', $assigneeId)
            ->exists();
    }
}
