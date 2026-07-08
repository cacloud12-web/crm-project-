<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Security\ResetRolePermissionsRequest;
use App\Http\Requests\Security\UpdateRolePermissionsRequest;
use App\Services\Rbac\RbacDatabaseService;
use App\Services\Rbac\RbacMatrixService;
use App\Services\Rbac\RbacService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class RolePermissionsController extends Controller
{
    public function __construct(
        private readonly RbacService $rbacService,
        private readonly RbacMatrixService $rbacMatrixService,
        private readonly RbacDatabaseService $rbacDatabaseService,
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
}
