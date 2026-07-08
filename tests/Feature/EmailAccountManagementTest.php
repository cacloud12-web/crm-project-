<?php

namespace Tests\Feature;

use App\Models\EmailSetting;
use App\Models\User;
use App\Services\Email\EmailImapConnectionService;
use App\Services\Email\EmailSmtpConnectionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class EmailAccountManagementTest extends TestCase
{
    use DatabaseTransactions;

    private function superAdmin(): User
    {
        return User::query()->where('email', 'superadmin@ca.local')->firstOrFail();
    }

    private function admin(): User
    {
        return User::query()->where('email', 'admin@ca.local')->firstOrFail();
    }

    private function issueSmtpToken(User $user, array $payload): string
    {
        $this->mock(EmailSmtpConnectionService::class, function ($mock) {
            $mock->shouldReceive('test')->andReturn(['success' => true, 'message' => 'SMTP OK']);
        });

        $response = $this->actingAs($user)->postJson('/email-accounts/test-smtp', $payload);

        return (string) $response->json('data.verification_token');
    }

    public function test_super_admin_can_list_email_accounts(): void
    {
        $this->actingAs($this->superAdmin());

        $this->getJson('/email-accounts')
            ->assertOk()
            ->assertJsonStructure(['data' => ['items']]);
    }

    public function test_admin_cannot_access_email_accounts(): void
    {
        $this->actingAs($this->admin());

        $this->getJson('/email-accounts')->assertForbidden();
    }

    public function test_cannot_save_without_smtp_verification_token(): void
    {
        $this->actingAs($this->superAdmin());

        $this->postJson('/email-accounts', [
            'from_email' => 'sales.'.uniqid().'@company.com',
            'display_name' => 'Sales',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'smtp_username' => 'sales',
            'smtp_password' => 'secret-pass',
            'imap_enabled' => false,
            'is_default' => true,
            'smtp_verification_token' => '',
        ])->assertUnprocessable();
    }

    public function test_super_admin_can_create_email_account_after_smtp_test(): void
    {
        $user = $this->superAdmin();
        $this->actingAs($user);

        $email = 'sales.'.uniqid().'@company.com';
        $payload = [
            'from_email' => $email,
            'display_name' => 'Sales Team',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'smtp_username' => 'sales',
            'smtp_password' => 'secret-pass',
            'imap_enabled' => false,
            'is_default' => true,
        ];

        $smtpToken = $this->issueSmtpToken($user, $payload);
        $payload['smtp_verification_token'] = $smtpToken;

        $this->postJson('/email-accounts', $payload)
            ->assertCreated()
            ->assertJsonPath('data.from_email', $email);

        $this->assertDatabaseHas('email_settings', [
            'from_email' => $email,
            'is_default' => true,
        ]);

        $this->assertArrayNotHasKey('smtp_password', $this->getJson('/email-accounts')->json('data.items.0') ?? []);
    }

    public function test_duplicate_email_account_is_rejected(): void
    {
        $user = $this->superAdmin();
        $this->actingAs($user);

        $email = 'dup.'.uniqid().'@company.com';
        $payload = [
            'from_email' => $email,
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'smtp_username' => 'sales',
            'smtp_password' => 'secret-pass',
            'imap_enabled' => false,
            'is_default' => false,
        ];

        $smtpToken = $this->issueSmtpToken($user, $payload);
        $payload['smtp_verification_token'] = $smtpToken;
        $this->postJson('/email-accounts', $payload)->assertCreated();

        $smtpToken2 = $this->issueSmtpToken($user, $payload);
        $payload['smtp_verification_token'] = $smtpToken2;
        $this->postJson('/email-accounts', $payload)->assertUnprocessable();
    }

    public function test_set_default_switches_default_account(): void
    {
        $user = $this->superAdmin();
        $this->actingAs($user);

        EmailSetting::query()->update(['is_default' => false]);
        $a = EmailSetting::query()->create([
            'from_email' => 'a.'.uniqid().'@company.com',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 465,
            'smtp_username' => 'a',
            'smtp_password' => 'pass',
            'smtp_encryption' => 'ssl',
            'is_default' => true,
            'is_active' => true,
            'mode' => EmailSetting::MODE_LIVE,
        ]);
        $b = EmailSetting::query()->create([
            'from_email' => 'b.'.uniqid().'@company.com',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 465,
            'smtp_username' => 'b',
            'smtp_password' => 'pass',
            'smtp_encryption' => 'ssl',
            'is_default' => false,
            'is_active' => true,
            'mode' => EmailSetting::MODE_LIVE,
        ]);

        $this->postJson('/email-accounts/'.$b->id.'/set-default')->assertOk();

        $this->assertFalse((bool) EmailSetting::query()->find($a->id)?->is_default);
        $this->assertTrue((bool) EmailSetting::query()->find($b->id)?->is_default);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Cache::flush();
        parent::tearDown();
    }
}
