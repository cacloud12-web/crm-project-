<?php

namespace App\Services\Auth;

use App\Models\LoginEmailChangeRequest;
use App\Models\User;
use App\Rules\ValidLoginEmailAddress;
use App\Services\Activity\ActivityLogService;
use App\Services\Email\SystemEmailService;
use App\Services\Rbac\RbacService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class LoginEmailChangeService
{
    public function __construct(
        private readonly RbacService $rbacService,
        private readonly ActivityLogService $activityLogService,
        private readonly SystemEmailService $systemEmailService,
    ) {}

    public function assertSuperAdmin(?User $user): void
    {
        if ($this->rbacService->roleKey($user) !== 'super_admin') {
            abort(403, 'Only Super Admin can change the login email.');
        }
    }

    /** @return array<string, mixed> */
    public function status(User $user): array
    {
        $this->assertSuperAdmin($user);
        $this->expireStaleRequests($user);

        $pending = $this->activePendingRequestFor($user);
        $latest = $this->latestRequestFor($user);

        $verificationStatus = LoginEmailChangeRequest::STATUS_VERIFIED;
        if ($pending) {
            $verificationStatus = LoginEmailChangeRequest::STATUS_PENDING;
        } elseif ($latest && in_array($latest->status, [
            LoginEmailChangeRequest::STATUS_EXPIRED,
            LoginEmailChangeRequest::STATUS_FAILED,
            LoginEmailChangeRequest::STATUS_CANCELLED,
        ], true)) {
            $verificationStatus = $latest->status;
        }

        return [
            'current_email' => $user->email,
            'verification_status' => $verificationStatus,
            'pending_verification' => $pending ? [
                'id' => $pending->id,
                'new_email' => $pending->new_email,
                'status' => $pending->status,
                'requested_at' => $pending->created_at?->toIso8601String(),
                'expires_at' => $pending->expires_at?->toIso8601String(),
            ] : null,
            'last_request' => $latest ? [
                'new_email' => $latest->new_email,
                'status' => $latest->status,
                'requested_at' => $latest->created_at?->toIso8601String(),
                'expires_at' => $latest->expires_at?->toIso8601String(),
            ] : null,
            'last_changed_at' => LoginEmailChangeRequest::query()
                ->where('user_id', $user->id)
                ->where('status', LoginEmailChangeRequest::STATUS_VERIFIED)
                ->whereNotNull('verified_at')
                ->latest('verified_at')
                ->value('verified_at')?->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function history(User $user): array
    {
        $this->assertSuperAdmin($user);

        return LoginEmailChangeRequest::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(fn (LoginEmailChangeRequest $request) => [
                'old_email' => $request->old_email,
                'new_email' => $request->new_email,
                'changed_by' => $user->name,
                'date' => ($request->verified_at ?? $request->created_at)?->toIso8601String(),
                'status' => $request->status,
                'ip_address' => $request->requested_ip,
            ])
            ->all();
    }

    public function requestChange(
        User $user,
        string $newEmail,
        string $currentPassword,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): LoginEmailChangeRequest {
        $this->assertSuperAdmin($user);
        $this->expireStaleRequests($user);

        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $normalizedEmail = strtolower(trim($newEmail));
        $this->assertDeliverableEmail($normalizedEmail);

        if (strcasecmp($normalizedEmail, (string) $user->email) === 0) {
            throw ValidationException::withMessages([
                'new_email' => ['The new email must be different from your current login email.'],
            ]);
        }

        if (User::query()->where('email', $normalizedEmail)->exists()) {
            throw ValidationException::withMessages([
                'new_email' => ['This email address is already in use.'],
            ]);
        }

        if ($this->activePendingRequestFor($user)) {
            throw ValidationException::withMessages([
                'new_email' => ['A verification request is already pending. Resend the email or cancel it before starting a new change.'],
            ]);
        }

        $plainToken = Str::random(64);
        $expiresAt = now()->addHours((int) config('login_email_change.token_expiry_hours', 24));

        $this->cancelSupersededPendingRequests($user);

        $request = LoginEmailChangeRequest::query()->create([
            'user_id' => $user->id,
            'old_email' => $user->email,
            'new_email' => $normalizedEmail,
            'status' => LoginEmailChangeRequest::STATUS_PENDING,
            'token_hash' => $this->hashToken($plainToken),
            'expires_at' => $expiresAt,
            'requested_ip' => $ipAddress,
            'requested_user_agent' => $userAgent,
        ]);

        try {
            $this->sendVerificationEmail($request, $plainToken);
        } catch (ValidationException $exception) {
            $request->delete();

            throw $exception;
        } catch (Throwable $exception) {
            $request->delete();

            Log::error('Login email verification send failed', [
                'user_id' => $user->id,
                'new_email' => $normalizedEmail,
                'message' => $exception->getMessage(),
            ]);

            $this->logEmailChangeAudit(
                $user,
                $user->email,
                $normalizedEmail,
                LoginEmailChangeRequest::STATUS_FAILED,
                $ipAddress,
                $userAgent,
                'Verification email could not be sent.',
            );

            throw ValidationException::withMessages([
                'new_email' => ['Unable to send the verification email. Please check the email address and try again later.'],
            ]);
        }

        $this->logEmailChangeAudit(
            $user,
            $user->email,
            $normalizedEmail,
            LoginEmailChangeRequest::STATUS_PENDING,
            $ipAddress,
            $userAgent,
            'Verification email sent to '.$normalizedEmail,
        );

        return $request;
    }

    public function resendVerification(
        User $user,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): LoginEmailChangeRequest {
        $this->assertSuperAdmin($user);
        $this->expireStaleRequests($user);

        $request = $this->activePendingRequestFor($user);

        if (! $request) {
            throw ValidationException::withMessages([
                'request' => ['No pending verification request was found. Please submit a new login email change.'],
            ]);
        }

        $plainToken = Str::random(64);
        $expiresAt = now()->addHours((int) config('login_email_change.token_expiry_hours', 24));

        $request->update([
            'token_hash' => $this->hashToken($plainToken),
            'expires_at' => $expiresAt,
            'requested_ip' => $ipAddress,
            'requested_user_agent' => $userAgent,
        ]);

        try {
            $this->sendVerificationEmail($request->fresh(), $plainToken);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Login email verification resend failed', [
                'user_id' => $user->id,
                'request_id' => $request->id,
                'message' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'request' => ['Unable to resend the verification email. Please try again later.'],
            ]);
        }

        $this->logEmailChangeAudit(
            $user,
            $request->old_email,
            $request->new_email,
            LoginEmailChangeRequest::STATUS_PENDING,
            $ipAddress,
            $userAgent,
            'Verification email resent to '.$request->new_email,
        );

        return $request->fresh();
    }

    public function cancelPending(User $user, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        $this->assertSuperAdmin($user);
        $this->expireStaleRequests($user);

        $request = $this->activePendingRequestFor($user);

        if (! $request) {
            throw ValidationException::withMessages([
                'request' => ['No pending verification request was found.'],
            ]);
        }

        $request->update(['status' => LoginEmailChangeRequest::STATUS_CANCELLED]);

        $this->logEmailChangeAudit(
            $user,
            $request->old_email,
            $request->new_email,
            LoginEmailChangeRequest::STATUS_CANCELLED,
            $ipAddress,
            $userAgent,
            'Pending login email change cancelled.',
        );
    }

    public function verify(string $plainToken, ?string $ipAddress = null, ?string $userAgent = null): User
    {
        $request = LoginEmailChangeRequest::query()
            ->where('token_hash', $this->hashToken($plainToken))
            ->first();

        if (! $request) {
            throw ValidationException::withMessages([
                'token' => ['This verification link is invalid or has already been used.'],
            ]);
        }

        if ($request->verified_at !== null || $request->status === LoginEmailChangeRequest::STATUS_VERIFIED) {
            throw ValidationException::withMessages([
                'token' => ['This verification link has already been used.'],
            ]);
        }

        if ($request->status === LoginEmailChangeRequest::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'token' => ['This verification request was cancelled. Please start a new login email change.'],
            ]);
        }

        if ($request->expires_at->isPast() || $request->status === LoginEmailChangeRequest::STATUS_EXPIRED) {
            $request->update(['status' => LoginEmailChangeRequest::STATUS_EXPIRED]);

            throw ValidationException::withMessages([
                'token' => ['This verification link has expired. Please request a new login email change.'],
            ]);
        }

        if (! ValidLoginEmailAddress::isDeliverable($request->new_email)) {
            $request->update(['status' => LoginEmailChangeRequest::STATUS_FAILED]);

            throw ValidationException::withMessages([
                'token' => ['This email address is no longer valid. Please request a new login email change.'],
            ]);
        }

        if (User::query()->where('email', $request->new_email)->where('id', '!=', $request->user_id)->exists()) {
            $request->update(['status' => LoginEmailChangeRequest::STATUS_FAILED]);

            throw ValidationException::withMessages([
                'token' => ['This email address is no longer available. Please request a new login email change.'],
            ]);
        }

        $user = User::query()->findOrFail($request->user_id);
        $oldEmail = (string) $user->email;
        $newEmail = (string) $request->new_email;

        DB::transaction(function () use ($user, $request, $newEmail) {
            $user->update([
                'email' => $newEmail,
                'email_verified_at' => now(),
            ]);

            $request->update([
                'verified_at' => now(),
                'status' => LoginEmailChangeRequest::STATUS_VERIFIED,
            ]);

            LoginEmailChangeRequest::query()
                ->where('user_id', $user->id)
                ->where('status', LoginEmailChangeRequest::STATUS_PENDING)
                ->whereKeyNot($request->id)
                ->update(['status' => LoginEmailChangeRequest::STATUS_CANCELLED]);
        });

        $this->logEmailChangeAudit(
            $user,
            $oldEmail,
            $newEmail,
            LoginEmailChangeRequest::STATUS_VERIFIED,
            $ipAddress ?? $request->requested_ip,
            $userAgent ?? $request->requested_user_agent,
            'Login email changed from '.$oldEmail.' to '.$newEmail,
        );

        try {
            $this->sendCompletionNotifications($user, $oldEmail, $newEmail);
        } catch (Throwable $exception) {
            Log::warning('Login email change completion notifications failed', [
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
            ]);
        }

        return $user->fresh();
    }

    public function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    private function assertDeliverableEmail(string $email): void
    {
        if (! ValidLoginEmailAddress::isDeliverable($email)) {
            throw ValidationException::withMessages([
                'new_email' => ['Please enter a valid email address.'],
            ]);
        }
    }

    private function expireStaleRequests(User $user): void
    {
        LoginEmailChangeRequest::query()
            ->where('user_id', $user->id)
            ->where('status', LoginEmailChangeRequest::STATUS_PENDING)
            ->whereNull('verified_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => LoginEmailChangeRequest::STATUS_EXPIRED]);
    }

    private function cancelSupersededPendingRequests(User $user): void
    {
        LoginEmailChangeRequest::query()
            ->where('user_id', $user->id)
            ->where('status', LoginEmailChangeRequest::STATUS_PENDING)
            ->whereNull('verified_at')
            ->update(['status' => LoginEmailChangeRequest::STATUS_CANCELLED]);
    }

    private function activePendingRequestFor(User $user): ?LoginEmailChangeRequest
    {
        return LoginEmailChangeRequest::query()
            ->where('user_id', $user->id)
            ->where('status', LoginEmailChangeRequest::STATUS_PENDING)
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }

    private function latestRequestFor(User $user): ?LoginEmailChangeRequest
    {
        return LoginEmailChangeRequest::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();
    }

    private function sendVerificationEmail(LoginEmailChangeRequest $request, string $plainToken): void
    {
        if (! ValidLoginEmailAddress::isDeliverable($request->new_email)) {
            throw ValidationException::withMessages([
                'new_email' => ['Please enter a valid email address.'],
            ]);
        }

        $verifyUrl = url(route('auth.verify-login-email', ['token' => $plainToken]));
        $appName = config('login_email_change.mail_from_name', 'CA Cloud Desk CRM');
        $hours = (int) config('login_email_change.token_expiry_hours', 24);

        $html = <<<HTML
<p>Hello,</p>
<p>You requested to change your {$appName} login email from <strong>{$request->old_email}</strong> to <strong>{$request->new_email}</strong>.</p>
<p>Please confirm this change by clicking the link below. Your current login email will remain active until verification is complete.</p>
<p><a href="{$verifyUrl}" style="display:inline-block;padding:10px 18px;background:#25b7a7;color:#fff;text-decoration:none;border-radius:8px;">Verify New Login Email</a></p>
<p>This link expires in {$hours} hours and can only be used once.</p>
<p>If you did not request this change, you can safely ignore this email.</p>
HTML;

        $this->systemEmailService->sendHtml(
            $request->new_email,
            'Verify your new login email',
            $html,
        );
    }

    private function sendCompletionNotifications(User $user, string $oldEmail, string $newEmail): void
    {
        $appName = config('login_email_change.mail_from_name', 'CA Cloud Desk CRM');

        $oldEmailHtml = <<<HTML
<p>Hello {$user->name},</p>
<p>This is a security notice from {$appName}.</p>
<p>Your login email was changed from <strong>{$oldEmail}</strong> to <strong>{$newEmail}</strong>.</p>
<p>If you did not authorize this change, contact your system administrator immediately.</p>
HTML;

        $newEmailHtml = <<<HTML
<p>Hello {$user->name},</p>
<p>Your {$appName} login email change is complete.</p>
<p>You can now sign in using <strong>{$newEmail}</strong>.</p>
HTML;

        $this->systemEmailService->sendHtml(
            $oldEmail,
            'Your login email was changed',
            $oldEmailHtml,
        );

        $this->systemEmailService->sendHtml(
            $newEmail,
            'Login email change complete',
            $newEmailHtml,
        );
    }

    private function logEmailChangeAudit(
        User $user,
        string $oldEmail,
        string $newEmail,
        string $status,
        ?string $ipAddress,
        ?string $userAgent,
        string $description,
    ): void {
        $payload = [
            'old_email' => $oldEmail,
            'new_email' => $newEmail,
            'requested_by' => $user->name,
            'status' => $status,
            'ip_address' => $ipAddress,
            'browser' => $this->summarizeUserAgent($userAgent),
            'requested_at' => now()->toIso8601String(),
        ];

        $this->activityLogService->log(
            'SECURITY',
            'Login Email Change',
            (string) $user->id,
            $description,
            performedBy: $user->name,
            beforeValue: $payload,
            afterValue: $payload,
            ipAddress: $ipAddress,
        );
    }

    private function summarizeUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return null;
        }

        $ua = trim($userAgent);

        if (strlen($ua) > 180) {
            return substr($ua, 0, 177).'...';
        }

        return $ua;
    }
}
