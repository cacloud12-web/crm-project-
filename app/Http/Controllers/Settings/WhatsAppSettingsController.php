<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\WhatsApp\SendWhatsAppTestTemplateRequest;
use App\Http\Requests\WhatsApp\UpdateWhatsAppSettingsRequest;
use App\Services\WhatsApp\WhatsAppConnectionService;
use App\Services\WhatsApp\WhatsAppSettingsService;
use App\Services\WhatsApp\WhatsAppTemplateService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class WhatsAppSettingsController extends Controller
{
    public function __construct(
        private readonly WhatsAppSettingsService $whatsAppSettingsService,
        private readonly WhatsAppConnectionService $whatsAppConnectionService,
        private readonly WhatsAppTemplateService $whatsAppTemplateService,
    ) {}

    public function show(): JsonResponse
    {
        try {
            $this->whatsAppSettingsService->ensureCanViewSettings(auth()->user());
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        return ApiResponse::success(
            $this->whatsAppSettingsService->toPublicArray(),
            'WhatsApp settings loaded',
        );
    }

    public function update(UpdateWhatsAppSettingsRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $this->whatsAppSettingsService->assertSavePayloadValid($request->validated());

            return ApiResponse::success(
                $this->whatsAppSettingsService->update($request->validated(), $user),
                'WhatsApp settings saved',
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

    public function validateConfiguration(): JsonResponse
    {
        try {
            $result = $this->whatsAppSettingsService->validateConfiguration(auth()->user());
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        return ApiResponse::success(
            $result,
            $result['valid'] ? 'WhatsApp configuration is valid (no API call made)' : 'WhatsApp configuration validation failed',
        );
    }

    public function testConnection(): JsonResponse
    {
        try {
            $result = $this->whatsAppConnectionService->testConnection(
                $this->whatsAppSettingsService->current(),
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

    public function sendTestTemplate(SendWhatsAppTestTemplateRequest $request): JsonResponse
    {
        try {
            $settings = $this->whatsAppSettingsService->current();
            $template = $this->whatsAppTemplateService->findApproved((int) $request->validated('message_template_id'));
            $mobile = $request->validated('mobile_no')
                ?: $settings->test_mobile_number
                ?: config('whatsapp_cloud.env_defaults.test_mobile_number');

            if (! filled($mobile)) {
                return ApiResponse::error('Configure a test mobile number before sending a test template.', 422);
            }

            $result = $this->whatsAppConnectionService->sendTestTemplate(
                $settings,
                $template,
                (string) $mobile,
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

        if (! $result['success']) {
            return ApiResponse::error($result['message'], 422, $result);
        }

        return ApiResponse::success($result, $result['message']);
    }

    public function reset(): JsonResponse
    {
        try {
            return ApiResponse::success(
                $this->whatsAppSettingsService->resetToDefaults(auth()->user()),
                'WhatsApp settings reset to defaults',
            );
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }
    }
}
