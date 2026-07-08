<?php

namespace App\Services\Auth;

use App\Services\Activity\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class LoginRateLimiterService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
    ) {}

    public function maxAttempts(): int
    {
        return (int) config('crm_queue.login_max_attempts', 5);
    }

    public function decaySeconds(): int
    {
        return (int) config('crm_queue.login_decay_minutes', 15) * 60;
    }

    public function throttleKey(Request $request): string
    {
        $email = strtolower(trim((string) $request->input('email', '')));

        return 'login:'.sha1($email.'|'.$request->ip());
    }

    public function isLockedOut(Request $request): bool
    {
        return RateLimiter::tooManyAttempts($this->throttleKey($request), $this->maxAttempts());
    }

    public function secondsUntilAvailable(Request $request): int
    {
        return RateLimiter::availableIn($this->throttleKey($request));
    }

    public function failedAttemptCount(Request $request): int
    {
        return RateLimiter::attempts($this->throttleKey($request));
    }

    public function lockoutMessage(): string
    {
        $minutes = (int) config('crm_queue.login_decay_minutes', 15);

        return "Too many failed login attempts. Please try again after {$minutes} minutes.";
    }

    public function recordFailedAttempt(Request $request): void
    {
        $key = $this->throttleKey($request);

        RateLimiter::hit($key, $this->decaySeconds());

        $this->logAttempt($request, 'failed');

        if (RateLimiter::tooManyAttempts($key, $this->maxAttempts())) {
            $this->logAttempt($request, 'locked');
        }
    }

    public function clear(Request $request): void
    {
        $key = $this->throttleKey($request);
        $hadFailedAttempts = RateLimiter::attempts($key) > 0;

        RateLimiter::clear($key);

        if ($hadFailedAttempts) {
            $this->logAttempt($request, 'success');
        }
    }

    public function logAttempt(Request $request, string $status): void
    {
        $action = match ($status) {
            'failed' => 'Login Failed',
            'locked' => 'Login Locked',
            'success' => 'Login Success',
            default => 'Login Attempt',
        };

        $this->activityLogService->log(
            moduleName: 'SECURITY',
            action: $action,
            recordId: strtolower(trim((string) $request->input('email', ''))),
            description: $this->buildDescription($request, $status),
            performedBy: 'System',
            beforeValue: null,
            afterValue: $this->auditPayload($request, $status),
            ipAddress: $request->ip(),
        );
    }

    /**
     * @return array{email: string, ip_address: string|null, user_agent: string, attempt_time: string, status: string}
     */
    private function auditPayload(Request $request, string $status): array
    {
        return [
            'email' => strtolower(trim((string) $request->input('email', ''))),
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'attempt_time' => now()->toIso8601String(),
            'status' => $status,
        ];
    }

    private function buildDescription(Request $request, string $status): string
    {
        $email = strtolower(trim((string) $request->input('email', '')));

        return match ($status) {
            'failed' => "Failed login attempt for {$email}",
            'locked' => "Login temporarily locked for {$email} after multiple failed attempts",
            'success' => "Successful login for {$email} after prior failed attempts",
            default => "Login attempt ({$status}) for {$email}",
        };
    }
}
