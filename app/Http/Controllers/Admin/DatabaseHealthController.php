<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DatabaseHealthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Super-admin database health endpoint.
 */
class DatabaseHealthController extends Controller
{
    public function __construct(
        private readonly DatabaseHealthService $databaseHealthService,
        private readonly \App\Services\Rbac\RbacService $rbacService,
    ) {}

    public function show(): JsonResponse
    {
        if ($this->rbacService->roleKey(auth()->user()) !== 'super_admin') {
            return ApiResponse::error('You do not have permission to access database health.', 403);
        }

        return ApiResponse::success(
            $this->databaseHealthService->report(),
            'Database health report generated',
        );
    }
}
