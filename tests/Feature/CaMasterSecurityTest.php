<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CaMasterSecurityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_employee_cannot_delete_lead(): void
    {
        $user = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($user);

        $lead = CaMaster::query()->firstOrFail();

        $this->deleteJson('/ca-masters/'.$lead->ca_id)
            ->assertForbidden();
    }

    public function test_employee_can_create_lead_and_is_auto_assigned(): void
    {
        app(\App\Services\Rbac\RbacDatabaseService::class)->resetRoleToDefault('employee');
        app(\App\Services\Rbac\RbacMatrixService::class)->flushCache();

        $user = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $employee = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $this->actingAs($user);

        $stateId = CaMaster::query()->whereNotNull('state_id')->value('state_id');
        $cityId = CaMaster::query()->where('state_id', $stateId)->whereNotNull('city_id')->value('city_id');
        $this->assertNotNull($stateId);
        $this->assertNotNull($cityId);
        $ts = (string) microtime(true);

        $response = $this->postJson('/ca-masters', [
            'ca_name' => 'Auto Assign Test '.$ts,
            'firm_name' => 'Auto Assign Firm '.$ts,
            'mobile_no' => '9'.random_int(100000000, 999999999),
            'email_id' => 'autoassign'.$ts.'@example.com',
            'state_id' => $stateId,
            'city_id' => $cityId,
            'status' => 'New',
        ]);

        $response->assertCreated();
        $caId = (int) $response->json('data.ca_id');
        $this->assertGreaterThan(0, $caId);

        $lead = CaMaster::query()->findOrFail($caId);
        $this->assertSame('New', $lead->status);
        $this->assertSame((int) $employee->employee_id, (int) $lead->created_by_employee_id);

        $assignment = LeadAssignmentEngine::query()
            ->where('ca_id', $caId)
            ->where('status', 'Active')
            ->first();

        $this->assertNotNull($assignment);
        $this->assertSame((int) $employee->employee_id, (int) $assignment->employee_id);
    }

    public function test_deactivated_user_session_is_invalidated_on_next_request(): void
    {
        $user = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($user);

        $user->update(['is_active' => false]);

        $this->getJson('/auth/me')
            ->assertUnauthorized();

        $user->update(['is_active' => true]);
    }
}
