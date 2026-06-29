<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Services\Employee\EmployeeCredentialService;
use App\Services\Rbac\RbacService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PasswordController extends Controller
{
    public function __construct(
        private readonly EmployeeCredentialService $credentialService,
    ) {}

    public function change(ChangePasswordRequest $request): JsonResponse
    {
        $role = app(RbacService::class)->roleKey($request->user());
        if ($role === 'employee') {
            return response()->json([
                'success' => false,
                'message' => 'Password changes must be requested from your administrator.',
            ], 403);
        }

        $this->credentialService->changeOwnPassword(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('password'),
        );

        return ApiResponse::success(null, 'Password updated successfully.');
    }
}
