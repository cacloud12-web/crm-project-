<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class WhatsAppCloudMappingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_can_load_whatsapp_settings_without_access_token(): void
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        $response = $this->getJson('/whatsapp-settings');

        $response->assertOk()
            ->assertJsonPath('data.provider_name', 'Meta WhatsApp Cloud API')
            ->assertJsonStructure(['data' => ['has_access_token', 'can_edit', 'api_version']]);

        $this->assertArrayNotHasKey('access_token', $response->json('data'));
    }

    public function test_employee_cannot_access_whatsapp_settings(): void
    {
        $employee = CrmTestAccounts::employeeUser();
        $this->actingAs($employee);

        $this->getJson('/whatsapp-settings')->assertForbidden();
    }

    public function test_approved_whatsapp_templates_are_listed(): void
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        $this->getJson('/message-templates/whatsapp')
            ->assertOk()
            ->assertJsonStructure(['data' => [['template_name', 'language_code', 'body_template']]]);
    }
}
