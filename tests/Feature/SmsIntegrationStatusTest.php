<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\SmsSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsIntegrationStatusTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::query()->where('email', 'admin@ca.local')->firstOrFail();
    }

    private function seedSmsSettings(array $overrides = []): SmsSetting
    {
        SmsSetting::query()->delete();

        return SmsSetting::query()->create(array_merge([
            'provider_name' => 'SMS Alert',
            'api_url' => SmsSetting::DEFAULT_API_URL,
            'api_key' => 'test-api-key',
            'sender_id' => 'CACLOD',
            'dlt_template_id' => '1107161234567890123',
            'mode' => SmsSetting::MODE_LIVE,
            'is_active' => true,
            'integration_status' => SmsSetting::INTEGRATION_CONNECTED,
        ], $overrides));
    }

    public function test_integration_status_not_configured_without_credentials(): void
    {
        SmsSetting::query()->delete();
        SmsSetting::query()->create([
            'provider_name' => 'SMS Alert',
            'api_url' => SmsSetting::DEFAULT_API_URL,
            'api_key' => null,
            'sender_id' => null,
            'mode' => SmsSetting::MODE_SIMULATION,
            'is_active' => true,
            'integration_status' => SmsSetting::INTEGRATION_NOT_CONFIGURED,
        ]);

        $this->actingAs($this->admin());

        $response = $this->getJson('/sms-settings');

        $response->assertOk();
        $response->assertJsonPath('data.integration_status', 'not_configured');
        $response->assertJsonPath('data.has_api_key', false);
    }

    public function test_integration_status_connected_when_credentials_saved_without_live_test(): void
    {
        $this->seedSmsSettings([
            'integration_status' => SmsSetting::INTEGRATION_CONNECTED,
            'last_tested_at' => null,
        ]);

        $this->actingAs($this->admin());

        $response = $this->getJson('/sms-settings');

        $response->assertOk();
        $response->assertJsonPath('data.integration_status', 'connected');
        $response->assertJsonPath('data.has_api_key', true);
        $response->assertJsonPath('data.sender_id', 'CACLOD');
    }

    public function test_validate_configuration_does_not_call_sms_alert_api(): void
    {
        $this->seedSmsSettings();

        Http::fake();

        $this->actingAs($this->admin());

        $response = $this->postJson('/sms-settings/validate');

        $response->assertOk();
        $response->assertJsonPath('data.valid', true);
        $response->assertJsonPath('data.settings.can_send_live', true);
        Http::assertNothingSent();
    }

    public function test_successful_sms_connection_test_sets_integrated_status(): void
    {
        $this->seedSmsSettings();

        Http::fake([
            SmsSetting::DEFAULT_API_URL => Http::response([
                'status' => 'success',
                'message' => 'Message sent successfully',
                'message_id' => 'MSG123',
            ], 200),
        ]);

        $this->actingAs($this->admin());

        $response = $this->postJson('/sms-settings/test-connection', [
            'mobileno' => '9876543210',
            'text' => 'CRM SMS Alert connection test',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.success', true);
        $response->assertJsonPath('data.settings.integration_status', 'integrated');
        $response->assertJsonPath('data.settings.last_test_status', 'success');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === SmsSetting::DEFAULT_API_URL
                && ($body['apikey'] ?? null) === 'test-api-key'
                && ($body['sender'] ?? null) === 'CACLOD'
                && ($body['mobileno'] ?? null) === '9876543210'
                && filled($body['text'] ?? null)
                && ($body['templateid'] ?? null) === '1107161234567890123';
        });

        $this->assertDatabaseHas('sms_settings', [
            'integration_status' => SmsSetting::INTEGRATION_INTEGRATED,
            'last_test_status' => 'success',
        ]);

        $this->assertTrue(
            ActivityLog::query()
                ->where('action', 'SMS Test Successful')
                ->exists()
        );
    }

    public function test_failed_sms_connection_test_sets_failed_status(): void
    {
        $this->seedSmsSettings([
            'integration_status' => SmsSetting::INTEGRATION_INTEGRATED,
            'last_test_status' => 'success',
        ]);

        Http::fake([
            SmsSetting::DEFAULT_API_URL => Http::response([
                'status' => 'error',
                'description' => 'Invalid API key',
            ], 200),
        ]);

        $this->actingAs($this->admin());

        $response = $this->postJson('/sms-settings/test-connection', [
            'mobileno' => '9876543210',
            'text' => 'CRM SMS Alert connection test',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.success', false);
        $response->assertJsonPath('data.settings.integration_status', 'failed');
        $response->assertJsonPath('data.settings.last_test_status', 'failed');
        $response->assertJsonPath('data.message', 'Invalid API key');

        $this->assertTrue(
            ActivityLog::query()
                ->where('action', 'SMS Test Failed')
                ->exists()
        );
    }

    public function test_public_sms_settings_response_never_exposes_api_key(): void
    {
        $this->seedSmsSettings([
            'integration_status' => SmsSetting::INTEGRATION_INTEGRATED,
            'last_test_status' => 'success',
            'last_test_response' => json_encode(['status' => 'success']),
        ]);

        $this->actingAs($this->admin());

        $response = $this->getJson('/sms-settings');

        $response->assertOk();
        $payload = $response->json('data');
        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey('api_key', $payload);
        $this->assertArrayNotHasKey('last_test_response', $payload);
        $this->assertTrue($payload['has_api_key']);
    }
}
