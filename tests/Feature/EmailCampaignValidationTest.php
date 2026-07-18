<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Mail\CrmHtmlMail;
use App\Models\CaMaster;
use App\Models\EmailCampaign;
use App\Models\EmailLog;
use App\Models\EmailSetting;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\Email\EmailRecipientValidationService;
use App\Services\Email\EmailSmtpDispatchService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailCampaignValidationTest extends TestCase
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

    private function campaignTemplate(): EmailTemplate
    {
        return EmailTemplate::query()->updateOrCreate(
            ['slug' => 'invoice-ready'],
            [
                'name' => 'Your Invoice is Ready',
                'subject' => 'Your Invoice is Ready',
                'body' => 'Dear {{CLIENT_NAME}}, invoice ready.',
                'is_active' => true,
            ],
        );
    }

    public function test_blocks_example_com_addresses_during_campaign_log_creation(): void
    {
        Mail::fake();
        $this->seedEmailSettings();
        $this->actingAs($this->admin());

        $lead = CaMaster::query()->firstOrFail();
        $lead->update(['email_id' => 'dummy-lead@example.com']);

        $response = $this->postJson('/email-campaigns', [
            'campaign_name' => 'Blocked Domain Campaign',
            'campaign_type' => 'Bulk Email',
            'audience_mode' => 'selected_leads',
            'email_template_id' => $this->campaignTemplate()->id,
            'ca_ids' => [$lead->ca_id],
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('email_logs', [
            'campaign_id' => $response->json('data.id'),
            'ca_id' => $lead->ca_id,
            'email_status' => EmailRecipientValidationService::STATUS_INVALID_EMAIL,
        ]);

        Mail::assertNothingSent();
    }

    public function test_skips_duplicate_emails_within_same_campaign(): void
    {
        Mail::fake();
        $this->seedEmailSettings();
        $this->actingAs($this->admin());

        $sharedEmail = 'duplicate.campaign.'.uniqid().'@gmail.com';
        $leads = CaMaster::query()->limit(2)->get();
        $this->assertGreaterThanOrEqual(2, $leads->count());

        $leads[0]->update(['email_id' => $sharedEmail]);
        $leads[1]->update(['email_id' => $sharedEmail]);

        $response = $this->postJson('/email-campaigns', [
            'campaign_name' => 'Duplicate Email Campaign',
            'campaign_type' => 'Bulk Email',
            'audience_mode' => 'selected_leads',
            'email_template_id' => $this->campaignTemplate()->id,
            'ca_ids' => $leads->pluck('ca_id')->all(),
        ]);

        $response->assertCreated();

        $campaignId = $response->json('data.id');

        $this->assertSame(1, EmailLog::query()
            ->where('campaign_id', $campaignId)
            ->whereIn('email_status', [
                EmailRecipientValidationService::STATUS_QUEUED,
                EmailRecipientValidationService::STATUS_SENT,
            ])
            ->count());

        $this->assertSame(1, EmailLog::query()
            ->where('campaign_id', $campaignId)
            ->where('email_status', EmailRecipientValidationService::STATUS_DUPLICATE)
            ->count());

        Mail::assertSent(CrmHtmlMail::class, 1);
    }

    public function test_smtp_failure_on_one_recipient_does_not_stop_other_sends(): void
    {
        $this->seedEmailSettings();
        $this->actingAs($this->admin());

        $leads = CaMaster::query()->limit(2)->get();
        $this->assertGreaterThanOrEqual(2, $leads->count());

        $leads[0]->update(['email_id' => 'good.'.uniqid().'@gmail.com']);
        $leads[1]->update(['email_id' => 'bad.'.uniqid().'@gmail.com']);

        $call = 0;
        $this->mock(EmailSmtpDispatchService::class, function ($mock) use (&$call) {
            $mock->shouldReceive('send')
                ->twice()
                ->andReturnUsing(function () use (&$call) {
                    $call++;
                    if ($call === 2) {
                        return [
                            'success' => false,
                            'status' => EmailRecipientValidationService::STATUS_FAILED,
                            'provider_response' => ['error' => '550 Mailbox unavailable'],
                            'error_message' => '550 Mailbox unavailable',
                            'smtp_error' => '550 Mailbox unavailable',
                        ];
                    }

                    return [
                        'success' => true,
                        'status' => EmailRecipientValidationService::STATUS_SENT,
                        'provider_response' => ['sent' => true],
                        'error_message' => null,
                        'smtp_error' => null,
                    ];
                });
            $mock->shouldReceive('applyDispatchResult')->twice()->andReturnUsing(function ($log, $result) {
                $log->update([
                    'email_status' => $result['status'],
                    'smtp_error' => $result['smtp_error'] ?? null,
                    'error_message' => $result['error_message'],
                    'sent_at' => now(),
                    'delivered_at' => $result['success'] ? now() : null,
                ]);

                return $log->fresh();
            });
        });

        $response = $this->postJson('/email-campaigns', [
            'campaign_name' => 'Partial Failure Campaign',
            'campaign_type' => 'Bulk Email',
            'audience_mode' => 'selected_leads',
            'email_template_id' => $this->campaignTemplate()->id,
            'ca_ids' => $leads->pluck('ca_id')->all(),
        ]);

        $response->assertCreated();

        $campaignId = $response->json('data.id');

        $this->assertSame(1, EmailLog::query()
            ->where('campaign_id', $campaignId)
            ->where('email_status', EmailRecipientValidationService::STATUS_SENT)
            ->count());

        $this->assertSame(1, EmailLog::query()
            ->where('campaign_id', $campaignId)
            ->where('email_status', EmailRecipientValidationService::STATUS_FAILED)
            ->count());

        $campaign = EmailCampaign::query()->findOrFail($campaignId);
        $this->assertSame('Partial', $campaign->status);
    }

    public function test_campaign_statistics_are_exposed_in_api(): void
    {
        Mail::fake();
        $this->seedEmailSettings();
        $this->actingAs($this->admin());

        $lead = CaMaster::query()->whereNotNull('email_id')->firstOrFail();
        $lead->update(['email_id' => 'stats.'.uniqid().'@gmail.com']);

        $response = $this->postJson('/email-campaigns', [
            'campaign_name' => 'Stats Campaign',
            'campaign_type' => 'Bulk Email',
            'audience_mode' => 'selected_leads',
            'email_template_id' => $this->campaignTemplate()->id,
            'ca_ids' => [$lead->ca_id],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.statistics.total_leads', 1)
            ->assertJsonPath('data.statistics.emails_sent', 1);
    }
}
