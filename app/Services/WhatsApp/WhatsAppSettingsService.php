<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Services\Activity\ActivityLogService;
use App\Services\Rbac\RbacService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class WhatsAppSettingsService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly RbacService $rbacService,
    ) {}

    public function current(): WhatsAppSetting
    {
        $settings = WhatsAppSetting::query()->first();

        if ($settings) {
            return $this->applyEnvDefaultsToEmptyFields($settings);
        }

        return $this->createFromEnvOrDefaults();
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(?WhatsAppSetting $settings = null): array
    {
        $settings ??= $this->current();

        return [
            'provider_name' => $settings->provider_name,
            'phone_number_id' => $settings->phone_number_id,
            'business_account_id' => $settings->business_account_id,
            'api_version' => $settings->api_version,
            'mode' => $settings->mode,
            'is_active' => (bool) $settings->is_active,
            'has_access_token' => $settings->hasAccessToken(),
            'has_webhook_verify_token' => $settings->hasWebhookVerifyToken(),
            'is_configured' => $settings->isConfigured(),
            'integration_status' => $this->integrationStatus($settings),
            'last_tested_at' => $settings->last_tested_at?->toIso8601String(),
            'last_test_status' => $settings->last_test_status,
            'last_test_message' => $this->lastTestMessage($settings),
            'last_successful_send_at' => $settings->last_successful_send_at?->toIso8601String(),
            'test_mobile_number' => $settings->test_mobile_number,
            'can_edit' => $this->canManageSettings(auth()->user()),
            'messages_endpoint_pattern' => config('whatsapp_cloud.messages_endpoint_pattern'),
        ];
    }

    public function integrationStatus(?WhatsAppSetting $settings = null): string
    {
        $settings ??= $this->current();

        if (! $settings->is_active) {
            return WhatsAppSetting::INTEGRATION_DISABLED;
        }

        if (! $settings->isConfigured()) {
            return WhatsAppSetting::INTEGRATION_NOT_CONFIGURED;
        }

        $stored = (string) ($settings->integration_status ?? '');

        if (in_array($stored, [WhatsAppSetting::INTEGRATION_INTEGRATED, WhatsAppSetting::INTEGRATION_FAILED], true)) {
            return $stored;
        }

        return WhatsAppSetting::INTEGRATION_CONNECTED;
    }

    public function lastTestMessage(?WhatsAppSetting $settings = null): ?string
    {
        $settings ??= $this->current();

        if (! filled($settings->last_test_response)) {
            return null;
        }

        $decoded = json_decode((string) $settings->last_test_response, true);
        if (! is_array($decoded)) {
            return $settings->last_test_status === 'success'
                ? 'WhatsApp Cloud API connection test succeeded.'
                : null;
        }

        if (isset($decoded['error']['message'])) {
            return (string) $decoded['error']['message'];
        }

        if (isset($decoded['phone_number']['verified_name'])) {
            return 'Verified: '.$decoded['phone_number']['verified_name'];
        }

        return $settings->last_test_status === 'success'
            ? 'WhatsApp Cloud API connection test succeeded.'
            : (string) ($decoded['message'] ?? $decoded['error'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function update(array $data, User $user): array
    {
        $this->ensureCanManageSettings($user);

        $settings = $this->current();

        $payload = [
            'provider_name' => $data['provider_name'] ?? $settings->provider_name,
            'phone_number_id' => array_key_exists('phone_number_id', $data) ? $data['phone_number_id'] : $settings->phone_number_id,
            'business_account_id' => array_key_exists('business_account_id', $data) ? $data['business_account_id'] : $settings->business_account_id,
            'api_version' => $data['api_version'] ?? $settings->api_version,
            'mode' => $data['mode'] ?? $settings->mode,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $settings->is_active,
            'test_mobile_number' => array_key_exists('test_mobile_number', $data)
                ? $data['test_mobile_number']
                : $settings->test_mobile_number,
        ];

        if (array_key_exists('access_token', $data) && filled($data['access_token'])) {
            $payload['access_token'] = $data['access_token'];
        }

        if (array_key_exists('webhook_verify_token', $data) && filled($data['webhook_verify_token'])) {
            $payload['webhook_verify_token'] = $data['webhook_verify_token'];
        }

        $credentialChanged = array_key_exists('access_token', $payload)
            || array_key_exists('phone_number_id', $data)
            || array_key_exists('business_account_id', $data)
            || array_key_exists('api_version', $data);

        if ($credentialChanged && ! isset($payload['integration_status'])) {
            $payload['integration_status'] = WhatsAppSetting::INTEGRATION_CONNECTED;
        }

        $settings->update($payload);

        $this->activityLogService->log(
            'WHATSAPP_SETTINGS',
            'WhatsApp Settings Updated',
            (string) $settings->id,
            'Meta WhatsApp Cloud API settings updated',
            $user->name ?? $user->email ?? 'System',
        );

        return $this->toPublicArray($settings->fresh());
    }

    public function recordSuccessfulSend(?WhatsAppSetting $settings = null): void
    {
        ($settings ??= $this->current())->update([
            'last_successful_send_at' => now(),
        ]);
    }

    /**
     * Validate prerequisites without calling Meta API.
     *
     * @return array{valid: bool, errors: array<int, string>, settings: array<string, mixed>, dispatch: string}
     */
    public function validateConfiguration(?User $user = null): array
    {
        if ($user) {
            $this->ensureCanViewSettings($user);
        }

        $settings = $this->current();
        $errors = [];

        if (! filled($settings->phone_number_id)) {
            $errors[] = 'Phone Number ID is required.';
        }

        if (! filled($settings->business_account_id)) {
            $errors[] = 'Business Account ID is required.';
        }

        if (! $settings->hasAccessToken()) {
            $errors[] = 'Permanent Access Token is required.';
        }

        if (! filled($settings->api_version)) {
            $errors[] = 'API Version is required.';
        }

        if (! filled($settings->mode)) {
            $errors[] = 'Mode is required.';
        }

        if (! $settings->is_active) {
            $errors[] = 'WhatsApp provider is inactive.';
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
            'provider_name' => WhatsAppSetting::DEFAULT_PROVIDER,
            'phone_number_id' => null,
            'business_account_id' => null,
            'access_token' => null,
            'webhook_verify_token' => null,
            'api_version' => config('whatsapp_cloud.default_api_version', WhatsAppSetting::DEFAULT_API_VERSION),
            'mode' => WhatsAppSetting::MODE_SIMULATION,
            'is_active' => true,
            'integration_status' => WhatsAppSetting::INTEGRATION_NOT_CONFIGURED,
            'last_tested_at' => null,
            'last_test_status' => null,
            'last_test_response' => null,
            'last_successful_send_at' => null,
            'test_mobile_number' => null,
        ]);

        $this->activityLogService->log(
            'WHATSAPP_SETTINGS',
            'WhatsApp Settings Updated',
            (string) $settings->id,
            'WhatsApp Cloud API settings reset to defaults',
            $user->name ?? $user->email ?? 'System',
        );

        return $this->toPublicArray($settings->fresh());
    }

    public function ensureCanManageSettings(?User $user): void
    {
        if (! $this->canManageSettings($user)) {
            throw new AuthorizationException('Only Admin and Super Admin can manage WhatsApp settings.');
        }
    }

    public function ensureCanViewSettings(?User $user): void
    {
        $role = $this->rbacService->roleKey($user);

        if (! in_array($role, ['admin', 'super_admin', 'manager'], true)) {
            throw new AuthorizationException('You do not have access to WhatsApp settings.');
        }
    }

    public function canManageSettings(?User $user): bool
    {
        return in_array($this->rbacService->roleKey($user), ['admin', 'super_admin'], true);
    }

    public function assertReadyForLiveDispatch(?WhatsAppSetting $settings = null): void
    {
        $settings ??= $this->current();

        if (! $settings->is_active) {
            throw ValidationException::withMessages([
                'mode' => ['WhatsApp provider is inactive.'],
            ]);
        }

        if (! $settings->isLiveMode()) {
            throw ValidationException::withMessages([
                'mode' => ['WhatsApp provider must be in Live mode to send messages.'],
            ]);
        }

        if (! $settings->isConfigured()) {
            throw ValidationException::withMessages([
                'integration_status' => ['Configure Phone Number ID, Business Account ID, and Access Token before sending live messages.'],
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    public function assertSavePayloadValid(array $data): void
    {
        $validator = validator($data, [
            'provider_name' => ['required', 'string', 'max:120'],
            'phone_number_id' => ['nullable', 'string', 'max:64'],
            'business_account_id' => ['nullable', 'string', 'max:64'],
            'api_version' => ['required', 'string', 'max:20'],
            'mode' => ['required', 'string', 'in:'.WhatsAppSetting::MODE_SIMULATION.','.WhatsAppSetting::MODE_LIVE],
            'is_active' => ['sometimes', 'boolean'],
            'access_token' => ['nullable', 'string', 'max:2048'],
            'webhook_verify_token' => ['nullable', 'string', 'max:255'],
            'test_mobile_number' => ['nullable', 'string', 'max:20'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    private function createFromEnvOrDefaults(): WhatsAppSetting
    {
        $env = config('whatsapp_cloud.env_defaults', []);

        return WhatsAppSetting::create([
            'provider_name' => WhatsAppSetting::DEFAULT_PROVIDER,
            'phone_number_id' => $env['phone_number_id'] ?? null,
            'business_account_id' => $env['business_account_id'] ?? null,
            'access_token' => $env['access_token'] ?? null,
            'webhook_verify_token' => $env['webhook_verify_token'] ?? null,
            'api_version' => config('whatsapp_cloud.default_api_version', WhatsAppSetting::DEFAULT_API_VERSION),
            'mode' => WhatsAppSetting::MODE_SIMULATION,
            'is_active' => true,
            'integration_status' => $this->resolveInitialIntegrationStatus($env),
            'test_mobile_number' => $env['test_mobile_number'] ?? null,
        ]);
    }

  private function applyEnvDefaultsToEmptyFields(WhatsAppSetting $settings): WhatsAppSetting
    {
        $env = config('whatsapp_cloud.env_defaults', []);
        $updates = [];

        foreach ([
            'phone_number_id' => 'phone_number_id',
            'business_account_id' => 'business_account_id',
            'access_token' => 'access_token',
            'webhook_verify_token' => 'webhook_verify_token',
            'test_mobile_number' => 'test_mobile_number',
        ] as $field => $envKey) {
            if (! filled($settings->{$field}) && filled($env[$envKey] ?? null)) {
                $updates[$field] = $env[$envKey];
            }
        }

        if ($updates !== []) {
            if (($settings->integration_status ?? WhatsAppSetting::INTEGRATION_NOT_CONFIGURED) === WhatsAppSetting::INTEGRATION_NOT_CONFIGURED) {
                $updates['integration_status'] = $this->resolveInitialIntegrationStatus(
                    array_merge($env, $updates),
                );
            }
            $settings->update($updates);

            return $settings->fresh();
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $env
     */
    private function resolveInitialIntegrationStatus(array $env): string
    {
        if (filled($env['phone_number_id'] ?? null)
            && filled($env['business_account_id'] ?? null)
            && filled($env['access_token'] ?? null)) {
            return WhatsAppSetting::INTEGRATION_CONNECTED;
        }

        return WhatsAppSetting::INTEGRATION_NOT_CONFIGURED;
    }
}
