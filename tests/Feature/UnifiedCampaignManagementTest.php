<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Models\CaMaster;
use App\Models\EmailCampaign;
use App\Models\LeadAssignmentEngine;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class UnifiedCampaignManagementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_can_list_unified_campaigns(): void
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        EmailCampaign::query()->create([
            'campaign_name' => 'Unified List Test',
            'campaign_type' => 'Newsletter',
            'audience_mode' => 'all_leads',
            'audience_label' => 'All Leads',
            'subject' => 'Hello',
            'body_template' => 'Body',
            'status' => 'Draft',
            'performed_by' => 'Admin',
            'total_emails' => 0,
        ]);

        $response = $this->getJson('/campaigns');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $items = $response->json('data.items') ?? [];
        $this->assertNotEmpty($items);
        $this->assertSame('Email', $items[0]['channel'] ?? null);
    }

    public function test_employee_cannot_see_other_users_campaigns_in_unified_list(): void
    {
        $admin = CrmTestAccounts::admin();
        $employee = CrmTestAccounts::employeeUser();

        EmailCampaign::query()->create([
            'campaign_name' => 'Admin Only Campaign',
            'campaign_type' => 'Newsletter',
            'audience_mode' => 'selected_leads',
            'audience_label' => 'Selected',
            'selected_ca_ids' => [1],
            'subject' => 'Hello',
            'body_template' => 'Body',
            'status' => 'Draft',
            'performed_by' => 'Admin',
            'created_by_user_id' => $admin->id,
            'total_emails' => 1,
        ]);

        $this->actingAs($employee);
        $response = $this->getJson('/campaigns?q=Admin+Only+Campaign')->assertOk();
        $items = $response->json('data.items') ?? [];
        $this->assertCount(0, $items);
    }

    public function test_employee_can_view_own_campaign_detail(): void
    {
        $employee = CrmTestAccounts::employeeUser();
        $lead = CaMaster::query()->whereNotNull('state_id')->firstOrFail();

        $campaign = EmailCampaign::query()->create([
            'campaign_name' => 'Employee Own Campaign',
            'campaign_type' => 'Newsletter',
            'audience_mode' => 'selected_leads',
            'audience_label' => 'Selected',
            'selected_ca_ids' => [$lead->ca_id],
            'subject' => 'Hello',
            'body_template' => 'Body',
            'status' => 'Draft',
            'performed_by' => $employee->name,
            'created_by_user_id' => $employee->id,
            'total_emails' => 1,
        ]);

        $this->actingAs($employee);
        $this->getJson('/campaigns/email/'.$campaign->id)
            ->assertOk()
            ->assertJsonPath('data.campaign_name', 'Employee Own Campaign');
    }

    public function test_employee_cannot_view_admin_campaign_detail(): void
    {
        $admin = CrmTestAccounts::admin();
        $employee = CrmTestAccounts::employeeUser();

        $campaign = EmailCampaign::query()->create([
            'campaign_name' => 'Admin Secret Campaign',
            'campaign_type' => 'Newsletter',
            'audience_mode' => 'all_leads',
            'audience_label' => 'All',
            'subject' => 'Hello',
            'body_template' => 'Body',
            'status' => 'Draft',
            'performed_by' => 'Admin',
            'created_by_user_id' => $admin->id,
            'total_emails' => 0,
        ]);

        $this->actingAs($employee);
        $this->getJson('/campaigns/email/'.$campaign->id)->assertForbidden();
    }

    public function test_duplicate_campaign_creates_draft_copy(): void
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        $campaign = EmailCampaign::query()->create([
            'campaign_name' => 'Duplicate Me',
            'campaign_type' => 'Newsletter',
            'audience_mode' => 'all_leads',
            'audience_label' => 'All',
            'subject' => 'Hello',
            'body_template' => 'Body',
            'status' => 'Completed',
            'performed_by' => 'Admin',
            'created_by_user_id' => $admin->id,
            'total_emails' => 5,
        ]);

        $response = $this->postJson('/campaigns/email/'.$campaign->id.'/duplicate')->assertCreated();

        $this->assertDatabaseHas('email_campaigns', [
            'campaign_name' => 'Duplicate Me (Copy)',
            'status' => 'Draft',
        ]);

        $this->assertNotSame($campaign->campaign_uuid, $response->json('data.campaign_uuid'));
    }
}
