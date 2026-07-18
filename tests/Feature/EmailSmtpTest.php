<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Mail\CrmHtmlMail;
use App\Models\CaMaster;
use App\Models\EmailSetting;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailSmtpTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return CrmTestAccounts::admin();
    }

    private function seedEmailSettings(): EmailSetting
    {
        EmailSetting::query()->delete();

        return EmailSetting::query()->create([
            'provider_name' => 'cloud desk',
            'smtp_host' => 'smtpout.secureserver.net',
            'smtp_port' => 465,
            'smtp_username' => 'CRM Email',
            'smtp_password' => 'test-smtp-password',
            'smtp_encryption' => 'tls',
            'from_email' => 'cacloud12@gmail.com',
            'from_name' => 'CA Cloud Desk',
            'reply_to_email' => 'cacloud12@gmail.com',
            'mode' => EmailSetting::MODE_LIVE,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    public function test_email_settings_do_not_expose_password(): void
    {
        $this->seedEmailSettings();
        $this->actingAs($this->admin());

        $response = $this->getJson('/email-settings');

        $response->assertOk()
            ->assertJsonPath('data.has_smtp_password', true)
            ->assertJsonPath('data.provider_name', 'cloud desk');

        $this->assertArrayNotHasKey('smtp_password', $response->json('data'));
    }

    public function test_email_templates_include_audit_template(): void
    {
        $this->seedEmailSettings();
        $this->actingAs($this->admin());

        EmailTemplate::query()->updateOrCreate(
            ['slug' => 'audit-data-request'],
            [
                'name' => 'Audit Data Request',
                'subject' => 'Share your Sales & Purchase Data',
                'body' => 'Dear {CLIENT_NAME},',
                'is_active' => true,
            ],
        );

        $this->getJson('/email-templates')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'audit-data-request']);
    }

    public function test_template_preview_replaces_variables(): void
    {
        $this->actingAs($this->admin());
        $template = EmailTemplate::query()->create([
            'name' => 'Preview Test',
            'slug' => 'preview-test',
            'subject' => 'Hello {CLIENT_NAME}',
            'body' => 'From {CA_ORGANIZATION_NAME} in {CITY}',
            'is_active' => true,
        ]);
        $lead = CaMaster::query()->whereNotNull('email_id')->firstOrFail();
        $lead->load(['city', 'state']);

        $response = $this->postJson('/email-templates/preview', [
            'email_template_id' => $template->id,
            'lead_id' => $lead->ca_id,
        ]);

        $response->assertOk();
        $this->assertStringContainsString((string) $lead->ca_name, (string) $response->json('data.subject'));
        $this->assertStringNotContainsString('{CLIENT_NAME}', (string) $response->json('data.subject'));
    }

    public function test_send_test_email_dispatches_mail_and_logs_result(): void
    {
        Mail::fake();
        $this->seedEmailSettings();
        $this->actingAs($this->admin());

        $response = $this->postJson('/email-settings/send-test-email', [
            'recipient_email' => 'tester@gmail.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.success', true);

        Mail::assertSent(CrmHtmlMail::class, function (CrmHtmlMail $mail) {
            return str_contains($mail->mailSubject, 'SMTP Test Email');
        });

        $this->assertDatabaseHas('email_logs', [
            'recipient_email' => 'tester@gmail.com',
            'email_status' => 'Sent',
        ]);
    }

    public function test_validate_configuration_checks_required_fields(): void
    {
        EmailSetting::query()->delete();
        EmailSetting::query()->create([
            'provider_name' => 'cloud desk',
            'smtp_host' => null,
            'smtp_port' => 465,
            'smtp_username' => null,
            'smtp_password' => null,
            'smtp_encryption' => 'tls',
            'from_email' => null,
            'from_name' => 'CA Cloud Desk',
            'mode' => EmailSetting::MODE_LIVE,
            'is_default' => true,
        ]);

        $this->actingAs($this->admin());

        $this->postJson('/email-settings/validate')
            ->assertOk()
            ->assertJsonPath('data.valid', false);
    }

    public function test_email_campaign_sends_without_consent_record(): void
    {
        Mail::fake();
        $this->seedEmailSettings();
        $this->actingAs($this->admin());

        $lead = CaMaster::query()->whereNotNull('email_id')->firstOrFail();
        $lead->update(['email_id' => 'campaign.test.'.uniqid().'@gmail.com']);
        \App\Models\ConsentTracking::query()
            ->where('ca_id', $lead->ca_id)
            ->where('consent_type', 'Email')
            ->delete();

        $template = EmailTemplate::query()->updateOrCreate(
            ['slug' => 'invoice-ready'],
            [
                'name' => 'Your Invoice is Ready',
                'subject' => 'Your Invoice is Ready',
                'body' => 'Dear {{CLIENT_NAME}}, invoice ready.',
                'is_active' => true,
            ],
        );

        $response = $this->postJson('/email-campaigns', [
            'campaign_name' => 'Invoice Campaign Test',
            'campaign_type' => 'Bulk Email',
            'audience_mode' => 'selected_leads',
            'email_template_id' => $template->id,
            'ca_ids' => [$lead->ca_id],
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('email_logs', [
            'campaign_id' => $response->json('data.id'),
            'ca_id' => $lead->ca_id,
            'email_status' => 'Sent',
        ]);

        Mail::assertSent(CrmHtmlMail::class);
    }
}
