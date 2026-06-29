<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Email\UpdateEmailSettingsRequest;
use App\Services\Email\EmailSettingsService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;

class EmailSettingsController extends Controller
{
    public function __construct(
        private readonly EmailSettingsService $emailSettingsService,
    ) {}

    public function show(): JsonResponse
    {
        try {
            $this->emailSettingsService->ensureCanViewSettings(auth()->user());
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        return ApiResponse::success(
            $this->emailSettingsService->toPublicArray(),
            'Email settings loaded',
        );
    }

    public function update(UpdateEmailSettingsRequest $request): JsonResponse
    {
        try {
            $settings = $this->emailSettingsService->update(
                $request->validated(),
                $request->user(),
            );
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        return ApiResponse::success($settings, 'Email settings saved');
    }
}
