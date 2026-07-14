<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Security\UpdateSecurityMatrixRequest;
use App\Models\CaMaster;
use App\Models\ConsentTracking;
use App\Models\DndManagement;
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

        $lockTtlMinutes = (int) config('crm_leads.lock_ttl_minutes', 10);
        $rateLimits = config('crm_rate_limits', []);

        return ApiResponse::success([
            'roles' => config('rbac.roles', []),
            'modules' => config('rbac.modules', []),
            'permissions' => config('rbac.permissions', []),
            'matrix' => $matrix,
            'editable_roles' => array_values(array_diff(array_keys(config('rbac.roles', [])), ['super_admin'])),
            'can_edit' => $this->rbacMatrixService->canEditMatrix($user),
            'users' => $users,
            'summary' => [
                'role_count' => count(config('rbac.roles', [])),
                'user_count' => User::query()->count(),
                'consent_count' => ConsentTracking::query()->count(),
                'dnd_count' => DndManagement::query()->count(),
                'active_lock_count' => CaMaster::query()
                    ->whereNotNull('locked_by')
                    ->whereNotNull('locked_at')
                    ->where('locked_at', '>=', now()->subMinutes($lockTtlMinutes))
                    ->count(),
                'encryption_label' => 'AES-256 via Laravel APP_KEY',
                'api_rate_summary' => sprintf(
                    'Import %d/min · Actions %d/min',
                    (int) ($rateLimits['bulk_import']['max_attempts'] ?? 10),
                    (int) ($rateLimits['lead_action']['max_attempts'] ?? 60),
                ),
            ],
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
