<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Security\ResetRolePermissionsRequest;
use App\Http\Requests\Security\UpdateRolePermissionsRequest;
use App\Http\Requests\Security\UpdateUserPermissionOverridesRequest;
use App\Models\User;
use App\Services\Rbac\RbacDatabaseService;
use App\Services\Rbac\RbacMatrixService;
use App\Services\Rbac\RbacService;
use App\Services\Rbac\RbacUserOverrideService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RolePermissionsController extends Controller
{
    public function __construct(
        private readonly RbacService $rbacService,
        private readonly RbacMatrixService $rbacMatrixService,
        private readonly RbacDatabaseService $rbacDatabaseService,
        private readonly RbacUserOverrideService $overrideService,
    ) {}

    public function show(): JsonResponse
    {
        $user = auth()->user();

        if (! $this->rbacMatrixService->canManageRolePermissions($user)) {
            return ApiResponse::error('You do not have permission to access this action.', 403);
        }

        $matrix = $this->rbacMatrixService->effectiveMatrix();

        return ApiResponse::success([
            'roles' => $this->rbacDatabaseService->roleCatalog(),
            'modules' => config('rbac.matrix_modules', []),
            'module_labels' => config('rbac.module_labels', []),
            'permissions' => config('rbac.matrix_permissions', []),
            'permission_labels' => config('rbac.permission_labels', []),
            'matrix' => $matrix,
            'editable_roles' => ['manager', 'employee', 'admin'],
            'can_edit' => true,
            'supports_user_overrides' => true,
        ], 'Role permissions loaded');
    }

    public function update(UpdateRolePermissionsRequest $request): JsonResponse
    {
        $user = auth()->user();

        if (! $this->rbacMatrixService->canManageRolePermissions($user)) {
            return ApiResponse::error('You do not have permission to access this action.', 403);
        }

        try {
            $matrix = $this->rbacMatrixService->updateRolePermissions(
                $user,
                $request->validated('role'),
                $request->validated('grants'),
            );
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success([
            'matrix' => $matrix,
            'permissions' => $this->rbacService->permissionsFor($user),
        ], 'Permissions saved successfully');
    }

    public function reset(ResetRolePermissionsRequest $request): JsonResponse
    {
        $user = auth()->user();

        if (! $this->rbacMatrixService->canManageRolePermissions($user)) {
            return ApiResponse::error('You do not have permission to access this action.', 403);
        }

        try {
            $matrix = $this->rbacMatrixService->resetRolePermissions(
                $user,
                $request->validated('role'),
            );
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success([
            'matrix' => $matrix,
        ], 'Permissions reset to default');
    }

    public function employees(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (! $this->rbacMatrixService->canManageRolePermissions($user)) {
            return ApiResponse::error('You do not have permission to access this action.', 403);
        }

        $q = trim((string) $request->query('q', ''));
        $query = User::query()
            ->whereIn('crm_role', ['employee', 'manager', 'admin'])
            ->orderBy('name')
            ->limit(50);

        if ($q !== '') {
            $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', '%'.$q.'%')
                    ->orWhere('email', 'like', '%'.$q.'%');
            });
        }

        $items = $query->get(['id', 'name', 'email', 'crm_role'])->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $this->rbacService->roleKey($u),
            'role_label' => $this->rbacService->roleLabel($u),
        ]);

        return ApiResponse::success(['items' => $items], 'Employees loaded');
    }

    public function showUserOverrides(int $userId): JsonResponse
    {
        $actor = auth()->user();
        if (! $this->rbacMatrixService->canManageRolePermissions($actor)) {
            return ApiResponse::error('You do not have permission to access this action.', 403);
        }

        $target = User::query()->findOrFail($userId);
        $roleKey = $this->rbacService->roleKey($target);
        $matrix = $this->rbacMatrixService->effectiveMatrix();
        $roleGrants = $matrix[$roleKey] ?? [];
        $overrides = $this->overrideService->overridesForUser($target);

        return ApiResponse::success([
            'user' => [
                'id' => $target->id,
                'name' => $target->name,
                'email' => $target->email,
                'role' => $roleKey,
                'role_label' => $this->rbacService->roleLabel($target),
            ],
            'role_grants' => $roleGrants,
            'overrides' => $overrides,
            'effective' => $this->rbacService->permissionsFor($target),
            'modules' => config('rbac.matrix_modules', []),
            'permissions' => config('rbac.matrix_permissions', []),
            'module_labels' => config('rbac.module_labels', []),
            'permission_labels' => config('rbac.permission_labels', []),
        ], 'User permission overrides loaded');
    }

    public function updateUserOverrides(UpdateUserPermissionOverridesRequest $request): JsonResponse
    {
        $actor = auth()->user();
        if (! $this->rbacMatrixService->canManageRolePermissions($actor)) {
            return ApiResponse::error('You do not have permission to access this action.', 403);
        }

        $target = User::query()->findOrFail((int) $request->validated('user_id'));

        try {
            $overrides = $this->overrideService->saveOverrides(
                $actor,
                $target,
                $request->validated('allows') ?? [],
                $request->validated('denies') ?? [],
            );
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success([
            'overrides' => $overrides,
            'effective' => $this->rbacService->permissionsFor($target->fresh()),
        ], 'Employee permission overrides saved successfully');
    }

    public function resetUserOverrides(int $userId): JsonResponse
    {
        $actor = auth()->user();
        if (! $this->rbacMatrixService->canManageRolePermissions($actor)) {
            return ApiResponse::error('You do not have permission to access this action.', 403);
        }

        $target = User::query()->findOrFail($userId);
        $overrides = $this->overrideService->clearOverrides($actor, $target);

        return ApiResponse::success([
            'overrides' => $overrides,
            'effective' => $this->rbacService->permissionsFor($target->fresh()),
        ], 'Employee overrides reset to role defaults');
    }
}
