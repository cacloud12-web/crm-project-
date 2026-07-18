<?php

namespace App\Services\Employee;

use App\Models\Employee;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use App\Services\Rbac\RbacService;
use App\Services\User\UserLifecycleService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class EmployeeCredentialService
{
    public function __construct(
        private readonly RbacService $rbacService,
        private readonly ActivityLogService $activityLogService,
        private readonly UserLifecycleService $userLifecycleService,
    ) {}

    /** @return list<string> */
    public function assignableRoles(?User $actor): array
    {
        return match ($this->rbacService->roleKey($actor)) {
            'super_admin' => ['employee', 'manager', 'admin'],
            'admin' => ['employee', 'manager'],
            'manager' => ['employee'],
            default => [],
        };
    }

    public function canManageEmployeeCredentials(?User $actor): bool
    {
        return in_array($this->rbacService->roleKey($actor), ['admin', 'super_admin'], true);
    }

    public function createLoginForEmployee(Employee $employee, string $password, string $crmRole = 'employee'): User
    {
        $existing = User::withTrashed()->where('email', $employee->email_id)->first();

        if ($existing && ! $existing->trashed()) {
            throw ValidationException::withMessages([
                'email_id' => ['A login account already exists for this email.'],
            ]);
        }

        if ($existing && $existing->trashed()) {
            $existing->restore();
            $existing->update([
                'name' => $employee->name,
                'password' => $password,
                'crm_role' => $crmRole,
                'is_active' => ($employee->status ?? 'Active') === 'Active',
            ]);
            $employee->update(['user_id' => $existing->id]);

            return $existing;
        }

        $user = User::query()->create([
            'name' => $employee->name,
            'email' => $employee->email_id,
            'password' => $password,
            'crm_role' => $crmRole,
            'is_active' => ($employee->status ?? 'Active') === 'Active',
        ]);

        $employee->update(['user_id' => $user->id]);

        $this->activityLogService->log(
            'EMPLOYEE_MASTER',
            'Employee login created',
            (string) $employee->employee_id,
            $employee->name,
        );

        return $user;
    }

    public function syncUserFromEmployee(Employee $employee, ?string $previousEmail = null): void
    {
        $user = $this->resolveUser($employee, $previousEmail);

        if (! $user) {
            return;
        }

        $willBeActive = ($employee->status ?? 'Active') === 'Active';
        if ($user->is_active && ! $willBeActive) {
            $this->userLifecycleService->assertCanDeactivateUser($user);
        }

        $user->update([
            'name' => $employee->name,
            'email' => $employee->email_id,
            'is_active' => $willBeActive,
        ]);

        if ($employee->user_id !== $user->id) {
            $employee->update(['user_id' => $user->id]);
        }
    }

    public function deactivateLogin(Employee $employee): void
    {
        $user = $employee->user;

        if (! $user) {
            return;
        }

        $this->userLifecycleService->assertCanDeactivateUser($user);
        $user->update(['is_active' => false]);
    }

    public function removeLoginForEmployee(Employee $employee): void
    {
        $user = $employee->user;

        if (! $user) {
            return;
        }

        $this->userLifecycleService->deactivateAndSoftDelete($user);
        $employee->update(['user_id' => null]);
    }

    public function syncCrmRoleForEmployee(Employee $employee, string $crmRole, ?User $actor): void
    {
        $user = $employee->user;

        if (! $user || ! in_array($crmRole, $this->assignableRoles($actor), true)) {
            throw ValidationException::withMessages([
                'crm_role' => ['You cannot assign the selected CRM role.'],
            ]);
        }

        $this->userLifecycleService->assertCanChangeRole($user, $crmRole);

        $user->update(['crm_role' => $crmRole]);
    }

    public function changeOwnPassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->update(['password' => $newPassword]);

        $this->activityLogService->log(
            'SECURITY',
            'Password Changed',
            (string) $user->id,
            $user->name,
            performedBy: $user->name,
        );
    }

    public function resetEmployeePassword(User $actor, Employee $employee, string $newPassword): void
    {
        if (! $this->canManageEmployeeCredentials($actor)) {
            abort(403, 'You do not have permission to reset employee passwords.');
        }

        $user = $employee->user;

        if (! $user) {
            throw ValidationException::withMessages([
                'employee_id' => ['This employee does not have a login account yet.'],
            ]);
        }

        $user->update(['password' => $newPassword]);

        $this->activityLogService->log(
            'EMPLOYEE_MASTER',
            'Admin reset employee password',
            (string) $employee->employee_id,
            $employee->name,
            performedBy: $actor->name,
        );
    }

    /** @return array{provisioned:int,linked:int,skipped:int} */
    public function provisionMissingLogins(?User $actor, ?string $defaultPassword = null): array
    {
        if (! $this->canManageEmployeeCredentials($actor)) {
            abort(403, 'You do not have permission to provision employee logins.');
        }

        $password = $defaultPassword ?: 'ChangeMe'.random_int(1000, 9999).'!';

        $stats = ['provisioned' => 0, 'linked' => 0, 'skipped' => 0];

        Employee::query()
            ->whereNull('user_id')
            ->orderBy('employee_id')
            ->each(function (Employee $employee) use ($password, &$stats) {
                $existingUser = User::withTrashed()->where('email', $employee->email_id)->first();

                if ($existingUser) {
                    if ($existingUser->trashed()) {
                        $existingUser->restore();
                        $existingUser->update(['is_active' => ($employee->status ?? 'Active') === 'Active']);
                    }
                    $employee->update(['user_id' => $existingUser->id]);
                    $stats['linked']++;

                    return;
                }

                try {
                    $this->createLoginForEmployee($employee, $password, 'employee');
                    $stats['provisioned']++;
                } catch (ValidationException) {
                    $stats['skipped']++;
                }
            });

        return $stats;
    }

    public function loginStatus(Employee $employee): string
    {
        $user = $employee->user;

        if (! $user) {
            return 'none';
        }

        return $user->is_active ? 'active' : 'inactive';
    }

    public function loginStatusLabel(Employee $employee): string
    {
        return match ($this->loginStatus($employee)) {
            'active' => 'Login Active',
            'inactive' => 'Login Inactive',
            default => 'No Login Created',
        };
    }

    private function resolveUser(Employee $employee, ?string $previousEmail = null): ?User
    {
        if ($employee->user_id) {
            return User::withTrashed()->find($employee->user_id);
        }

        if ($previousEmail) {
            return User::withTrashed()->where('email', $previousEmail)->first();
        }

        return User::withTrashed()->where('email', $employee->email_id)->first();
    }
}
