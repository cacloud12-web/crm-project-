<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginRateLimitTest extends TestCase
{
    use DatabaseTransactions;

    private function loginPayload(string $email, string $password = 'wrong-password'): array
    {
        return [
            'email' => $email,
            'password' => $password,
        ];
    }

    public function test_user_can_login_with_correct_credentials(): void
    {
        $response = $this->post('/login', $this->loginPayload('admin@ca.local', 'password'));

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs(User::query()->where('email', 'admin@ca.local')->firstOrFail());
    }

    public function test_wrong_password_returns_generic_error_message(): void
    {
        $email = 'wrong-pass.'.microtime(true).'@test.local';

        $response = $this->from('/login')->post('/login', $this->loginPayload($email));

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors([
            'email' => 'Invalid email or password.',
        ]);
    }

    public function test_five_wrong_password_attempts_block_login(): void
    {
        $email = 'block.'.microtime(true).'@test.local';

        for ($i = 0; $i < 5; $i++) {
            $this->from('/login')->post('/login', $this->loginPayload($email))
                ->assertRedirect('/login')
                ->assertSessionHasErrors(['email']);
        }

        $this->assertSame(5, ActivityLog::query()->where('action', 'Login Failed')->where('record_id', $email)->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'Login Locked')->where('record_id', $email)->count());
    }

    public function test_sixth_attempt_returns_lockout_message(): void
    {
        $email = 'lockout.'.microtime(true).'@test.local';

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', $this->loginPayload($email));
        }

        $response = $this->from('/login')->post('/login', $this->loginPayload($email));

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors([
            'email' => 'Too many failed login attempts. Please try again after 15 minutes.',
        ]);
    }

    public function test_correct_login_clears_failed_attempts_after_lock_expires(): void
    {
        $email = 'recover.'.microtime(true).'@test.local';

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', $this->loginPayload($email));
        }

        $this->from('/login')->post('/login', $this->loginPayload($email))
            ->assertSessionHasErrors(['email']);

        $this->travel(16)->minutes();

        $response = $this->post('/login', $this->loginPayload('admin@ca.local', 'password'));

        $response->assertRedirect('/dashboard');

        $key = 'login:'.sha1(strtolower($email).'|127.0.0.1');
        $this->assertFalse(RateLimiter::tooManyAttempts($key, 5));
    }

    public function test_successful_login_after_failed_attempts_is_logged(): void
    {
        $email = 'admin@ca.local';

        $this->post('/login', $this->loginPayload($email, 'wrong-password'));
        $this->post('/login', $this->loginPayload($email, 'wrong-password'));

        $this->post('/login', $this->loginPayload($email, 'password'))
            ->assertRedirect('/dashboard');

        $successLog = ActivityLog::query()
            ->where('action', 'Login Success')
            ->where('record_id', $email)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($successLog);
        $this->assertSame('SECURITY', $successLog->module_name);
        $this->assertSame('127.0.0.1', $successLog->ip_address);

        $payload = json_decode((string) $successLog->after_value, true);
        $this->assertSame($email, $payload['email']);
        $this->assertSame('success', $payload['status']);
        $this->assertArrayNotHasKey('password', $payload);
    }

    public function test_failed_login_creates_security_activity_log(): void
    {
        $email = 'audit.'.microtime(true).'@test.local';

        $this->post('/login', $this->loginPayload($email));

        $log = ActivityLog::query()
            ->where('action', 'Login Failed')
            ->where('record_id', $email)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('SECURITY', $log->module_name);

        $payload = json_decode((string) $log->after_value, true);
        $this->assertSame($email, $payload['email']);
        $this->assertSame('127.0.0.1', $payload['ip_address']);
        $this->assertSame('failed', $payload['status']);
        $this->assertNotEmpty($payload['user_agent']);
        $this->assertNotEmpty($payload['attempt_time']);
    }
}
