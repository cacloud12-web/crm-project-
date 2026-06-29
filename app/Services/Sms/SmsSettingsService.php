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
            'mode' => $settings->mode,
            'is_active' => (bool) $settings->is_active,
            'has_api_key' => $settings->hasApiKey(),
            'is_configured' => $settings->isConfigured(),
            'integration_status' => $this->integrationStatus($settings),
            'can_edit' => $this->canManageSettings(auth()->user()),
        ];
    }

    public function integrationStatus(?SmsSetting $settings = null): string
    {
        $settings ??= $this->current();

        if (! $settings->is_active) {
            return 'disabled';
        }

        if ($settings->hasApiKey() && filled($settings->sender_id)) {
            return 'connected';
        }

        return 'not_configured';
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
            'mode' => $data['mode'] ?? $settings->mode,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $settings->is_active,
        ];

        if (array_key_exists('api_key', $data) && filled($data['api_key'])) {
            $payload['api_key'] = $data['api_key'];
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
            'mode' => SmsSetting::MODE_SIMULATION,
            'is_active' => true,
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
    }
}
