<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\PasswordResetService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use DatabaseTransactions;

    public function test_forgot_password_page_is_accessible(): void
    {
        $this->get('/forgot-password')->assertOk();
    }

    public function test_reset_link_can_be_sent_for_active_user(): void
    {
        Mail::fake();

        $user = User::query()->where('email', 'manager@ca.local')->firstOrFail();

        $response = $this->post('/forgot-password', [
            'email' => $user->email,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        Mail::assertSent(\App\Mail\CrmHtmlMail::class);
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        $user = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $token = Password::broker()->createToken($user);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertRedirect(route('crm.login'));
        $user->refresh();
        $this->assertTrue(Hash::check('new-password-123', $user->password));

        $user->update(['password' => Hash::make('password')]);
    }

    public function test_deactivated_user_cannot_reset_password(): void
    {
        $user = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $user->update(['is_active' => false]);
        $token = Password::broker()->createToken($user);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertSessionHasErrors('email');
        $user->update(['is_active' => true]);
    }
}
