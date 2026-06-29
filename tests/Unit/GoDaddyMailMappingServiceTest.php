<?php

namespace Tests\Unit;

use App\Models\CaMaster;
use App\Models\EmailSetting;
use App\Models\User;
use App\Services\Email\EmailSettingsService;
use App\Services\Email\GoDaddyMailService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GoDaddyMailMappingServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_build_mail_transport_maps_godaddy_smtp_fields(): void
    {
        $settings = new EmailSetting([
            'provider_name' => 'GoDaddy SMTP',
            'smtp_host' => 'smtpout.secureserver.net',
            'smtp_port' => 465,
            'smtp_username' => 'sales@example.com',
            'smtp_password' => 'manager-provided-password',
            'smtp_encryption' => 'ssl',
            'from_email' => 'sales@example.com',
            'from_name' => 'CA Cloud Desk',
            'mode' => EmailSetting::MODE_SIMULATION,
        ]);

        $transport = app(GoDaddyMailService::class)->buildMailTransport($settings);

        $this->assertSame('smtpout.secureserver.net', $transport['MAIL_HOST']);
        $this->assertSame(465, $transport['MAIL_PORT']);
        $this->assertSame('sales@example.com', $transport['MAIL_USERNAME']);
        $this->assertSame('[REDACTED]', $transport['MAIL_PASSWORD']);
        $this->assertSame('ssl', $transport['MAIL_ENCRYPTION']);
        $this->assertSame('sales@example.com', $transport['MAIL_FROM_ADDRESS']);
        $this->assertSame('CA Cloud Desk', $transport['MAIL_FROM_NAME']);
    }

    public function test_validation_fails_without_smtp_settings_or_valid_email(): void
    {
        $settings = new EmailSetting([
            'smtp_host' => null,
            'smtp_port' => null,
            'smtp_username' => null,
            'smtp_password' => null,
            'smtp_encryption' => null,
            'from_email' => null,
        ]);

        $errors = app(GoDaddyMailService::class)->validateDispatchPrerequisites(
            $settings,
            'not-an-email',
            '',
            '',
        );

        $this->assertContains('SMTP host is not configured.', $errors);
        $this->assertContains('SMTP password is not configured.', $errors);
        $this->assertContains('Email subject is required.', $errors);
        $this->assertContains('Lead email address is missing or invalid.', $errors);
    }

    public function test_prepare_for_lead_stores_mapped_mail_without_sending(): void
    {
        $settings = EmailSetting::query()->create([
            'provider_name' => 'GoDaddy SMTP',
            'smtp_host' => 'smtpout.secureserver.net',
            'smtp_port' => 465,
            'smtp_username' => 'sales@example.com',
            'smtp_password' => 'manager-provided-password',
            'smtp_encryption' => 'ssl',
            'from_email' => 'sales@example.com',
            'from_name' => 'CA Cloud Desk',
            'mode' => EmailSetting::MODE_SIMULATION,
        ]);

        $lead = CaMaster::query()->firstOrFail();
        $lead->update(['email_id' => 'lead@example.com']);

        $prepared = app(GoDaddyMailService::class)->prepareForLead(
            $lead,
            'Hello {{name}}',
            'Welcome to CA Cloud Desk',
            $settings,
        );

        $this->assertTrue($prepared['valid']);
        $this->assertSame('lead@example.com', $prepared['mail_object']['to']);
        $this->assertStringContainsString('mapped_not_sent', (string) $prepared['provider_response']);
    }

    public function test_settings_defaults_leave_credentials_empty(): void
    {
        EmailSetting::query()->delete();

        $settings = app(EmailSettingsService::class)->current();
        $public = app(EmailSettingsService::class)->toPublicArray($settings);

        $this->assertSame('GoDaddy SMTP', $settings->provider_name);
        $this->assertNull($settings->smtp_username);
        $this->assertFalse($public['has_smtp_password']);
        $this->assertArrayNotHasKey('smtp_password', $public);
        $this->assertSame(EmailSetting::MODE_SIMULATION, $settings->mode);
    }

    public function test_employee_cannot_manage_email_settings(): void
    {
        $employee = User::query()->where('crm_role', 'employee')->first();

        if (! $employee) {
            $this->markTestSkipped('No employee user in database.');
        }

        $this->expectException(AuthorizationException::class);
        app(EmailSettingsService::class)->ensureCanManageSettings($employee);
    }
}
