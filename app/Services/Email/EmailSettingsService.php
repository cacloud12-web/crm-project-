<?php

namespace App\Services\Email;

use App\Models\EmailSetting;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use App\Services\Rbac\RbacService;
use Illuminate\Auth\Access\AuthorizationException;

class EmailSettingsService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly RbacService $rbacService,
    ) {}

    public function current(): EmailSetting
    {
        $settings = EmailSetting::query()->first();

        if ($settings) {
            return $settings;
        }

        return EmailSetting::create([
            'provider_name' => EmailSetting::DEFAULT_PROVIDER,
            'smtp_host' => EmailSetting::DEFAULT_SMTP_HOST,
            'smtp_port' => EmailSetting::DEFAULT_SMTP_PORT,
            'smtp_username' => null,
            'smtp_password' => null,
            'smtp_encryption' => EmailSetting::DEFAULT_ENCRYPTION,
            'from_email' => null,
            'from_name' => null,
            'mode' => EmailSetting::MODE_SIMULATION,
        ]);
    }

    /**
     * Public API shape — SMTP password is never exposed.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(?EmailSetting $settings = null): array
    {
        $settings ??= $this->current();

        return [
            'provider_name' => $settings->provider_name,
            'smtp_host' => $settings->smtp_host,
            'smtp_port' => $settings->smtp_port,
            'smtp_username' => $settings->smtp_username,
            'smtp_encryption' => $settings->smtp_encryption,
            'from_email' => $settings->from_email,
            'from_name' => $settings->from_name,
            'mode' => $settings->mode,
            'has_smtp_password' => $settings->hasPassword(),
            'is_configured' => $settings->isConfigured(),
        ];
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
            'smtp_host' => $data['smtp_host'] ?? $settings->smtp_host,
            'smtp_port' => array_key_exists('smtp_port', $data) ? $data['smtp_port'] : $settings->smtp_port,
            'smtp_username' => array_key_exists('smtp_username', $data) ? $data['smtp_username'] : $settings->smtp_username,
            'smtp_encryption' => $data['smtp_encryption'] ?? $settings->smtp_encryption,
            'from_email' => array_key_exists('from_email', $data) ? $data['from_email'] : $settings->from_email,
            'from_name' => array_key_exists('from_name', $data) ? $data['from_name'] : $settings->from_name,
            'mode' => $data['mode'] ?? $settings->mode,
        ];

        if (array_key_exists('smtp_password', $data) && filled($data['smtp_password'])) {
            $payload['smtp_password'] = $data['smtp_password'];
        }

        $settings->update($payload);

        $this->activityLogService->log(
            'EMAIL_SETTINGS',
            'Email Settings Updated',
            (string) $settings->id,
            'GoDaddy SMTP mapping settings updated',
            $user->name ?? $user->email ?? 'System',
        );

        return $this->toPublicArray($settings->fresh());
    }

    public function ensureCanManageSettings(?User $user): void
    {
        $role = $this->rbacService->roleKey($user);

        if (! in_array($role, ['admin', 'super_admin'], true)) {
            throw new AuthorizationException('Only Admin and Super Admin can manage email settings.');
        }
    }

    public function ensureCanViewSettings(?User $user): void
    {
        $this->ensureCanManageSettings($user);
    }
}
