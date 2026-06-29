<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Services\Auth\ProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    public function __construct(
        private readonly ProfileService $profileService,
    ) {}

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $payload = $this->profileService->update(
            $request->user(),
            $request->validated(),
        );

        return ApiResponse::success($payload, 'Profile updated successfully.');
    }
}
