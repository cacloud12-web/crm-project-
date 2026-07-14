<?php

namespace Tests\Feature;

use App\Models\FollowUp;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FollowUpActionsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_follow_ups_list_includes_followup_id_for_action_menu(): void
    {
        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $this->actingAs($manager);

        $response = $this->getJson('/follow-ups?per_page=5');
        $response->assertOk();

        $items = $response->json('data.items') ?? [];
        if ($items === []) {
            $this->markTestSkipped('No follow-ups in database');
        }

        $this->assertArrayHasKey('followup_id', $items[0]);
        $this->assertNotEmpty($items[0]['followup_id']);
    }

    public function test_follow_ups_list_includes_mobile_number_from_related_lead(): void
    {
        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $this->actingAs($manager);

        $followUp = FollowUp::query()
            ->with('caMaster:ca_id,mobile_no')
            ->whereHas('caMaster', function ($query) {
                $query->whereNotNull('mobile_no')->where('mobile_no', '!=', '');
            })
            ->first();

        if (! $followUp) {
            $this->markTestSkipped('No follow-ups with lead mobile numbers in database');
        }

        $response = $this->getJson('/follow-ups?per_page=50');
        $response->assertOk();

        $items = collect($response->json('data.items') ?? []);
        $match = $items->firstWhere('followup_id', $followUp->followup_id);

        $this->assertNotNull($match, 'Expected follow-up in listing response');
        $this->assertArrayHasKey('mobile_no', $match);
        $this->assertSame($followUp->caMaster?->mobile_no, $match['mobile_no']);

        $this->getJson('/follow-ups/'.$followUp->followup_id)
            ->assertOk()
            ->assertJsonPath('data.mobile_no', $followUp->caMaster?->mobile_no);
    }

    public function test_manager_can_view_single_follow_up(): void
    {
        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $this->actingAs($manager);

        $followUp = FollowUp::query()->first();
        if (! $followUp) {
            $this->markTestSkipped('No follow-ups in database');
        }

        $this->getJson('/follow-ups/'.$followUp->followup_id)
            ->assertOk()
            ->assertJsonPath('data.followup_id', $followUp->followup_id);
    }

    public function test_follow_ups_per_page_only_allows_standard_sizes(): void
    {
        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $this->actingAs($manager);

        foreach ([10, 25, 50, 100, 200] as $size) {
            $this->getJson('/follow-ups?per_page='.$size)
                ->assertOk()
                ->assertJsonPath('data.pagination.per_page', $size);
        }

        $this->getJson('/follow-ups?per_page=500')
            ->assertOk()
            ->assertJsonPath('data.pagination.per_page', 10);

        $this->getJson('/follow-ups?per_page=1000')
            ->assertOk()
            ->assertJsonPath('data.pagination.per_page', 10);

        $this->getJson('/follow-ups?per_page=7')
            ->assertOk()
            ->assertJsonPath('data.pagination.per_page', 10);
    }

    public function test_employee_can_view_own_follow_up(): void
    {
        $employeeUser = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employeeUser);

        $employeeId = \App\Models\Employee::query()->where('user_id', $employeeUser->id)->value('employee_id');
        $followUp = FollowUp::query()->where('employee_id', $employeeId)->first();
        if (! $followUp) {
            $this->markTestSkipped('No employee follow-ups in database');
        }

        $this->getJson('/follow-ups/'.$followUp->followup_id)
            ->assertOk();
    }

    public function test_manager_can_mark_follow_up_completed(): void
    {
        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $this->actingAs($manager);

        $followUp = FollowUp::query()
            ->whereNotIn('status', ['Completed', 'Closed', 'Done'])
            ->where(function ($q) {
                $q->whereNull('followup_type')
                    ->orWhere('followup_type', '!=', 'Demo Scheduled');
            })
            ->first();
        if (! $followUp) {
            $this->markTestSkipped('No open non-demo follow-ups in database');
        }

        $this->patchJson('/follow-ups/'.$followUp->followup_id, [
            'status' => 'Completed',
        ])->assertOk()
            ->assertJsonPath('data.status', 'Completed');
    }

    public function test_manager_can_mark_demo_scheduled_completed_when_meeting_link_exists(): void
    {
        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $this->actingAs($manager);

        $followUp = FollowUp::query()
            ->where('followup_type', 'Demo Scheduled')
            ->whereNotIn('status', ['Completed', 'Closed', 'Done'])
            ->whereNotNull('meeting_link')
            ->where('meeting_link', '!=', '')
            ->first();

        if (! $followUp) {
            $this->markTestSkipped('No open demo scheduled follow-ups with meeting link in database');
        }

        $this->patchJson('/follow-ups/'.$followUp->followup_id, [
            'status' => 'Completed',
        ])->assertOk()
            ->assertJsonPath('data.status', 'Completed');
    }

    public function test_employee_cannot_update_another_employees_follow_up(): void
    {
        $employeeUser = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employeeUser);

        $employeeId = \App\Models\Employee::query()->where('user_id', $employeeUser->id)->value('employee_id');
        $otherFollowUp = FollowUp::query()
            ->when($employeeId, fn ($q) => $q->where('employee_id', '!=', $employeeId))
            ->first();

        if (! $otherFollowUp) {
            $this->markTestSkipped('No follow-up owned by another employee');
        }

        $this->patchJson('/follow-ups/'.$otherFollowUp->followup_id, [
            'remarks' => 'Should be blocked',
        ])->assertForbidden();
    }

    public function test_manager_can_schedule_follow_up_without_create_permission(): void
    {
        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $this->actingAs($manager);

        $lead = \App\Models\CaMaster::query()->firstOrFail();
        $employeeId = \App\Models\Employee::query()->where('status', 'Active')->value('employee_id');

        $this->postJson('/follow-ups', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employeeId,
            'followup_type' => 'Follow Up Scheduled',
            'scheduled_date' => now()->addDay()->format('Y-m-d H:i:s'),
            'remarks' => 'Manager scheduled follow-up',
        ])->assertCreated();
    }
}
