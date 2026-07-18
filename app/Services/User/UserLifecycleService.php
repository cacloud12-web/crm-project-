<?php

namespace App\Services\User;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class UserLifecycleService
{
    public function isProtectedRootUser(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $rootEmail = strtolower(trim((string) config('crm_bootstrap.root_super_admin_email', '')));

        return $rootEmail !== '' && strtolower((string) $user->email) === $rootEmail;
    }

    public function activeSuperAdminCount(): int
    {
        return User::query()
            ->where('crm_role', 'super_admin')
            ->where('is_active', true)
            ->count();
    }

    public function isLastActiveSuperAdmin(?User $user): bool
    {
        if (! $user || $user->crm_role !== 'super_admin' || ! $user->is_active) {
            return false;
        }

        return $this->activeSuperAdminCount() <= 1;
    }

    public function assertCanDeleteUser(User $user): void
    {
        if ($this->isProtectedRootUser($user)) {
            throw ValidationException::withMessages([
                'user' => ['The root Super Admin account cannot be deleted.'],
            ]);
        }

        if ($user->crm_role === 'super_admin') {
            throw ValidationException::withMessages([
                'user' => ['Super Admin accounts cannot be deleted from user management.'],
            ]);
        }
    }

    public function assertCanChangeRole(User $user, string $newRole): void
    {
        if ($this->isProtectedRootUser($user)) {
            throw ValidationException::withMessages([
                'crm_role' => ['The root Super Admin role cannot be changed.'],
            ]);
        }

        if ($user->crm_role === 'super_admin' && $newRole !== 'super_admin' && $this->isLastActiveSuperAdmin($user)) {
            throw ValidationException::withMessages([
                'crm_role' => ['Cannot demote the last active Super Admin. Create another Super Admin first.'],
            ]);
        }

        if ($user->crm_role === 'super_admin' && $newRole !== 'super_admin') {
            throw ValidationException::withMessages([
                'crm_role' => ['Super Admin accounts cannot be demoted from user management.'],
            ]);
        }
    }

    public function assertCanDeactivateUser(User $user): void
    {
        if ($this->isProtectedRootUser($user)) {
            throw ValidationException::withMessages([
                'status' => ['The root Super Admin account cannot be deactivated.'],
            ]);
        }

        if ($this->isLastActiveSuperAdmin($user)) {
            throw ValidationException::withMessages([
                'status' => ['Cannot deactivate the last active Super Admin.'],
            ]);
        }
    }

    public function deactivateAndSoftDelete(User $user): void
    {
        $this->assertCanDeleteUser($user);

        $user->update(['is_active' => false]);
        $user->delete();
    }
}
