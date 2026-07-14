<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Presence\EmployeePresenceService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PresenceController extends Controller
{
    public function __construct(
        private readonly EmployeePresenceService $presenceService,
    ) {}

    public function heartbeat(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return ApiResponse::error('Unauthenticated.', 401);
        }

        // Employees (and any authenticated CRM user) may only refresh their own presence.
        $this->presenceService->touch($user);

        return ApiResponse::success(
            $this->presenceService->payloadForUser($user->fresh()),
            'Presence updated',
        );
    }
}
