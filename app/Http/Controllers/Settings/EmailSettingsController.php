<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Email\SendTestEmailRequest;
use App\Http\Requests\Email\UpdateEmailSettingsRequest;
use App\Services\Email\EmailSettingsService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

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

    public function validateConfiguration(): JsonResponse
    {
        try {
            $this->emailSettingsService->ensureCanViewSettings(auth()->user());
            $result = $this->emailSettingsService->validateConfiguration();
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        return ApiResponse::success(
            $result,
            $result['valid'] ? 'Email SMTP configuration is valid' : 'Email SMTP configuration validation failed',
        );
    }

    public function sendTestEmail(SendTestEmailRequest $request): JsonResponse
    {
        try {
            $result = $this->emailSettingsService->sendTestEmail(
                (string) $request->validated('recipient_email'),
                $request->user(),
            );
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        if (! $result['success']) {
            return ApiResponse::error($result['message'], 422, $result);
        }

        return ApiResponse::success($result, $result['message']);
    }
}
