<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Security\UpdateSecurityMatrixRequest;
use App\Models\User;
use App\Services\Rbac\RbacMatrixService;
use App\Services\Rbac\RbacService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class SecurityController extends Controller
{
    public function __construct(
        private readonly RbacService $rbacService,
        private readonly RbacMatrixService $rbacMatrixService,
    ) {}

    public function show(): JsonResponse
    {
        $user = auth()->user();
        $matrix = $this->rbacMatrixService->effectiveMatrix();

        $users = User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'crm_role'])
            ->map(fn (User $row) => [
                'id' => $row->id,
                'name' => $row->name,
                'email' => $row->email,
                'role' => $this->rbacService->roleKey($row),
                'role_label' => $this->rbacService->roleLabel($row),
                'permissions' => $this->rbacService->permissionsFor($row),
            ])
            ->values()
            ->all();

        return ApiResponse::success([
            'roles' => config('rbac.roles', []),
            'modules' => config('rbac.modules', []),
            'permissions' => config('rbac.permissions', []),
            'matrix' => $matrix,
            'editable_roles' => array_values(array_diff(array_keys(config('rbac.roles', [])), ['super_admin'])),
            'can_edit' => $this->rbacMatrixService->canEditMatrix($user),
            'users' => $users,
        ], 'Security matrix loaded');
    }

    public function update(UpdateSecurityMatrixRequest $request): JsonResponse
    {
        $user = auth()->user();

        if (! $this->rbacMatrixService->canEditMatrix($user)) {
            return ApiResponse::error('You are not allowed to update security permissions.', 403);
        }

        try {
            $matrix = $this->rbacMatrixService->togglePermission(
                $user,
                $request->validated('role'),
                $request->validated('module'),
                $request->validated('permission'),
                (bool) $request->validated('granted'),
            );
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success([
            'matrix' => $matrix,
        ], 'Security permission updated');
    }
}
