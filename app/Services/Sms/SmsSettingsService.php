<?php

namespace App\Services\Sms;

use App\Models\SmsSetting;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use App\Services\Rbac\RbacService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class SmsSettingsService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly RbacService $rbacService,
    ) {}

    public function current(): SmsSetting
    {
        $settings = SmsSetting::query()->first();

        if ($settings) {
            return $settings;
        }

        return SmsSetting::create([
            'provider_name' => SmsSetting::DEFAULT_PROVIDER,
            'api_url' => SmsSetting::DEFAULT_API_URL,
            'api_key' => null,
            'sender_id' => null,
            'mode' => SmsSetting::MODE_SIMULATION,
            'is_active' => true,
            'integration_status' => SmsSetting::INTEGRATION_NOT_CONFIGURED,
        ]);
    }

    /**
     * Public API shape — API key is never exposed.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(?SmsSetting $settings = null): array
    {
        $settings ??= $this->current();

        return [
            'provider_name' => $settings->provider_name,
            'api_url' => $settings->api_url,
            'sender_id' => $settings->sender_id,
            'dlt_template_id' => $settings->dlt_template_id,
            'mode' => $settings->mode,
            'is_active' => (bool) $settings->is_active,
            'has_api_key' => $settings->hasApiKey(),
            'is_configured' => $settings->isConfigured(),
            'integration_status' => $this->integrationStatus($settings),
            'last_tested_at' => $settings->last_tested_at?->toIso8601String(),
            'last_test_status' => $settings->last_test_status,
            'last_test_message' => $this->lastTestMessage($settings),
            'can_edit' => $this->canManageSettings(auth()->user()),
            'can_send_live' => $this->canSendLiveSms($settings)['can_send'],
            'send_blockers' => $this->canSendLiveSms($settings)['errors'],
        ];
    }

    /**
     * @return array{can_send: bool, errors: array<int, string>}
     */
    public function canSendLiveSms(?SmsSetting $settings = null): array
    {
        $settings ??= $this->current();
        $errors = [];

        if (! $settings->is_active) {
            $errors[] = 'SMS provider is inactive. Enable it in SMS Settings.';
        }

        if (! $settings->isLiveMode()) {
            $errors[] = 'Set SMS mode to Live in SMS Settings to send messages.';
        }

        if (! filled($settings->api_url)) {
            $errors[] = 'API URL is required.';
        } elseif (! filter_var($settings->api_url, FILTER_VALIDATE_URL)) {
            $errors[] = 'API URL must be a valid URL.';
        }

        if (! $settings->hasApiKey()) {
            $errors[] = 'API Key is required.';
        }

        if (! filled($settings->sender_id)) {
            $errors[] = 'Sender ID is required.';
        }

        if ($settings->isLiveMode() && ! filled($settings->dlt_template_id)) {
            $errors[] = SmsAlertMappingService::ERROR_DLT_TEMPLATE_ID_REQUIRED;
        }

        return [
            'can_send' => $errors === [],
            'errors' => array_values(array_unique($errors)),
        ];
    }

    public function integrationStatus(?SmsSetting $settings = null): string
    {
        $settings ??= $this->current();

        if (! $settings->is_active) {
            return SmsSetting::INTEGRATION_DISABLED;
        }

        if (! $settings->hasApiKey() || ! filled($settings->sender_id)) {
            return SmsSetting::INTEGRATION_NOT_CONFIGURED;
        }

        $stored = (string) ($settings->integration_status ?? '');

        if (in_array($stored, [SmsSetting::INTEGRATION_INTEGRATED, SmsSetting::INTEGRATION_FAILED], true)) {
            return $stored;
        }

        return SmsSetting::INTEGRATION_CONNECTED;
    }

    public function lastTestMessage(?SmsSetting $settings = null): ?string
    {
        $settings ??= $this->current();

        if (! filled($settings->last_test_response)) {
            return null;
        }

        $decoded = json_decode((string) $settings->last_test_response, true);
        if (! is_array($decoded)) {
            return null;
        }

        $message = $decoded['description']
            ?? $decoded['message']
            ?? $decoded['error']
            ?? ($settings->last_test_status === 'success' ? 'SMS Alert connection test succeeded.' : null);

        if (is_array($message)) {
            $message = json_encode($message);
        }

        return $message !== null ? (string) $message : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function update(array $data, User $user): array
    {
        $this->ensureCanManageSettings($user);

        $settings = $this->current();
        $previousMode = $settings->mode;
        $wasActive = (bool) $settings->is_active;

        $payload = [
            'provider_name' => $data['provider_name'] ?? $settings->provider_name,
            'api_url' => $data['api_url'] ?? $settings->api_url,
            'sender_id' => array_key_exists('sender_id', $data) ? $data['sender_id'] : $settings->sender_id,
            'dlt_template_id' => array_key_exists('dlt_template_id', $data)
                ? ($data['dlt_template_id'] !== '' ? $data['dlt_template_id'] : null)
                : $settings->dlt_template_id,
            'mode' => $data['mode'] ?? $settings->mode,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $settings->is_active,
        ];

        $credentialsChanged = false;

        if (array_key_exists('api_key', $data) && filled($data['api_key'])) {
            $payload['api_key'] = $data['api_key'];
            $credentialsChanged = true;
        }

        if (array_key_exists('sender_id', $data) && $data['sender_id'] !== $settings->sender_id) {
            $credentialsChanged = true;
        }

        if (array_key_exists('api_url', $data) && $data['api_url'] !== $settings->api_url) {
            $credentialsChanged = true;
        }

        if ($credentialsChanged) {
            $payload['last_tested_at'] = null;
            $payload['last_test_status'] = null;
            $payload['last_test_response'] = null;

            $willHaveKey = (array_key_exists('api_key', $data) && filled($data['api_key'])) || $settings->hasApiKey();
            $willHaveSender = filled($payload['sender_id']);

            $payload['integration_status'] = ($willHaveKey && $willHaveSender)
                ? SmsSetting::INTEGRATION_CONNECTED
                : SmsSetting::INTEGRATION_NOT_CONFIGURED;
        }

        $settings->update($payload);
        $settings = $settings->fresh();

        $this->activityLogService->log(
            'SMS_SETTINGS',
            'SMS Settings Updated',
            (string) $settings->id,
            'SMS Alert mapping settings updated',
            $user->name ?? $user->email ?? 'System',
        );

        if ($previousMode !== $settings->mode) {
            $this->activityLogService->log(
                'SMS_SETTINGS',
                'SMS Mode Changed',
                (string) $settings->id,
                'Mode changed to '.$settings->mode,
                $user->name ?? $user->email ?? 'System',
            );
        }

        if (! $wasActive && $settings->is_active) {
            $this->activityLogService->log(
                'SMS_SETTINGS',
                'SMS Provider Activated',
                (string) $settings->id,
                $settings->provider_name.' activated',
                $user->name ?? $user->email ?? 'System',
            );
        }

        return $this->toPublicArray($settings);
    }

    /**
     * Validate configuration without calling the SMS Alert API.
     *
     * @return array{valid: bool, errors: array<int, string>, settings: array<string, mixed>}
     */
    public function validateConfiguration(?User $user = null): array
    {
        if ($user) {
            $this->ensureCanViewSettings($user);
        }

        $settings = $this->current();
        $errors = [];

        if (! filled($settings->api_url)) {
            $errors[] = 'API URL is required.';
        } elseif (! filter_var($settings->api_url, FILTER_VALIDATE_URL)) {
            $errors[] = 'API URL must be a valid URL.';
        }

        if (! $settings->hasApiKey()) {
            $errors[] = 'API Key is required.';
        }

        if (! filled($settings->sender_id)) {
            $errors[] = 'Sender ID is required.';
        }

        if ($settings->isLiveMode() && ! filled($settings->dlt_template_id)) {
            $errors[] = SmsAlertMappingService::ERROR_DLT_TEMPLATE_ID_REQUIRED;
        }

        if (! filled($settings->mode)) {
            $errors[] = 'Mode is required.';
        }

        if (! $settings->is_active) {
            $errors[] = 'SMS provider is inactive. Enable the provider to proceed.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'settings' => $this->toPublicArray($settings),
            'dispatch' => 'validation_only',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resetToDefaults(User $user): array
    {
        $this->ensureCanManageSettings($user);

        $settings = $this->current();
        $settings->update([
            'provider_name' => SmsSetting::DEFAULT_PROVIDER,
            'api_url' => SmsSetting::DEFAULT_API_URL,
            'api_key' => null,
            'sender_id' => null,
            'dlt_template_id' => null,
            'mode' => SmsSetting::MODE_SIMULATION,
            'is_active' => true,
            'integration_status' => SmsSetting::INTEGRATION_NOT_CONFIGURED,
            'last_tested_at' => null,
            'last_test_status' => null,
            'last_test_response' => null,
        ]);

        $this->activityLogService->log(
            'SMS_SETTINGS',
            'SMS Settings Updated',
            (string) $settings->id,
            'SMS Alert settings reset to defaults',
            $user->name ?? $user->email ?? 'System',
        );

        return $this->toPublicArray($settings->fresh());
    }

    public function ensureCanManageSettings(?User $user): void
    {
        if (! $this->canManageSettings($user)) {
            throw new AuthorizationException('Only Admin and Super Admin can manage SMS settings.');
        }
    }

    public function ensureCanViewSettings(?User $user): void
    {
        $role = $this->rbacService->roleKey($user);

        if (! in_array($role, ['admin', 'super_admin', 'manager'], true)) {
            throw new AuthorizationException('You do not have access to SMS settings.');
        }
    }

    public function canManageSettings(?User $user): bool
    {
        return in_array($this->rbacService->roleKey($user), ['admin', 'super_admin'], true);
    }

    /**
     * @throws ValidationException
     */
    public function assertSavePayloadValid(array $data): void
    {
        $validator = validator($data, [
            'provider_name' => ['required', 'string', 'max:120'],
            'api_url' => ['required', 'string', 'max:255', 'url'],
            'sender_id' => ['required', 'string', 'max:20'],
            'dlt_template_id' => ['nullable', 'string', 'max:30'],
            'mode' => ['required', 'string', 'in:'.SmsSetting::MODE_SIMULATION.','.SmsSetting::MODE_LIVE],
            'is_active' => ['sometimes', 'boolean'],
            'api_key' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $settings = $this->current();
        $hasKey = filled($data['api_key'] ?? null) || $settings->hasApiKey();

        if (! $hasKey) {
            throw ValidationException::withMessages([
                'api_key' => ['API Key is required.'],
            ]);
        }

        $mode = $data['mode'] ?? $settings->mode;
        $dltId = array_key_exists('dlt_template_id', $data)
            ? ($data['dlt_template_id'] !== '' ? $data['dlt_template_id'] : null)
            : $settings->dlt_template_id;

        if ($mode === SmsSetting::MODE_LIVE && ! filled($dltId)) {
            throw ValidationException::withMessages([
                'dlt_template_id' => [SmsAlertMappingService::ERROR_DLT_TEMPLATE_ID_REQUIRED],
            ]);
        }
    }
}
