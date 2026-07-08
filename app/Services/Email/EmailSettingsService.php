<?php

namespace App\Services\Email;

use App\Models\EmailLog;
use App\Models\EmailSetting;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use App\Services\Rbac\RbacService;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class EmailSettingsService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly RbacService $rbacService,
    ) {}

    public function current(): EmailSetting
    {
        $settings = EmailSetting::query()
            ->where('is_default', true)
            ->orderBy('id')
            ->first();

        if ($settings) {
            return $this->normalizeSmtpIdentity($this->hydratePasswordFromEnvIfMissing($settings));
        }

        return $this->createDefaultFromEnv();
    }

    /**
     * Resolve the default SMTP account for transactional/system emails.
     * Merges missing credentials from env defaults without persisting changes.
     */
    public function resolveForSystemMail(): EmailSetting
    {
        return $this->hydrateFromEnvIfIncomplete($this->current());
    }

    public function resolve(?int $settingId = null): EmailSetting
    {
        if ($settingId) {
            $settings = EmailSetting::query()->where('id', $settingId)->where('is_active', true)->firstOrFail();

            return $this->normalizeSmtpIdentity($this->hydratePasswordFromEnvIfMissing($settings));
        }

        return $this->current();
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(?EmailSetting $settings = null): array
    {
        $settings ??= $this->current();

        return [
            'id' => $settings->id,
            'provider_name' => $settings->provider_name,
            'smtp_host' => $settings->smtp_host,
            'smtp_port' => $settings->smtp_port,
            'smtp_username' => $settings->smtp_username,
            'smtp_encryption' => $settings->smtp_encryption,
            'from_email' => $settings->from_email,
            'from_name' => $settings->from_name,
            'reply_to_email' => $settings->reply_to_email,
            'mode' => $settings->mode,
            'is_active' => (bool) $settings->is_active,
            'is_default' => (bool) $settings->is_default,
            'has_smtp_password' => $settings->hasPassword(),
            'is_configured' => $settings->isConfigured(),
            'last_tested_at' => $settings->last_tested_at,
            'last_test_status' => $settings->last_test_status,
            'last_test_response' => $settings->last_test_response,
        ];
    }

    /**
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validateConfiguration(?EmailSetting $settings = null): array
    {
        $settings ??= $this->current();
        $errors = [];

        if (! filled($settings->smtp_host)) {
            $errors[] = 'SMTP host is not configured.';
        }
        if (! filled($settings->smtp_port)) {
            $errors[] = 'SMTP port is not configured.';
        }
        if (! filled($settings->smtp_username)) {
            $errors[] = 'SMTP username is not configured.';
        }
        if (! $settings->hasPassword()) {
            $errors[] = 'SMTP password is not configured.';
        }
        if (! filled($settings->smtp_encryption)) {
            $errors[] = 'SMTP encryption is not configured.';
        }
        if (! filled($settings->from_email)) {
            $errors[] = 'From email address is not configured.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'settings' => $this->toPublicArray($settings),
        ];
    }

    /**
     * @return array{success: bool, message: string, settings: array<string, mixed>, log_id: ?int}
     */
    public function sendTestEmail(string $recipientEmail, User $user): array
    {
        $this->ensureCanManageSettings($user);

        $settings = $this->current();

        if (! $settings->isLiveMode()) {
            throw new InvalidArgumentException('Switch email mode to Live before sending a test email.');
        }

        $validation = $this->validateConfiguration($settings);
        if (! $validation['valid']) {
            throw new InvalidArgumentException(implode(' ', $validation['errors']));
        }

        $subject = 'CA Cloud Desk — SMTP Test Email';
        $body = "Hello,\n\nThis is a test email from CA Cloud Desk CRM.\n\nProvider: {$settings->provider_name}\nHost: {$settings->smtp_host}\n\nIf you received this message, SMTP is configured correctly.";

        $result = app(EmailSmtpDispatchService::class)->send($settings, $recipientEmail, $subject, $body);

        $log = EmailLog::query()->create([
            'campaign_id' => null,
            'email_setting_id' => $settings->id,
            'recipient_email' => $recipientEmail,
            'subject' => $subject,
            'body' => $body,
            'is_html' => true,
            'email_status' => $result['status'],
            'provider_response' => json_encode($result['provider_response']),
            'error_message' => $result['error_message'],
            'failed_reason' => $result['success'] ? null : $result['error_message'],
            'queued_at' => now(),
            'sent_at' => now(),
            'delivered_at' => $result['success'] ? now() : null,
        ]);

        $settings->update([
            'last_tested_at' => now(),
            'last_test_status' => $result['success'] ? 'success' : 'failed',
            'last_test_response' => $result['success']
                ? 'Test email sent successfully.'
                : ($result['error_message'] ?? 'Test email failed.'),
        ]);

        $this->activityLogService->log(
            'EMAIL_SETTINGS',
            $result['success'] ? 'Test Email Sent' : 'Test Email Failed',
            (string) $settings->id,
            $recipientEmail.' · '.($result['error_message'] ?? 'success'),
            $user->name ?? $user->email ?? 'System',
        );

        return [
            'success' => $result['success'],
            'message' => $result['success']
                ? 'Test email sent successfully to '.$recipientEmail.'.'
                : ($result['error_message'] ?? 'Failed to send test email.'),
            'settings' => $this->toPublicArray($settings->fresh()),
            'log_id' => $log->id,
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
            'reply_to_email' => array_key_exists('reply_to_email', $data) ? $data['reply_to_email'] : $settings->reply_to_email,
            'mode' => $data['mode'] ?? $settings->mode,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $settings->is_active,
        ];

        if (array_key_exists('smtp_password', $data) && filled($data['smtp_password'])) {
            $payload['smtp_password'] = $data['smtp_password'];
        }

        $settings->update($payload);

        $this->activityLogService->log(
            'EMAIL_SETTINGS',
            'Email Settings Updated',
            (string) $settings->id,
            ($settings->provider_name ?? 'SMTP').' settings updated',
            $user->name ?? $user->email ?? 'System',
        );

        return $this->toPublicArray($settings->fresh());
    }

    public function assertReadyForLiveDispatch(?EmailSetting $settings = null): void
    {
        $settings ??= $this->current();

        if (! $settings->isLiveMode()) {
            throw new InvalidArgumentException('Email mode is not set to Live.');
        }

        $validation = $this->validateConfiguration($settings);
        if (! $validation['valid']) {
            throw new InvalidArgumentException(implode(' ', $validation['errors']));
        }
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

    private function hydratePasswordFromEnvIfMissing(EmailSetting $settings): EmailSetting
    {
        if ($settings->hasPassword()) {
            return $settings;
        }

        $envPassword = config('email_smtp.env_defaults.smtp_password');
        if (filled($envPassword)) {
            $settings->smtp_password = $envPassword;
            $settings->save();
        }

        return $settings->fresh();
    }

    private function normalizeSmtpIdentity(EmailSetting $settings): EmailSetting
    {
        $normalized = app(EmailSmtpConnectionService::class)->normalizeConfig([
            'smtp_host' => $settings->smtp_host,
            'smtp_port' => $settings->smtp_port,
            'smtp_username' => $settings->smtp_username,
            'smtp_password' => $settings->smtp_password,
            'smtp_encryption' => $settings->smtp_encryption,
            'from_email' => $settings->from_email,
        ]);

        $updates = [];
        if (($normalized['smtp_username'] ?? '') !== ($settings->smtp_username ?? '')) {
            $updates['smtp_username'] = $normalized['smtp_username'];
        }
        if (($normalized['smtp_host'] ?? '') !== ($settings->smtp_host ?? '')) {
            $updates['smtp_host'] = $normalized['smtp_host'];
        }
        if ((int) ($normalized['smtp_port'] ?? 0) !== (int) $settings->smtp_port) {
            $updates['smtp_port'] = $normalized['smtp_port'];
        }

        if ($updates !== []) {
            $settings->update($updates);

            return $settings->fresh();
        }

        return $settings;
    }

    private function createDefaultFromEnv(): EmailSetting
    {
        $defaults = (array) config('email_smtp.env_defaults', []);

        return EmailSetting::create([
            'provider_name' => $defaults['provider_name'] ?? EmailSetting::DEFAULT_PROVIDER,
            'smtp_host' => $defaults['smtp_host'] ?? EmailSetting::DEFAULT_SMTP_HOST,
            'smtp_port' => (int) ($defaults['smtp_port'] ?? EmailSetting::DEFAULT_SMTP_PORT),
            'smtp_username' => $defaults['smtp_username'] ?? null,
            'smtp_password' => $defaults['smtp_password'] ?? null,
            'smtp_encryption' => $defaults['smtp_encryption'] ?? EmailSetting::DEFAULT_ENCRYPTION,
            'from_email' => $defaults['from_email'] ?? null,
            'from_name' => $defaults['from_name'] ?? null,
            'reply_to_email' => $defaults['reply_to_email'] ?? null,
            'mode' => $defaults['mode'] ?? EmailSetting::MODE_LIVE,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    private function hydrateFromEnvIfIncomplete(EmailSetting $settings): EmailSetting
    {
        $defaults = (array) config('email_smtp.env_defaults', []);
        $hydrated = $settings->replicate();

        foreach ([
            'provider_name',
            'smtp_host',
            'smtp_port',
            'smtp_username',
            'smtp_encryption',
            'from_email',
            'from_name',
            'reply_to_email',
            'mode',
        ] as $field) {
            if (! filled($hydrated->{$field}) && filled($defaults[$field] ?? null)) {
                $hydrated->{$field} = $defaults[$field];
            }
        }

        if (! $hydrated->hasPassword() && filled($defaults['smtp_password'] ?? null)) {
            $hydrated->smtp_password = $defaults['smtp_password'];
        }

        return $hydrated;
    }
}
