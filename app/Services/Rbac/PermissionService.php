<?php

namespace App\Services\Rbac;

use App\Models\User;

/**
 * Central permission API for controllers, middleware, and services.
 *
 * Supports:
 * - PermissionService::can($user, 'campaigns', 'view')
 * - PermissionService::can($user, 'campaigns.view')
 * - PermissionService::can($user, 'communication.view')  // aliases to campaigns
 */
class PermissionService
{
    /** @var array<string, string> */
    private const MODULE_ALIASES = [
        'communication' => 'campaigns',
        'master_data' => 'ca_master',
        'lead_management' => 'leads',
    ];

    public function __construct(
        private readonly RbacService $rbacService,
    ) {}

    public function can(?User $user, string $moduleOrKey, ?string $permission = null): bool
    {
        [$module, $action] = $this->resolve($moduleOrKey, $permission);

        return $this->rbacService->can($user, $module, $action);
    }

    public function authorize(?User $user, string $moduleOrKey, ?string $permission = null): void
    {
        [$module, $action] = $this->resolve($moduleOrKey, $permission);
        $this->rbacService->authorize($user, $module, $action);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolve(string $moduleOrKey, ?string $permission): array
    {
        if ($permission !== null && $permission !== '') {
            $module = self::MODULE_ALIASES[$moduleOrKey] ?? $moduleOrKey;

            return [$module, $permission];
        }

        $parts = explode('.', $moduleOrKey, 2);
        if (count($parts) !== 2) {
            return [$moduleOrKey, 'view'];
        }

        $module = self::MODULE_ALIASES[$parts[0]] ?? $parts[0];

        return [$module, $parts[1]];
    }
}
