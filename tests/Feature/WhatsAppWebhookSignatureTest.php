<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class WhatsAppWebhookSignatureTest extends TestCase
{
    use DatabaseTransactions;

    public function test_webhook_receive_rejects_missing_signature_when_app_secret_configured(): void
    {
        config(['whatsapp_cloud.env_defaults.app_secret' => 'test-app-secret']);

        $payload = ['entry' => []];

        $this->postJson('/webhooks/whatsapp', $payload)
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_webhook_receive_accepts_valid_signature(): void
    {
        $secret = 'test-app-secret';
        config(['whatsapp_cloud.env_defaults.app_secret' => $secret]);

        $body = json_encode(['entry' => []], JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret);

        $this->call(
            'POST',
            '/webhooks/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $body,
        )->assertOk()->assertJsonPath('success', true);
    }

    public function test_webhook_receive_rejects_invalid_signature(): void
    {
        config(['whatsapp_cloud.env_defaults.app_secret' => 'test-app-secret']);

        $body = json_encode(['entry' => []], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/webhooks/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid',
            ],
            $body,
        )->assertForbidden();
    }

    public function test_webhook_receive_allows_unsigned_payload_in_testing_without_secret(): void
    {
        config(['whatsapp_cloud.env_defaults.app_secret' => null]);

        $this->postJson('/webhooks/whatsapp', ['entry' => []])
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
