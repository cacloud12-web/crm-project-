<?php

namespace App\Services\Rbac;

use App\Models\CrmSetting;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class RbacMatrixService
{
    private const CACHE_KEY = 'crm:rbac:matrix';

    private const PROTECTED_ROLES = ['super_admin'];

    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly RbacDatabaseService $rbacDatabaseService,
    ) {}

    public function effectiveMatrix(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, function () {
            if ($this->rbacDatabaseService->isSeeded()) {
                return $this->rbacDatabaseService->buildMatrix();
            }

            $base = config('rbac.matrix', []);
            $stored = CrmSetting::query()
                ->where('group', 'rbac')
                ->where('key', 'matrix')
                ->value('value');

            if (! $stored) {
                return $base;
            }

            $override = json_decode($stored, true);

            if (! is_array($override)) {
                return $base;
            }

            return $this->mergeMatrix($base, $override);
        });
    }

    public function canEditMatrix(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $role = app(RbacService::class)->roleKey($user);

        return $role === 'super_admin';
    }

    public function canManageRolePermissions(?User $user): bool
    {
        return $this->canEditMatrix($user);
    }

    public function togglePermission(
        User $actor,
        string $role,
        string $module,
        string $permission,
        bool $granted,
    ): array {
        if (in_array($role, self::PROTECTED_ROLES, true)) {
            throw new InvalidArgumentException('This role cannot be modified.');
        }

        $modules = config('rbac.modules', []);
        $permissions = config('rbac.permissions', []);

        if (! in_array($module, $modules, true)) {
            throw new InvalidArgumentException('Invalid module.');
        }

        if (! in_array($permission, $permissions, true)) {
            throw new InvalidArgumentException('Invalid permission.');
        }

        $matrix = $this->effectiveMatrix();
        $roleMatrix = $matrix[$role] ?? [];

        if ($this->roleHasWildcard($roleMatrix)) {
            throw new InvalidArgumentException('Wildcard roles cannot be edited.');
        }

        $modulePermissions = $roleMatrix[$module] ?? $roleMatrix['*'] ?? [];

        if (in_array('*', $modulePermissions, true)) {
            $modulePermissions = $permissions;
        }

        $modulePermissions = array_values(array_filter(
            $modulePermissions,
            fn (string $perm) => $perm !== $permission,
        ));

        if ($granted) {
            $modulePermissions[] = $permission;
        }

        $modulePermissions = array_values(array_unique($modulePermissions));

        $this->guardSelfLockout($actor, $role, $module, $modulePermissions);

        $matrix[$role][$module] = $modulePermissions;
        unset($matrix[$role]['*']);

        $this->persistMatrix($role, $matrix[$role]);

        $this->activityLogService->log(
            'SECURITY',
            'Permission Update',
            $role,
            "{$role}.{$module}.{$permission}=".($granted ? 'granted' : 'revoked'),
            $actor->name,
        );

        Cache::forget(self::CACHE_KEY);

        return $this->effectiveMatrix();
    }

    /**
     * @param  array<string, list<string>>  $moduleGrants
     */
    public function updateRolePermissions(User $actor, string $role, array $moduleGrants): array
    {
        if (in_array($role, self::PROTECTED_ROLES, true)) {
            throw new InvalidArgumentException('This role cannot be modified.');
        }

        $this->guardSelfLockoutMatrix($actor, $role, $moduleGrants);

        if ($this->rbacDatabaseService->isSeeded()) {
            $this->rbacDatabaseService->saveRolePermissions($role, $moduleGrants);
        } else {
            $matrix = $this->effectiveMatrix();
            $matrix[$role] = $moduleGrants;
            $this->persistLegacyMatrix($matrix);
        }

        $this->activityLogService->log(
            'SECURITY',
            'Role Permissions Saved',
            $role,
            'Updated permissions for '.$role,
            $actor->name,
        );

        Cache::forget(self::CACHE_KEY);

        return $this->effectiveMatrix();
    }

    public function resetRolePermissions(User $actor, string $role): array
    {
        if (in_array($role, self::PROTECTED_ROLES, true)) {
            throw new InvalidArgumentException('This role cannot be reset.');
        }

        if ($this->rbacDatabaseService->isSeeded()) {
            $this->rbacDatabaseService->resetRoleToDefault($role);
        } else {
            $defaults = config('rbac.matrix.'.$role, []);
            $matrix = $this->effectiveMatrix();
            $matrix[$role] = $defaults;
            $this->persistLegacyMatrix($matrix);
        }

        $this->activityLogService->log(
            'SECURITY',
            'Role Permissions Reset',
            $role,
            'Reset permissions to default for '.$role,
            $actor->name,
        );

        Cache::forget(self::CACHE_KEY);

        return $this->effectiveMatrix();
    }

    public function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function persistMatrix(string $role, array $roleMatrix): void
    {
        if ($this->rbacDatabaseService->isSeeded()) {
            $this->rbacDatabaseService->saveRolePermissions($role, $roleMatrix);

            return;
        }

        $matrix = $this->effectiveMatrix();
        $matrix[$role] = $roleMatrix;
        $this->persistLegacyMatrix($matrix);
    }

    private function persistLegacyMatrix(array $matrix): void
    {
        $base = config('rbac.matrix', []);
        $override = [];

        foreach ($matrix as $role => $roleMatrix) {
            if (! isset($base[$role])) {
                continue;
            }

            if ($roleMatrix !== $base[$role]) {
                $override[$role] = $roleMatrix;
            }
        }

        CrmSetting::query()->updateOrCreate(
            ['group' => 'rbac', 'key' => 'matrix'],
            ['value' => json_encode($override, JSON_UNESCAPED_UNICODE)],
        );
    }

    /**
     * @param  array<string, list<string>>  $moduleGrants
     */
    private function guardSelfLockoutMatrix(User $actor, string $role, array $moduleGrants): void
    {
        $actorRole = app(RbacService::class)->roleKey($actor);

        if ($actorRole !== $role) {
            return;
        }

        $dashboard = $moduleGrants['dashboard'] ?? [];
        if (! in_array('view', $dashboard, true)) {
            throw new InvalidArgumentException('You cannot remove your own dashboard access.');
        }
    }

    private function guardSelfLockout(User $actor, string $role, string $module, array $modulePermissions): void
    {
        $actorRole = app(RbacService::class)->roleKey($actor);

        if ($actorRole !== $role) {
            return;
        }

        if ($module === 'dashboard' && ! in_array('view', $modulePermissions, true)) {
            throw new InvalidArgumentException('You cannot remove your own dashboard access.');
        }

        if ($module === 'security' && ! in_array('view', $modulePermissions, true)) {
            throw new InvalidArgumentException('You cannot remove your own security access.');
        }
    }

    private function mergeMatrix(array $base, array $override): array
    {
        foreach ($override as $role => $roleMatrix) {
            if (! is_array($roleMatrix) || ! isset($base[$role])) {
                continue;
            }

            $base[$role] = array_replace($base[$role], $roleMatrix);
        }

        return $base;
    }

    private function roleHasWildcard(array $matrix): bool
    {
        return isset($matrix['*']) && in_array('*', $matrix['*'], true);
    }
}
