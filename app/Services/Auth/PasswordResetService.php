<?php

namespace App\Services\Auth;

use App\Mail\CrmHtmlMail;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PasswordResetService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
    ) {}

    public function sendResetLink(string $email): string
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! $user->is_active) {
            return Password::RESET_LINK_SENT;
        }

        $token = Password::broker()->createToken($user);

        try {
            $resetUrl = url(route('crm.password.reset', [
                'token' => $token,
                'email' => $user->email,
            ], false));

            Mail::to($user->email)->send(new CrmHtmlMail(
                mailSubject: 'Reset your CA Cloud Desk CRM password',
                htmlBody: $this->buildResetEmailBody($user->name, $resetUrl),
            ));
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'email' => ['Unable to send password reset email. Please try again later or contact support.'],
            ]);
        }

        $this->activityLogService->log(
            'AUTH',
            'Password reset requested',
            (string) $user->id,
            $user->email,
        );

        return Password::RESET_LINK_SENT;
    }

    public function resetPassword(string $email, string $token, string $password): string
    {
        $status = Password::broker()->reset(
            [
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password,
                'token' => $token,
            ],
            function (User $user, string $newPassword) {
                if (! $user->is_active) {
                    throw ValidationException::withMessages([
                        'email' => ['This account is deactivated. Please contact an administrator.'],
                    ]);
                }

                $user->forceFill([
                    'password' => Hash::make($newPassword),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));

                $this->activityLogService->log(
                    'AUTH',
                    'Password reset completed',
                    (string) $user->id,
                    $user->email,
                );
            },
        );

        return $status;
    }

    private function buildResetEmailBody(?string $name, string $resetUrl): string
    {
        $greeting = $name ? 'Hello '.e($name).',' : 'Hello,';

        return '<p>'.$greeting.'</p>'
            .'<p>We received a request to reset your CA Cloud Desk CRM password.</p>'
            .'<p><a href="'.e($resetUrl).'" style="display:inline-block;padding:10px 18px;background:#2563eb;color:#fff;text-decoration:none;border-radius:8px;">Reset Password</a></p>'
            .'<p>This link expires in '.(config('auth.passwords.users.expire') ?? 60).' minutes.</p>'
            .'<p>If you did not request a password reset, you can safely ignore this email.</p>';
    }
}
