<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sms\TestSmsConnectionRequest;
use App\Http\Requests\Sms\UpdateSmsSettingsRequest;
use App\Services\Sms\SmsAlertConnectionService;
use App\Services\Sms\SmsSettingsService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class SmsSettingsController extends Controller
{
    public function __construct(
        private readonly SmsSettingsService $smsSettingsService,
        private readonly SmsAlertConnectionService $smsAlertConnectionService,
    ) {}

    public function show(): JsonResponse
    {
        try {
            $this->smsSettingsService->ensureCanViewSettings(auth()->user());
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        return ApiResponse::success(
            $this->smsSettingsService->toPublicArray(),
            'SMS settings loaded',
        );
    }

    public function update(UpdateSmsSettingsRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $this->smsSettingsService->assertSavePayloadValid($request->validated());

            return ApiResponse::success(
                $this->smsSettingsService->update($request->validated(), $user),
                'SMS settings saved',
            );
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        } catch (ValidationException $exception) {
            return ApiResponse::error(
                collect($exception->errors())->flatten()->first() ?? 'Validation failed.',
                422,
                $exception->errors(),
            );
        }
    }

    public function testConfiguration(): JsonResponse
    {
        try {
            $result = $this->smsSettingsService->validateConfiguration(auth()->user());
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        return ApiResponse::success(
            $result,
            $result['valid'] ? 'SMS configuration is valid (no API call made)' : 'SMS configuration validation failed',
        );
    }

    public function testConnection(TestSmsConnectionRequest $request): JsonResponse
    {
        try {
            $result = $this->smsAlertConnectionService->testConnection(
                $this->smsSettingsService->current(),
                $request->validated('mobileno'),
                $request->validated('text'),
                auth()->user(),
            );
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        } catch (ValidationException $exception) {
            return ApiResponse::error(
                collect($exception->errors())->flatten()->first() ?? 'Validation failed.',
                422,
                $exception->errors(),
            );
        }

        return ApiResponse::success($result, $result['message']);
    }

    public function reset(): JsonResponse
    {
        try {
            return ApiResponse::success(
                $this->smsSettingsService->resetToDefaults(auth()->user()),
                'SMS settings reset to defaults',
            );
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }
    }
}
