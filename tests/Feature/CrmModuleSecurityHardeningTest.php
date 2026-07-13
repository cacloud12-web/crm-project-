<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\LeadAssignmentEngine;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CrmModuleSecurityHardeningTest extends TestCase
{
    use DatabaseTransactions;

    public function test_employee_cannot_delete_assignment_they_cannot_access(): void
    {
        $employeeUser = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employeeUser);

        $employeeId = Employee::query()->where('user_id', $employeeUser->id)->value('employee_id');
        $otherAssignment = LeadAssignmentEngine::query()
            ->when($employeeId, fn ($q) => $q->where('employee_id', '!=', $employeeId))
            ->first();

        if (! $otherAssignment) {
            $this->markTestSkipped('No assignment owned by another employee');
        }

        $this->deleteJson('/lead-assignments/'.$otherAssignment->assignment_id)
            ->assertForbidden();
    }

    public function test_assignment_update_uses_scoped_find(): void
    {
        $employeeUser = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employeeUser);

        $employeeId = Employee::query()->where('user_id', $employeeUser->id)->value('employee_id');
        $otherAssignment = LeadAssignmentEngine::query()
            ->when($employeeId, fn ($q) => $q->where('employee_id', '!=', $employeeId))
            ->first();

        if (! $otherAssignment) {
            $this->markTestSkipped('No assignment owned by another employee');
        }

        $this->patchJson('/lead-assignments/'.$otherAssignment->assignment_id, [
            'reason' => 'Should be blocked',
        ])->assertForbidden();
    }

    public function test_follow_up_cannot_be_moved_to_inaccessible_lead(): void
    {
        $employeeUser = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employeeUser);

        $employeeId = Employee::query()->where('user_id', $employeeUser->id)->value('employee_id');
        $ownFollowUp = FollowUp::query()->where('employee_id', $employeeId)->first();
        $inaccessibleLead = CaMaster::query()
            ->whereDoesntHave('activeAssignment', fn ($q) => $q->where('employee_id', $employeeId))
            ->first();

        if (! $ownFollowUp || ! $inaccessibleLead) {
            $this->markTestSkipped('Missing employee follow-up or inaccessible lead fixture');
        }

        $this->patchJson('/follow-ups/'.$ownFollowUp->followup_id, [
            'ca_id' => $inaccessibleLead->ca_id,
        ])->assertForbidden();
    }

    public function test_invalid_followup_type_is_rejected(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $lead = CaMaster::query()->firstOrFail();
        $employeeId = Employee::query()->where('status', 'Active')->value('employee_id');

        $this->postJson('/follow-ups', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employeeId,
            'followup_type' => 'Totally Invalid Type',
            'scheduled_date' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['followup_type']);
    }

    public function test_employee_cannot_list_another_employees_tasks(): void
    {
        $employeeUser = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employeeUser);

        $employeeId = Employee::query()->where('user_id', $employeeUser->id)->value('employee_id');
        $otherEmployeeId = Employee::query()
            ->when($employeeId, fn ($q) => $q->where('employee_id', '!=', $employeeId))
            ->value('employee_id');

        if (! $otherEmployeeId) {
            $this->markTestSkipped('No second employee fixture');
        }

        $response = $this->getJson('/follow-ups/tasks?employee_id='.$otherEmployeeId);
        $response->assertOk();

        $taskEmployeeIds = collect($response->json('data'))
            ->pluck('employee_id')
            ->filter()
            ->unique()
            ->all();

        $this->assertNotContains((int) $otherEmployeeId, $taskEmployeeIds);
        if ($taskEmployeeIds !== []) {
            $this->assertSame([(int) $employeeId], $taskEmployeeIds);
        }
    }

    public function test_employee_cannot_reassign_follow_up_to_another_employee(): void
    {
        $employeeUser = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employeeUser);

        $employeeId = Employee::query()->where('user_id', $employeeUser->id)->value('employee_id');
        $otherEmployeeId = Employee::query()
            ->when($employeeId, fn ($q) => $q->where('employee_id', '!=', $employeeId))
            ->value('employee_id');
        $ownFollowUp = FollowUp::query()->where('employee_id', $employeeId)->first();

        if (! $ownFollowUp || ! $otherEmployeeId) {
            $this->markTestSkipped('Missing employee follow-up or second employee fixture');
        }

        $this->patchJson('/follow-ups/'.$ownFollowUp->followup_id, [
            'employee_id' => $otherEmployeeId,
        ])->assertForbidden();
    }
}
