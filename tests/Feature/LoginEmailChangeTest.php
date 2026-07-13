<?php

namespace Tests\Feature;

use App\Mail\CrmHtmlMail;
use App\Models\EmailSetting;
use App\Models\LoginEmailChangeRequest;
use App\Models\User;
use App\Services\Auth\LoginEmailChangeService;
use App\Services\Email\EmailSmtpDispatchService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoginEmailChangeTest extends TestCase
{
    use DatabaseTransactions;

    private LoginEmailChangeService $loginEmailChangeService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDefaultSmtpSettings();
        $this->loginEmailChangeService = app(LoginEmailChangeService::class);

        $superAdmin = $this->superAdmin();
        LoginEmailChangeRequest::query()->where('user_id', $superAdmin->id)->delete();
    }

    private function superAdmin(): User
    {
        return User::query()->where('email', 'superadmin@ca.local')->firstOrFail();
    }

    private function actingAsSuperAdmin(): User
    {
        $user = $this->superAdmin();
        $this->actingAs($user);

        return $user;
    }

    /** @return array<string, string> */
    private function changePayload(User $user, string $newEmail, string $password = 'password'): array
    {
        return [
            'new_email' => $newEmail,
            'new_email_confirmation' => $newEmail,
            'current_password' => $password,
            'current_email' => $user->email,
        ];
    }

    private function uniqueGmail(string $prefix = 'login.change'): string
    {
        return $prefix.'.'.uniqid().'@gmail.com';
    }

    private function seedDefaultSmtpSettings(): void
    {
        EmailSetting::query()->delete();

        EmailSetting::query()->create([
            'provider_name' => 'cloud desk',
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => 465,
            'smtp_username' => 'test@example.com',
            'smtp_password' => 'test-smtp-password',
            'smtp_encryption' => 'ssl',
            'from_email' => 'test@example.com',
            'from_name' => 'CA Cloud Desk',
            'reply_to_email' => 'test@example.com',
            'mode' => EmailSetting::MODE_LIVE,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    private function smtpDispatchFailureMock(): void
    {
        $this->mock(EmailSmtpDispatchService::class, function ($mock) {
            $mock->shouldReceive('send')->andReturn([
                'success' => false,
                'status' => 'failed',
                'provider_response' => [],
                'error_message' => 'SMTP connection failed',
                'smtp_error' => 'SMTP connection failed',
            ]);
        });
    }

    private function extractVerificationTokenFromSentMail(): string
    {
        $token = null;

        Mail::assertSent(CrmHtmlMail::class, function (CrmHtmlMail $mail) use (&$token) {
            if (! preg_match('/verify-login-email\/([A-Za-z0-9]+)/', $mail->htmlBody, $matches)) {
                return false;
            }

            $token = $matches[1];

            return $mail->mailSubject === 'Verify your new login email';
        });

        $this->assertNotNull($token, 'Expected a verification token in the sent email.');

        return (string) $token;
    }

    private function createPendingRequest(User $user, string $newEmail, ?string $plainToken = null): LoginEmailChangeRequest
    {
        $plainToken ??= Str::random(64);

        return LoginEmailChangeRequest::query()->create([
            'user_id' => $user->id,
            'old_email' => $user->email,
            'new_email' => $newEmail,
            'status' => LoginEmailChangeRequest::STATUS_PENDING,
            'token_hash' => $this->loginEmailChangeService->hashToken($plainToken),
            'expires_at' => now()->addDay(),
            'requested_ip' => '127.0.0.1',
            'requested_user_agent' => 'PHPUnit',
        ]);
    }

    public function test_super_admin_can_view_verified_status_when_no_pending_request(): void
    {
        $user = $this->actingAsSuperAdmin();

        $this->getJson('/auth/login-email-change')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.current_email', $user->email)
            ->assertJsonPath('data.verification_status', LoginEmailChangeRequest::STATUS_VERIFIED)
            ->assertJsonPath('data.pending_verification', null);
    }

    public function test_super_admin_can_view_login_email_change_history(): void
    {
        $user = $this->actingAsSuperAdmin();
        $newEmail = $this->uniqueGmail('history.row');

        LoginEmailChangeRequest::query()->create([
            'user_id' => $user->id,
            'old_email' => $user->email,
            'new_email' => $newEmail,
            'status' => LoginEmailChangeRequest::STATUS_PENDING,
            'token_hash' => $this->loginEmailChangeService->hashToken(Str::random(64)),
            'expires_at' => now()->addDay(),
            'requested_ip' => '127.0.0.1',
        ]);

        $this->getJson('/auth/login-email-change/history')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.old_email', $user->email)
            ->assertJsonPath('data.0.new_email', $newEmail)
            ->assertJsonPath('data.0.ip_address', '127.0.0.1');
    }

    public function test_status_reflects_active_pending_verification_request(): void
    {
        $user = $this->actingAsSuperAdmin();
        $newEmail = $this->uniqueGmail('pending.status');

        $pending = $this->createPendingRequest($user, $newEmail);

        $this->getJson('/auth/login-email-change')
            ->assertOk()
            ->assertJsonPath('data.verification_status', LoginEmailChangeRequest::STATUS_PENDING)
            ->assertJsonPath('data.pending_verification.id', $pending->id)
            ->assertJsonPath('data.pending_verification.new_email', $newEmail)
            ->assertJsonPath('data.pending_verification.status', LoginEmailChangeRequest::STATUS_PENDING);
    }

    public function test_status_marks_stale_pending_requests_as_expired(): void
    {
        $user = $this->actingAsSuperAdmin();
        $newEmail = $this->uniqueGmail('stale.pending');

        LoginEmailChangeRequest::query()->create([
            'user_id' => $user->id,
            'old_email' => $user->email,
            'new_email' => $newEmail,
            'status' => LoginEmailChangeRequest::STATUS_PENDING,
            'token_hash' => $this->loginEmailChangeService->hashToken(Str::random(64)),
            'expires_at' => now()->subHour(),
        ]);

        $this->getJson('/auth/login-email-change')
            ->assertOk()
            ->assertJsonPath('data.verification_status', LoginEmailChangeRequest::STATUS_EXPIRED)
            ->assertJsonPath('data.pending_verification', null)
            ->assertJsonPath('data.last_request.status', LoginEmailChangeRequest::STATUS_EXPIRED);
    }

    public function test_non_super_admin_users_are_denied_access(): void
    {
        $deniedUsers = [
            'admin@ca.local',
            'manager@ca.local',
            'employee@ca.local',
        ];

        foreach ($deniedUsers as $email) {
            $user = User::query()->where('email', $email)->firstOrFail();
            $this->actingAs($user);

            $this->getJson('/auth/login-email-change')->assertForbidden();
            $this->getJson('/auth/login-email-change/history')->assertForbidden();
            $this->postJson('/auth/login-email-change', $this->changePayload($user, $this->uniqueGmail('denied')))->assertForbidden();
            $this->postJson('/auth/login-email-change/resend')->assertForbidden();
            $this->postJson('/auth/login-email-change/cancel')->assertForbidden();
        }
    }

    public function test_unauthenticated_user_cannot_access_login_email_change_endpoints(): void
    {
        $this->getJson('/auth/login-email-change')->assertUnauthorized();
        $this->getJson('/auth/login-email-change/history')->assertUnauthorized();
        $this->postJson('/auth/login-email-change', [])->assertUnauthorized();
        $this->postJson('/auth/login-email-change/resend')->assertUnauthorized();
        $this->postJson('/auth/login-email-change/cancel')->assertUnauthorized();
    }

    public function test_fake_domains_are_rejected(): void
    {
        $user = $this->actingAsSuperAdmin();

        $blockedEmails = [
            'blocked.'.uniqid().'@example.com',
            'blocked.'.uniqid().'@test.com',
            'blocked.'.uniqid().'@mailinator.com',
            'blocked.'.uniqid().'@ca.local',
        ];

        foreach ($blockedEmails as $blockedEmail) {
            $this->postJson('/auth/login-email-change', $this->changePayload($user, $blockedEmail))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['new_email']);
        }

        $this->assertDatabaseMissing('login_email_change_requests', [
            'user_id' => $user->id,
        ]);
    }

    public function test_valid_gmail_address_is_accepted_and_sends_verification_email(): void
    {
        Mail::fake();

        $user = $this->actingAsSuperAdmin();
        $newEmail = $this->uniqueGmail('accepted');

        $this->postJson('/auth/login-email-change', $this->changePayload($user, $newEmail))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.verification_status', LoginEmailChangeRequest::STATUS_PENDING)
            ->assertJsonPath('data.pending_verification.new_email', $newEmail);

        $this->assertDatabaseHas('login_email_change_requests', [
            'user_id' => $user->id,
            'old_email' => $user->email,
            'new_email' => $newEmail,
            'status' => LoginEmailChangeRequest::STATUS_PENDING,
        ]);

        Mail::assertSent(CrmHtmlMail::class, function (CrmHtmlMail $mail) use ($newEmail) {
            return $mail->mailSubject === 'Verify your new login email'
                && str_contains($mail->htmlBody, $newEmail)
                && str_contains($mail->htmlBody, 'verify-login-email/');
        });

        $this->assertDatabaseHas('activity_logs', [
            'module_name' => 'SECURITY',
            'action' => 'Login Email Change',
            'performed_by' => $user->name,
        ]);
    }

    public function test_verification_email_uses_configured_smtp_dispatch_service(): void
    {
        $user = $this->actingAsSuperAdmin();
        $newEmail = $this->uniqueGmail('smtp.dispatch');

        $this->mock(EmailSmtpDispatchService::class, function ($mock) use ($newEmail) {
            $mock->shouldReceive('send')
                ->once()
                ->withArgs(function (EmailSetting $settings, string $recipient, string $subject, string $body) use ($newEmail) {
                    return $settings->isLiveMode()
                        && $settings->isConfigured()
                        && $recipient === $newEmail
                        && $subject === 'Verify your new login email'
                        && str_contains($body, 'verify-login-email/');
                })
                ->andReturn([
                    'success' => true,
                    'status' => 'sent',
                    'provider_response' => [],
                    'error_message' => null,
                    'smtp_error' => null,
                ]);
        });

        $this->postJson('/auth/login-email-change', $this->changePayload($user, $newEmail))
            ->assertOk()
            ->assertJsonPath('data.pending_verification.new_email', $newEmail);
    }

    public function test_incorrect_password_is_rejected(): void
    {
        Mail::fake();

        $user = $this->actingAsSuperAdmin();
        $newEmail = $this->uniqueGmail('wrong.password');

        $this->postJson('/auth/login-email-change', $this->changePayload($user, $newEmail, 'not-the-password'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);

        $this->assertDatabaseMissing('login_email_change_requests', [
            'user_id' => $user->id,
            'new_email' => $newEmail,
        ]);

        Mail::assertNothingSent();
    }

    public function test_duplicate_pending_request_is_rejected(): void
    {
        Mail::fake();

        $user = $this->actingAsSuperAdmin();
        $firstEmail = $this->uniqueGmail('first.pending');
        $secondEmail = $this->uniqueGmail('second.pending');

        $this->postJson('/auth/login-email-change', $this->changePayload($user, $firstEmail))
            ->assertOk();

        $this->postJson('/auth/login-email-change', $this->changePayload($user, $secondEmail))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_email']);

        $this->assertDatabaseHas('login_email_change_requests', [
            'user_id' => $user->id,
            'new_email' => $firstEmail,
            'status' => LoginEmailChangeRequest::STATUS_PENDING,
        ]);

        $this->assertDatabaseMissing('login_email_change_requests', [
            'user_id' => $user->id,
            'new_email' => $secondEmail,
        ]);
    }

    public function test_resend_sends_new_verification_email_and_refreshes_token(): void
    {
        Mail::fake();

        $user = $this->actingAsSuperAdmin();
        $newEmail = $this->uniqueGmail('resend');

        $this->postJson('/auth/login-email-change', $this->changePayload($user, $newEmail))
            ->assertOk();

        $firstToken = $this->extractVerificationTokenFromSentMail();
        $firstHash = $this->loginEmailChangeService->hashToken($firstToken);

        Mail::fake();

        $this->postJson('/auth/login-email-change/resend')
            ->assertOk()
            ->assertJsonPath('data.verification_status', LoginEmailChangeRequest::STATUS_PENDING)
            ->assertJsonPath('data.pending_verification.new_email', $newEmail);

        $secondToken = $this->extractVerificationTokenFromSentMail();
        $this->assertNotSame($firstToken, $secondToken);

        $request = LoginEmailChangeRequest::query()
            ->where('user_id', $user->id)
            ->where('new_email', $newEmail)
            ->firstOrFail();

        $this->assertSame($this->loginEmailChangeService->hashToken($secondToken), $request->token_hash);
        $this->assertNotSame($firstHash, $request->token_hash);
        $this->assertTrue($request->expires_at->isFuture());
    }

    public function test_resend_fails_when_no_pending_request_exists(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/auth/login-email-change/resend')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['request']);
    }

    public function test_cancel_pending_login_email_change(): void
    {
        Mail::fake();

        $user = $this->actingAsSuperAdmin();
        $newEmail = $this->uniqueGmail('cancel');

        $this->postJson('/auth/login-email-change', $this->changePayload($user, $newEmail))
            ->assertOk();

        $this->postJson('/auth/login-email-change/cancel')
            ->assertOk()
            ->assertJsonPath('data.verification_status', LoginEmailChangeRequest::STATUS_CANCELLED)
            ->assertJsonPath('data.pending_verification', null)
            ->assertJsonPath('data.last_request.status', LoginEmailChangeRequest::STATUS_CANCELLED);

        $this->assertDatabaseHas('login_email_change_requests', [
            'user_id' => $user->id,
            'new_email' => $newEmail,
            'status' => LoginEmailChangeRequest::STATUS_CANCELLED,
        ]);
    }

    public function test_cancel_fails_when_no_pending_request_exists(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/auth/login-email-change/cancel')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['request']);
    }

    public function test_expired_verification_link_is_rejected(): void
    {
        $user = $this->superAdmin();
        $newEmail = $this->uniqueGmail('expired');
        $plainToken = Str::random(64);

        LoginEmailChangeRequest::query()->create([
            'user_id' => $user->id,
            'old_email' => $user->email,
            'new_email' => $newEmail,
            'status' => LoginEmailChangeRequest::STATUS_PENDING,
            'token_hash' => $this->loginEmailChangeService->hashToken($plainToken),
            'expires_at' => now()->subMinute(),
        ]);

        $this->get('/auth/verify-login-email/'.$plainToken)
            ->assertOk()
            ->assertViewIs('crm.auth.verify-login-email')
            ->assertViewHas('success', false)
            ->assertSee('This verification link has expired', false);

        $this->assertDatabaseHas('login_email_change_requests', [
            'user_id' => $user->id,
            'new_email' => $newEmail,
            'status' => LoginEmailChangeRequest::STATUS_EXPIRED,
        ]);

        $this->assertSame('superadmin@ca.local', $user->fresh()->email);
    }

    public function test_verify_updates_login_email_and_sends_completion_notifications(): void
    {
        Mail::fake();

        $user = $this->actingAsSuperAdmin();
        $oldEmail = (string) $user->email;
        $newEmail = $this->uniqueGmail('verified');

        $this->postJson('/auth/login-email-change', $this->changePayload($user, $newEmail))
            ->assertOk();

        $plainToken = $this->extractVerificationTokenFromSentMail();

        Mail::fake();

        $this->get('/auth/verify-login-email/'.$plainToken)
            ->assertOk()
            ->assertViewIs('crm.auth.verify-login-email')
            ->assertViewHas('success', true)
            ->assertSee('Your login email has been updated', false);

        $user->refresh();

        $this->assertSame($newEmail, $user->email);

        $this->assertDatabaseHas('login_email_change_requests', [
            'user_id' => $user->id,
            'new_email' => $newEmail,
            'status' => LoginEmailChangeRequest::STATUS_VERIFIED,
        ]);

        Mail::assertSent(CrmHtmlMail::class, function (CrmHtmlMail $mail) use ($oldEmail) {
            return $mail->mailSubject === 'Your login email was changed'
                && str_contains($mail->htmlBody, $oldEmail);
        });

        Mail::assertSent(CrmHtmlMail::class, function (CrmHtmlMail $mail) use ($newEmail) {
            return $mail->mailSubject === 'Login email change complete'
                && str_contains($mail->htmlBody, $newEmail);
        });
    }

    public function test_invalid_verification_token_is_rejected(): void
    {
        $this->get('/auth/verify-login-email/'.Str::random(64))
            ->assertOk()
            ->assertViewHas('success', false)
            ->assertSee('invalid or has already been used', false);
    }

    public function test_already_used_verification_token_is_rejected(): void
    {
        $user = $this->superAdmin();
        $newEmail = $this->uniqueGmail('used.token');
        $plainToken = Str::random(64);

        LoginEmailChangeRequest::query()->create([
            'user_id' => $user->id,
            'old_email' => $user->email,
            'new_email' => $newEmail,
            'status' => LoginEmailChangeRequest::STATUS_VERIFIED,
            'token_hash' => $this->loginEmailChangeService->hashToken($plainToken),
            'expires_at' => now()->addDay(),
            'verified_at' => now()->subMinute(),
        ]);

        $this->get('/auth/verify-login-email/'.$plainToken)
            ->assertOk()
            ->assertViewHas('success', false)
            ->assertSee('already been used', false);
    }

    public function test_cancelled_verification_token_is_rejected(): void
    {
        $user = $this->superAdmin();
        $newEmail = $this->uniqueGmail('cancelled.token');
        $plainToken = Str::random(64);

        LoginEmailChangeRequest::query()->create([
            'user_id' => $user->id,
            'old_email' => $user->email,
            'new_email' => $newEmail,
            'status' => LoginEmailChangeRequest::STATUS_CANCELLED,
            'token_hash' => $this->loginEmailChangeService->hashToken($plainToken),
            'expires_at' => now()->addDay(),
        ]);

        $this->get('/auth/verify-login-email/'.$plainToken)
            ->assertOk()
            ->assertViewHas('success', false)
            ->assertSee('was cancelled', false);
    }

    public function test_smtp_failure_during_request_rolls_back_and_returns_error(): void
    {
        $this->smtpDispatchFailureMock();

        $user = $this->actingAsSuperAdmin();
        $newEmail = $this->uniqueGmail('smtp.fail');

        $this->postJson('/auth/login-email-change', $this->changePayload($user, $newEmail))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseMissing('login_email_change_requests', [
            'user_id' => $user->id,
            'new_email' => $newEmail,
        ]);
    }

    public function test_smtp_failure_during_resend_returns_error_without_removing_pending_request(): void
    {
        Mail::fake();

        $user = $this->actingAsSuperAdmin();
        $newEmail = $this->uniqueGmail('resend.fail');

        $this->postJson('/auth/login-email-change', $this->changePayload($user, $newEmail))
            ->assertOk();

        $request = LoginEmailChangeRequest::query()
            ->where('user_id', $user->id)
            ->where('new_email', $newEmail)
            ->firstOrFail();

        $this->smtpDispatchFailureMock();

        $this->postJson('/auth/login-email-change/resend')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseHas('login_email_change_requests', [
            'id' => $request->id,
            'user_id' => $user->id,
            'new_email' => $newEmail,
            'status' => LoginEmailChangeRequest::STATUS_PENDING,
        ]);
    }
}
