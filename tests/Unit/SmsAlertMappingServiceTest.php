<?php

namespace Tests\Unit;

use App\Models\CaMaster;
use App\Models\SmsSetting;
use App\Models\User;
use App\Services\Sms\SmsAlertMappingService;
use App\Services\Sms\SmsSettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SmsAlertMappingServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_build_push_payload_maps_sms_alert_fields(): void
    {
        $settings = new SmsSetting([
            'provider_name' => 'SMS Alert',
            'api_url' => SmsSetting::DEFAULT_API_URL,
            'api_key' => 'manager-provided-key',
            'sender_id' => 'CACLDS',
            'mode' => SmsSetting::MODE_SIMULATION,
        ]);

        $service = app(SmsAlertMappingService::class);

        $payload = $service->buildPushPayload($settings, '+91 98765 43210', 'Hello {{name}}');

        $this->assertSame([
            'apikey' => 'manager-provided-key',
            'sender' => 'CACLDS',
            'mobileno' => '9876543210',
            'text' => 'Hello {{name}}',
        ], $payload);
    }

    public function test_mask_payload_hides_sensitive_fields(): void
    {
        $service = app(SmsAlertMappingService::class);

        $masked = $service->maskPayloadForDisplay([
            'apikey' => 'secret-key',
            'sender' => 'TESTID',
            'mobileno' => '9876543210',
            'text' => 'Hello Client',
        ]);

        $this->assertSame('******', $masked['apikey']);
        $this->assertSame('******', $masked['sender']);
        $this->assertSame('9876543210', $masked['mobileno']);
    }

    public function test_deduplicate_leads_by_mobile(): void
    {
        $service = app(SmsAlertMappingService::class);
        $leads = collect([
            new CaMaster(['ca_id' => 1, 'mobile_no' => '9876543210']),
            new CaMaster(['ca_id' => 2, 'mobile_no' => '9876543210']),
            new CaMaster(['ca_id' => 3, 'mobile_no' => '9123456789']),
        ]);

        $deduped = $service->deduplicateLeadsByMobile($leads);

        $this->assertCount(2, $deduped);
    }

    public function test_validation_fails_when_settings_or_mobile_missing(): void
    {
        $service = app(SmsAlertMappingService::class);
        $settings = new SmsSetting([
            'api_url' => SmsSetting::DEFAULT_API_URL,
            'api_key' => null,
            'sender_id' => null,
        ]);

        $errors = $service->validateDispatchPrerequisites($settings, null, '');

        $this->assertContains('SMS API Key is not configured.', $errors);
        $this->assertContains('SMS Sender ID is not configured.', $errors);
        $this->assertContains(SmsAlertMappingService::ERROR_MESSAGE_REQUIRED, $errors);
        $this->assertContains(SmsAlertMappingService::ERROR_MOBILE_REQUIRED, $errors);
    }

    public function test_lead_mobile_validation_error_messages(): void
    {
        $service = app(SmsAlertMappingService::class);

        $this->assertSame(
            SmsAlertMappingService::ERROR_MOBILE_REQUIRED,
            $service->leadMobileValidationError(null),
        );
        $this->assertSame(
            SmsAlertMappingService::ERROR_MOBILE_INVALID,
            $service->leadMobileValidationError('12345'),
        );
        $this->assertNull($service->leadMobileValidationError('9876543210'));
    }

    public function test_prepare_for_lead_stores_mapped_payload_without_sending(): void
    {
        $settings = SmsSetting::query()->create([
            'provider_name' => 'SMS Alert',
            'api_url' => SmsSetting::DEFAULT_API_URL,
            'api_key' => 'test-key-from-manager',
            'sender_id' => 'TESTID',
            'mode' => SmsSetting::MODE_SIMULATION,
            'is_active' => true,
        ]);

        $lead = CaMaster::query()->firstOrFail();
        $lead->update(['mobile_no' => '9876543210']);

        $service = app(SmsAlertMappingService::class);
        $prepared = $service->prepareForLead($lead, 'Test message', $settings);

        $this->assertTrue($prepared['valid']);
        $this->assertSame('9876543210', $prepared['payload']['mobileno']);
        $this->assertStringContainsString('mapped_not_sent', (string) $prepared['provider_response']);
    }

    public function test_settings_service_masks_api_key_in_public_array(): void
    {
        $settings = SmsSetting::query()->create([
            'provider_name' => 'SMS Alert',
            'api_url' => SmsSetting::DEFAULT_API_URL,
            'api_key' => 'secret-key',
            'sender_id' => 'TESTID',
            'mode' => SmsSetting::MODE_SIMULATION,
            'is_active' => true,
        ]);

        $service = app(SmsSettingsService::class);
        $public = $service->toPublicArray($settings);

        $this->assertArrayNotHasKey('api_key', $public);
        $this->assertTrue($public['has_api_key']);
        $this->assertSame('connected', $public['integration_status']);
    }

    public function test_integration_status_not_configured_without_credentials(): void
    {
        $settings = new SmsSetting([
            'api_url' => SmsSetting::DEFAULT_API_URL,
            'sender_id' => null,
            'is_active' => true,
        ]);

        $this->assertSame('not_configured', app(SmsSettingsService::class)->integrationStatus($settings));
    }

    public function test_integration_status_disabled_when_inactive(): void
    {
        $settings = new SmsSetting([
            'api_key' => 'secret',
            'sender_id' => 'TESTID',
            'is_active' => false,
        ]);

        $this->assertSame('disabled', app(SmsSettingsService::class)->integrationStatus($settings));
    }

    public function test_manager_cannot_manage_sms_settings(): void
    {
        $manager = User::query()->where('crm_role', 'manager')->first();
        if (! $manager) {
            $this->markTestSkipped('No manager user in database.');
        }

        $this->expectException(AuthorizationException::class);
        app(SmsSettingsService::class)->ensureCanManageSettings($manager);
    }

    public function test_settings_service_defaults_leave_credentials_empty(): void
    {
        SmsSetting::query()->delete();

        $settings = app(SmsSettingsService::class)->current();

        $this->assertSame('SMS Alert', $settings->provider_name);
        $this->assertSame(SmsSetting::DEFAULT_API_URL, $settings->api_url);
        $this->assertFalse($settings->hasApiKey());
        $this->assertNull($settings->sender_id);
        $this->assertSame(SmsSetting::MODE_SIMULATION, $settings->mode);
    }
}
