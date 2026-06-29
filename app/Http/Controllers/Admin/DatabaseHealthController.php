<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DatabaseHealthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Development/admin database health endpoint.
 *
 * TODO: Protect this route with admin authentication and authorization before production.
 */
class DatabaseHealthController extends Controller
{
    public function __construct(
        private readonly DatabaseHealthService $databaseHealthService,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success(
            $this->databaseHealthService->report(),
            'Database health report generated',
        );
    }
}
