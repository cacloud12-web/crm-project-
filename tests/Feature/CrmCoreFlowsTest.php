<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\City;
use App\Models\Employee;
use App\Models\State;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CrmCoreFlowsTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsAdmin(): User
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        return $admin;
    }

    public function test_login_page_is_accessible(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_guest_cannot_access_dashboard_metrics(): void
    {
        $response = $this->getJson('/dashboard/metrics');
        $response->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_authenticated_admin_can_load_dashboard_metrics(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/dashboard/metrics');
        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => ['total_leads', 'active_employees', 'followups_due_today'],
            ]);
    }

    public function test_ca_master_crud_via_api(): void
    {
        $admin = $this->actingAsAdmin();
        $ts = (string) microtime(true);
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();

        $create = $this->postJson('/ca-masters', [
            'firm_name' => 'Feature Test Firm '.$ts,
            'ca_name' => 'Feature CA',
            'mobile_no' => '9'.substr(str_replace('.', '', $ts), -9),
            'alternate_mobile_no' => '8'.substr(str_replace('.', '', $ts), -9),
            'email_id' => "feature.{$ts}@test.local",
            'state_id' => $state->state_id,
            'city_id' => $city->city_id,
            'status' => 'New',
            'rating' => 4,
            'team_size' => 6,
        ]);
        $create->assertCreated();
        $caId = $create->json('data.ca_id');
        $email = $create->json('data.email_id');
        $mobile = $create->json('data.mobile_no');
        $alternateMobile = $create->json('data.alternate_mobile_no');
        $this->assertNotNull($caId);
        $this->assertSame('8'.substr(str_replace('.', '', $ts), -9), $alternateMobile);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'Add Lead',
            'performed_by' => $admin->name,
        ]);

        $this->putJson("/ca-masters/{$caId}", [
            'firm_name' => 'Feature Test Firm Updated '.$ts,
            'ca_name' => 'Feature CA Updated',
            'mobile_no' => $mobile,
            'alternate_mobile_no' => '7'.substr(str_replace('.', '', $ts), -9),
            'email_id' => $email,
            'state_id' => $state->state_id,
            'city_id' => $city->city_id,
            'status' => 'Warm',
        ])->assertOk();

        $this->patchJson("/ca-masters/{$caId}/contact", [
            'mobile_no' => $mobile,
            'alternate_mobile_no' => '6'.substr(str_replace('.', '', $ts), -9),
            'email_id' => $email,
            'website' => 'https://feature-test.example',
        ])->assertOk()
            ->assertJsonPath('data.website', 'https://feature-test.example');

        $this->deleteJson("/ca-masters/{$caId}")->assertOk();
    }

    public function test_ca_master_can_be_created_without_mobile_number(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();

        $create = $this->postJson('/ca-masters', [
            'firm_name' => 'No Mobile Firm '.$ts,
            'ca_name' => 'No Mobile CA',
            'mobile_no' => '',
            'email_id' => "no.mobile.{$ts}@test.local",
            'state_id' => $state->state_id,
            'city_id' => $city->city_id,
            'status' => 'New',
            'rating' => 3,
            'team_size' => 4,
        ]);

        $create->assertCreated();
        $caId = $create->json('data.ca_id');
        $this->assertNotNull($caId);
        $this->assertNull($create->json('data.mobile_no'));

        $mobile = '9'.substr(str_replace('.', '', $ts), -9);
        $this->putJson("/ca-masters/{$caId}", [
            'firm_name' => 'No Mobile Firm '.$ts,
            'ca_name' => 'No Mobile CA',
            'mobile_no' => $mobile,
            'email_id' => "no.mobile.{$ts}@test.local",
            'state_id' => $state->state_id,
            'city_id' => $city->city_id,
            'status' => 'New',
        ])->assertOk()
            ->assertJsonPath('data.mobile_no', $mobile);

        $this->deleteJson("/ca-masters/{$caId}")->assertOk();
    }

    public function test_employee_crud_via_api(): void
    {
        $admin = $this->actingAsAdmin();
        $ts = (string) microtime(true);

        $create = $this->postJson('/employees', [
            'name' => 'Feature Employee '.$ts,
            'email_id' => "feature.emp.{$ts}@test.local",
            'mobile_no' => '8'.substr(str_replace('.', '', $ts), -9),
            'role' => 'Sales Executive',
            'crm_role' => 'employee',
            'password' => 'FeaturePass123',
            'password_confirmation' => 'FeaturePass123',
            'status' => 'Active',
        ]);
        $create->assertCreated();
        $employeeId = $create->json('data.employee_id');
        $email = $create->json('data.email_id');
        $mobile = $create->json('data.mobile_no');

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'Add Employee',
            'performed_by' => $admin->name,
        ]);

        $this->putJson("/employees/{$employeeId}", [
            'name' => 'Feature Employee Updated '.$ts,
            'email_id' => $email,
            'mobile_no' => $mobile,
            'status' => 'Active',
        ])->assertOk();

        $this->deleteJson("/employees/{$employeeId}")->assertOk();
    }

    public function test_lead_assignment_and_follow_up(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);

        $lead = CaMaster::query()->create([
            'firm_name' => 'Assign Test '.$ts,
            'ca_name' => 'Assign CA',
            'mobile_no' => '7'.substr(str_replace('.', '', $ts), -9),
            'email_id' => "assign.{$ts}@test.local",
            'status' => 'New',
        ]);

        $employee = Employee::query()->where('email_id', 'employee@ca.local')->first();
        $this->assertNotNull($employee);

        $this->postJson('/lead-assignments', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'assignment_type' => 'Manual',
            'reason' => 'FEATURE_TEST',
        ])->assertCreated();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'Lead Assignment',
        ]);

        $followUp = $this->postJson('/follow-ups', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'followup_type' => 'Call',
            'scheduled_date' => now()->addDay()->toDateTimeString(),
            'status' => 'Scheduled',
            'remarks' => 'Feature test follow-up',
        ]);
        $followUp->assertCreated();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'Follow-up Create',
        ]);
    }

    public function test_employee_rbac_scopes_ca_master_list(): void
    {
        $employeeUser = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employeeUser);

        $response = $this->getJson('/ca-masters?per_page=50');
        $response->assertOk();

        $items = $response->json('data.items') ?? [];
        foreach ($items as $item) {
            $this->assertArrayHasKey('ca_id', $item);
        }
    }

    public function test_dashboard_metrics_match_database_for_admin(): void
    {
        $this->actingAsAdmin();

        $this->flushCrmCachesForTesting();
        $metrics = app(DashboardService::class)->metrics();
        $dbTotal = CaMaster::query()
            ->countableInStatistics()
            ->count();

        $this->assertSame($dbTotal, (int) $metrics['total_leads']);
    }

    public function test_whatsapp_campaign_create_simulation(): void
    {
        $admin = $this->actingAsAdmin();
        $ts = (string) microtime(true);

        $response = $this->postJson('/whatsapp-campaigns', [
            'campaign_name' => 'Feature WA '.$ts,
            'campaign_type' => 'Demo Confirmation',
            'audience_mode' => 'all_leads',
            'message_template' => 'Hello {{name}} (test)',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'WhatsApp Campaign Create',
            'performed_by' => $admin->name,
        ]);
    }
}
