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

        $followUp = FollowUp::query()->whereNotIn('status', ['Completed', 'Closed', 'Done'])->first();
        if (! $followUp) {
            $this->markTestSkipped('No open follow-ups in database');
        }

        $this->patchJson('/follow-ups/'.$followUp->followup_id, [
            'status' => 'Completed',
        ])->assertOk()
            ->assertJsonPath('data.status', 'Completed');
    }
}
