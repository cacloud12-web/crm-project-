<?php

namespace App\Services\Email;

use App\Models\EmailSetting;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use App\Services\Rbac\RbacService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EmailAccountService
{
    private const VERIFY_TTL_SECONDS = 1800;

    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly RbacService $rbacService,
        private readonly EmailSmtpConnectionService $smtpConnection,
        private readonly EmailImapConnectionService $imapConnection,
        private readonly EmailImapSyncService $imapSync,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return EmailSetting::query()
            ->orderByDesc('is_default')
            ->orderBy('from_email')
            ->get()
            ->map(fn (EmailSetting $account) => $this->toPublicArray($account))
            ->all();
    }

    public function find(int $id): EmailSetting
    {
        return EmailSetting::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function store(array $data, User $user): array
    {
        $this->ensureSuperAdmin($user);
        $this->assertUniqueEmail($data['from_email'] ?? null);
        $this->assertTestsPassed($data, $user, null);

        $smtpTest = $this->smtpConnection->test($this->smtpConfigFromData($data));
        if (! $smtpTest['success']) {
            throw ValidationException::withMessages(['smtp' => [$smtpTest['message']]]);
        }

        if (! empty($data['imap_enabled'])) {
            $imapTest = $this->imapConnection->test($this->imapConfigFromData($data));
            if (! $imapTest['success']) {
                throw ValidationException::withMessages(['imap' => [$imapTest['message']]]);
            }
        }

        $account = DB::transaction(function () use ($data) {
            $isDefault = (bool) ($data['is_default'] ?? false);
            if ($isDefault || EmailSetting::query()->count() === 0) {
                EmailSetting::query()->update(['is_default' => false]);
                $isDefault = true;
            }

            return EmailSetting::query()->create($this->payloadFromData($data, null, $isDefault));
        });

        $this->logAudit($user, 'Email account added', $account, $account->from_email);

        return $this->toPublicArray($account->fresh());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(EmailSetting $account, array $data, User $user): array
    {
        $this->ensureSuperAdmin($user);

        if (isset($data['from_email']) && strtolower($data['from_email']) !== strtolower((string) $account->from_email)) {
            $this->assertUniqueEmail($data['from_email'], $account->id);
        }

        $merged = array_merge($account->toArray(), $data);
        if (empty($data['smtp_password'])) {
            $merged['smtp_password'] = $account->smtp_password;
        }
        if (empty($data['imap_password'])) {
            $merged['imap_password'] = $account->imap_password;
        }

        $this->assertTestsPassed($data, $user, $account);

        $smtpTest = $this->smtpConnection->test($this->smtpConfigFromData($merged));
        if (! $smtpTest['success']) {
            throw ValidationException::withMessages(['smtp' => [$smtpTest['message']]]);
        }

        $imapEnabled = array_key_exists('imap_enabled', $data)
            ? (bool) $data['imap_enabled']
            : (bool) $account->imap_enabled;

        if ($imapEnabled) {
            $imapTest = $this->imapConnection->test($this->imapConfigFromData($merged));
            if (! $imapTest['success']) {
                throw ValidationException::withMessages(['imap' => [$imapTest['message']]]);
            }
        }

        $wasDefault = (bool) $account->is_default;
        $wasImap = (bool) $account->imap_enabled;

        DB::transaction(function () use ($account, $data, $merged) {
            if (! empty($data['is_default'])) {
                EmailSetting::query()->where('id', '!=', $account->id)->update(['is_default' => false]);
            }

            $account->update($this->payloadFromData($merged, $account, (bool) ($data['is_default'] ?? $account->is_default)));
        });

        $account = $account->fresh();
        $this->logAudit($user, 'Email account updated', $account, $account->from_email);

        if ($wasDefault !== (bool) $account->is_default && $account->is_default) {
            $this->logAudit($user, 'Default email account changed', $account, $account->from_email);
        }

        if ($wasImap !== (bool) $account->imap_enabled) {
            $this->logAudit(
                $user,
                $account->imap_enabled ? 'IMAP enabled' : 'IMAP disabled',
                $account,
                $account->from_email,
            );
        }

        return $this->toPublicArray($account);
    }

    public function destroy(EmailSetting $account, User $user): void
    {
        $this->ensureSuperAdmin($user);

        if ($account->is_default && EmailSetting::query()->where('id', '!=', $account->id)->exists()) {
            throw new InvalidArgumentException('Set another account as default before deleting the default account.');
        }

        $email = $account->from_email;
        $id = (string) $account->id;
        $account->delete();

        $this->activityLogService->log(
            'EMAIL_ACCOUNT',
            'Email account deleted',
            $id,
            $email,
            $user->name ?? $user->email ?? 'System',
        );
    }

    public function setDefault(EmailSetting $account, User $user): array
    {
        $this->ensureSuperAdmin($user);

        DB::transaction(function () use ($account) {
            EmailSetting::query()->update(['is_default' => false]);
            $account->update(['is_default' => true, 'is_active' => true]);
        });

        $account = $account->fresh();
        $this->logAudit($user, 'Default email account changed', $account, $account->from_email);

        return $this->toPublicArray($account);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{success: bool, message: string, verification_token: string}
     */
    public function testSmtp(array $data, User $user): array
    {
        $this->ensureSuperAdmin($user);

        $result = $this->smtpConnection->test($this->smtpConfigFromData($data));
        $fingerprint = $this->fingerprint($data, 'smtp');
        $token = $this->issueVerificationToken($user, 'smtp', $fingerprint, $result['success']);

        $this->activityLogService->log(
            'EMAIL_ACCOUNT',
            $result['success'] ? 'SMTP test passed' : 'SMTP test failed',
            null,
            ($data['from_email'] ?? 'unknown').' · '.$result['message'],
            $user->name ?? $user->email ?? 'System',
        );

        return array_merge($result, ['verification_token' => $token]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{success: bool, message: string, verification_token: string, inbox_count?: int}
     */
    public function testImap(array $data, User $user): array
    {
        $this->ensureSuperAdmin($user);

        $result = $this->imapConnection->test($this->imapConfigFromData($data));
        $fingerprint = $this->fingerprint($data, 'imap');
        $token = $this->issueVerificationToken($user, 'imap', $fingerprint, $result['success']);

        $this->activityLogService->log(
            'EMAIL_ACCOUNT',
            $result['success'] ? 'IMAP test passed' : 'IMAP test failed',
            null,
            ($data['from_email'] ?? 'unknown').' · '.$result['message'],
            $user->name ?? $user->email ?? 'System',
        );

        return array_merge($result, ['verification_token' => $token]);
    }

    /**
     * @return array<string, mixed>
     */
    public function syncImap(EmailSetting $account, User $user): array
    {
        $this->ensureSuperAdmin($user);

        $result = $this->imapSync->syncAccount($account);

        $this->activityLogService->log(
            'EMAIL_ACCOUNT',
            'IMAP sync completed',
            (string) $account->id,
            $account->from_email.' · '.$result['message'],
            $user->name ?? $user->email ?? 'System',
        );

        return array_merge($result, ['account' => $this->toPublicArray($account->fresh())]);
    }

    public function ensureSuperAdmin(?User $user): void
    {
        if ($this->rbacService->roleKey($user) !== 'super_admin') {
            throw new AuthorizationException('Only Super Admin can manage email accounts.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(EmailSetting $account): array
    {
        return [
            'id' => $account->id,
            'from_email' => $account->from_email,
            'display_name' => $account->resolvedDisplayName(),
            'from_name' => $account->from_name,
            'reply_to_email' => $account->reply_to_email,
            'provider_name' => $account->provider_name,
            'smtp_host' => $account->smtp_host,
            'smtp_port' => $account->smtp_port,
            'smtp_username' => $account->smtp_username,
            'smtp_encryption' => $account->smtp_encryption,
            'imap_host' => $account->imap_host,
            'imap_port' => $account->imap_port,
            'imap_encryption' => $account->imap_encryption,
            'imap_username' => $account->imap_username,
            'imap_enabled' => (bool) $account->imap_enabled,
            'mode' => $account->mode,
            'is_active' => (bool) $account->is_active,
            'is_default' => (bool) $account->is_default,
            'has_smtp_password' => $account->hasPassword(),
            'has_imap_password' => $account->hasImapPassword(),
            'is_configured' => $account->isConfigured(),
            'is_imap_configured' => $account->isImapConfigured(),
            'smtp_last_tested_at' => $account->smtp_last_tested_at,
            'smtp_last_test_status' => $account->smtp_last_test_status,
            'smtp_last_test_response' => $account->smtp_last_test_response,
            'imap_last_tested_at' => $account->imap_last_tested_at,
            'imap_last_test_status' => $account->imap_last_test_status,
            'imap_last_test_response' => $account->imap_last_test_response,
            'last_imap_sync_at' => $account->last_imap_sync_at,
            'connection_status' => $this->connectionStatus($account),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertTestsPassed(array $data, User $user, ?EmailSetting $existing): void
    {
        $smtpToken = $data['smtp_verification_token'] ?? null;
        $imapToken = $data['imap_verification_token'] ?? null;
        $smtpFp = $this->fingerprint(array_merge($existing?->toArray() ?? [], $data), 'smtp');

        if (! $this->consumeVerificationToken($user, 'smtp', (string) $smtpToken, $smtpFp)) {
            throw ValidationException::withMessages([
                'smtp_verification_token' => ['Run Test SMTP Connection successfully before saving.'],
            ]);
        }

        $imapEnabled = array_key_exists('imap_enabled', $data)
            ? (bool) $data['imap_enabled']
            : (bool) ($existing?->imap_enabled ?? false);

        if ($imapEnabled) {
            $imapFp = $this->fingerprint(array_merge($existing?->toArray() ?? [], $data), 'imap');
            if (! $this->consumeVerificationToken($user, 'imap', (string) $imapToken, $imapFp)) {
                throw ValidationException::withMessages([
                    'imap_verification_token' => ['Run Test IMAP Connection successfully before saving.'],
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function payloadFromData(array $data, ?EmailSetting $existing, bool $isDefault): array
    {
        $payload = [
            'provider_name' => $data['provider_name'] ?? $existing?->provider_name ?? EmailSetting::DEFAULT_PROVIDER,
            'from_email' => $data['from_email'] ?? $existing?->from_email,
            'from_name' => $data['from_name'] ?? $data['display_name'] ?? $existing?->from_name,
            'display_name' => $data['display_name'] ?? $data['from_name'] ?? $existing?->display_name,
            'reply_to_email' => $data['reply_to_email'] ?? $data['from_email'] ?? $existing?->reply_to_email,
            'smtp_host' => $data['smtp_host'] ?? $existing?->smtp_host,
            'smtp_port' => (int) ($data['smtp_port'] ?? $existing?->smtp_port ?? 465),
            'smtp_username' => $data['smtp_username'] ?? $existing?->smtp_username,
            'smtp_encryption' => $data['smtp_encryption'] ?? $existing?->smtp_encryption ?? 'ssl',
            'imap_host' => $data['imap_host'] ?? $existing?->imap_host,
            'imap_port' => isset($data['imap_port']) ? (int) $data['imap_port'] : $existing?->imap_port,
            'imap_encryption' => $data['imap_encryption'] ?? $existing?->imap_encryption,
            'imap_username' => $data['imap_username'] ?? $existing?->imap_username,
            'imap_enabled' => (bool) ($data['imap_enabled'] ?? $existing?->imap_enabled ?? false),
            'mode' => $data['mode'] ?? $existing?->mode ?? EmailSetting::MODE_LIVE,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($existing?->is_active ?? true),
            'is_default' => $isDefault,
            'smtp_last_tested_at' => now(),
            'smtp_last_test_status' => 'success',
            'smtp_last_test_response' => 'Verified before save',
        ];

        if (! empty($data['smtp_password'])) {
            $payload['smtp_password'] = $data['smtp_password'];
        } elseif ($existing) {
            $payload['smtp_password'] = $existing->smtp_password;
        }

        if (! empty($data['imap_password'])) {
            $payload['imap_password'] = $data['imap_password'];
        } elseif ($existing) {
            $payload['imap_password'] = $existing->imap_password;
        }

        if ($payload['imap_enabled']) {
            $payload['imap_last_tested_at'] = now();
            $payload['imap_last_test_status'] = 'success';
            $payload['imap_last_test_response'] = 'Verified before save';
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function smtpConfigFromData(array $data): array
    {
        return $this->smtpConnection->normalizeConfig([
            'smtp_host' => $data['smtp_host'] ?? null,
            'smtp_port' => $data['smtp_port'] ?? null,
            'smtp_username' => $data['smtp_username'] ?? null,
            'smtp_password' => $data['smtp_password'] ?? null,
            'smtp_encryption' => $data['smtp_encryption'] ?? null,
            'from_email' => $data['from_email'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function imapConfigFromData(array $data): array
    {
        return $this->imapConnection->normalizeConfig([
            'imap_host' => $data['imap_host'] ?? null,
            'imap_port' => $data['imap_port'] ?? 993,
            'imap_username' => $data['imap_username'] ?? null,
            'imap_password' => $data['imap_password'] ?? null,
            'imap_encryption' => $data['imap_encryption'] ?? 'ssl',
            'from_email' => $data['from_email'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fingerprint(array $data, string $type): string
    {
        $keys = $type === 'smtp'
            ? ['from_email', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption']
            : ['from_email', 'imap_host', 'imap_port', 'imap_username', 'imap_password', 'imap_encryption'];

        $parts = [];
        foreach ($keys as $key) {
            $parts[] = (string) ($data[$key] ?? '');
        }

        return hash('sha256', $type.'|'.implode('|', $parts));
    }

    private function issueVerificationToken(User $user, string $type, string $fingerprint, bool $success): string
    {
        $token = bin2hex(random_bytes(20));
        if ($success) {
            Cache::put($this->cacheKey($user->id, $type, $token), $fingerprint, self::VERIFY_TTL_SECONDS);
        }

        return $token;
    }

    private function consumeVerificationToken(User $user, string $type, string $token, string $fingerprint): bool
    {
        if ($token === '') {
            return false;
        }

        $cached = Cache::pull($this->cacheKey($user->id, $type, $token));

        return is_string($cached) && hash_equals($cached, $fingerprint);
    }

    private function cacheKey(int $userId, string $type, string $token): string
    {
        return "email_account_verify:{$userId}:{$type}:{$token}";
    }

    private function assertUniqueEmail(?string $email, ?int $ignoreId = null): void
    {
        if (! filled($email)) {
            throw ValidationException::withMessages(['from_email' => ['Email address is required.']]);
        }

        $query = EmailSetting::query()->where('from_email', $email);
        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['from_email' => ['This email account already exists.']]);
        }
    }

    private function connectionStatus(EmailSetting $account): string
    {
        if (! $account->is_active) {
            return 'inactive';
        }

        if ($account->smtp_last_test_status === 'success') {
            return $account->imap_enabled && $account->imap_last_test_status !== 'success'
                ? 'smtp_only'
                : 'connected';
        }

        return 'untested';
    }

    private function logAudit(User $user, string $action, EmailSetting $account, string $detail): void
    {
        $this->activityLogService->log(
            'EMAIL_ACCOUNT',
            $action,
            (string) $account->id,
            $detail,
            $user->name ?? $user->email ?? 'System',
        );
    }
}
