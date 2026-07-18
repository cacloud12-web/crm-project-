<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Models\CaMaster;
use App\Models\City;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\FollowUpHistory;
use App\Models\FollowUpReminder;
use App\Models\LeadAssignmentEngine;
use App\Models\State;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FollowUpAutomationTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsAdmin(): User
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        return $admin;
    }

    private function createLead(): CaMaster
    {
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $suffix = (string) random_int(1000, 9999);

        return CaMaster::query()->create([
            'firm_name' => 'Automation Firm '.$suffix,
            'ca_name' => 'Automation CA '.$suffix,
            'mobile_no' => '9'.substr(str_pad((string) random_int(100000000, 999999999), 9, '0'), -9),
            'email_id' => 'auto.'.$suffix.'@test.local',
            'city_id' => $city->city_id,
            'state_id' => $state->state_id,
            'status' => 'Hot',
            'rating' => 4,
            'team_size' => 5,
        ]);
    }

    private function createEmployee(): Employee
    {
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $suffix = (string) random_int(100, 999);

        return Employee::query()->create([
            'name' => 'Automation Exec '.$suffix,
            'email_id' => 'auto.exec.'.$suffix.'@test.local',
            'mobile_no' => '8'.substr(str_pad((string) random_int(100000000, 999999999), 9, '0'), -9),
            'role' => 'Sales Executive',
            'city_id' => $city->city_id,
            'status' => 'Active',
            'date_of_joining' => now()->toDateString(),
        ]);
    }

    public function test_create_follow_up_auto_creates_task_reminders_and_history(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();
        $employee = $this->createEmployee();

        $response = $this->postJson('/follow-ups', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'followup_type' => 'Call',
            'scheduled_date' => now()->addDays(2)->setTime(11, 0)->toDateTimeString(),
            'priority' => 'High',
        ]);

        $response->assertCreated();
        $followupId = $response->json('data.followup_id');

        $this->assertDatabaseHas('tasks', [
            'followup_id' => $followupId,
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'task_source' => 'Auto Generated',
            'status' => 'Pending',
        ]);

        $this->assertTrue(
            FollowUpReminder::query()->where('followup_id', $followupId)->exists()
        );

        $this->assertTrue(
            FollowUpHistory::query()->where('ca_id', $lead->ca_id)->where('event_type', 'Follow-up Created')->exists()
        );

        $this->assertDatabaseHas('activity_logs', [
            'module_name' => 'FOLLOW_UP_MANAGEMENT',
            'action' => 'Task Created',
        ]);
    }

    public function test_no_answer_outcome_advances_day_one_sequence(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();
        $employee = $this->createEmployee();

        $current = FollowUp::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'followup_type' => 'Call',
            'scheduled_date' => now()->subDay(),
            'status' => 'Pending',
            'sequence_step' => null,
        ]);

        $response = $this->postJson('/follow-ups/call-outcome', [
            'followup_id' => $current->followup_id,
            'employee_id' => $employee->employee_id,
            'outcome' => 'No Answer',
            'remarks' => 'Rang twice',
        ]);

        $response->assertOk();
        $nextId = $response->json('data.next_follow_up.followup_id');
        $this->assertNotNull($nextId);

        $next = FollowUp::query()->findOrFail($nextId);
        $this->assertSame(1, $next->sequence_step);
        $this->assertTrue($next->is_auto_generated);
        $this->assertSame('auto_sequence', $next->source);

        $this->assertDatabaseHas('follow_up_histories', [
            'ca_id' => $lead->ca_id,
            'event_type' => 'Day 1 Follow-up Created',
        ]);
    }

    public function test_day_three_sequence_after_day_one_no_answer(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();
        $employee = $this->createEmployee();

        $dayOne = FollowUp::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'followup_type' => 'Call',
            'scheduled_date' => now()->subDay(),
            'status' => 'Pending',
            'sequence_step' => 1,
            'is_auto_generated' => true,
            'source' => 'auto_sequence',
        ]);

        $response = $this->postJson('/follow-ups/call-outcome', [
            'followup_id' => $dayOne->followup_id,
            'employee_id' => $employee->employee_id,
            'outcome' => 'No Answer',
            'remarks' => 'Still no answer on day one',
        ]);

        $response->assertOk();
        $next = FollowUp::query()->findOrFail($response->json('data.next_follow_up.followup_id'));
        $this->assertSame(3, $next->sequence_step);
    }

    public function test_overdue_command_marks_follow_ups_and_tasks(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();
        $employee = $this->createEmployee();

        $followUp = FollowUp::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'followup_type' => 'Call',
            'scheduled_date' => now()->subDays(2),
            'status' => 'Pending',
        ]);

        Task::query()->create([
            'followup_id' => $followUp->followup_id,
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'task_type' => 'Follow-up Call',
            'due_date' => now()->subDay()->toDateString(),
            'status' => 'Pending',
            'task_source' => 'Auto Generated',
        ]);

        $this->artisan('followups:process-automation')->assertSuccessful();

        $this->assertDatabaseHas('follow_ups', [
            'followup_id' => $followUp->followup_id,
            'status' => 'Overdue',
        ]);
        $this->assertDatabaseHas('tasks', [
            'followup_id' => $followUp->followup_id,
            'status' => 'Overdue',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'Overdue Follow-up',
        ]);
    }

    public function test_reschedule_logs_audit_and_notifies(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();
        $employee = $this->createEmployee();

        $followUp = FollowUp::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'followup_type' => 'Call',
            'scheduled_date' => now()->addDay()->setTime(10, 0),
            'status' => 'Pending',
        ]);

        $newDate = now()->addDays(3)->setTime(14, 30)->toDateTimeString();

        $this->putJson('/follow-ups/'.$followUp->followup_id, [
            'scheduled_date' => $newDate,
            'reschedule_reason' => 'Customer requested later slot',
        ])->assertOk();

        $this->assertDatabaseHas('follow_up_reschedule_logs', [
            'followup_id' => $followUp->followup_id,
            'reason' => 'Customer requested later slot',
        ]);

        $followUp->refresh();
        $this->assertTrue($followUp->is_rescheduled);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'Follow-up Rescheduled',
        ]);
    }

    public function test_manager_metrics_endpoint(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/follow-ups/manager-metrics')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'today',
                    'upcoming',
                    'completed_today',
                    'missed',
                    'overdue',
                    'followup_conversion_pct',
                    'demo_conversion_pct',
                    'employees',
                ],
            ]);
    }

    public function test_admin_can_update_sequence_config(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/follow-ups/sequence', [
            'sequence_days' => [1, 3, 7, 15, 30],
            'trigger_outcomes' => ['No Answer', 'Busy'],
        ])->assertOk()
            ->assertJsonPath('data.sequence_days', [1, 3, 7, 15, 30]);

        $this->assertDatabaseHas('follow_up_sequence_configs', [
            'is_active' => true,
        ]);
    }

    public function test_lead_follow_up_history_timeline(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();
        $employee = $this->createEmployee();

        LeadAssignmentEngine::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'assigned_date' => now()->toDateString(),
            'assignment_type' => 'Manual',
            'status' => 'Active',
        ]);

        $this->postJson('/follow-ups', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'followup_type' => 'Call',
            'scheduled_date' => now()->addDay()->toDateTimeString(),
        ])->assertCreated();

        $this->postJson('/workflow/calls', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'call_status' => 'Connected',
            'call_note' => 'Timeline sync check',
        ])->assertOk();

        $timeline = $this->getJson('/ca-masters/'.$lead->ca_id.'/follow-up-history')
            ->assertOk()
            ->assertJsonStructure(['success', 'data'])
            ->json('data');

        $this->assertContains('Call Logged', array_column($timeline, 'activity_type'));
        $this->assertContains('Follow-up Created', array_column($timeline, 'activity_type'));
        $this->assertCount(1, array_filter($timeline, fn ($row) => ($row['activity_type'] ?? '') === 'Call Logged'));

        $feed = $this->getJson('/follow-ups/activity-timeline?ca_id='.$lead->ca_id)
            ->assertOk()
            ->json('data.items');

        $this->assertNotEmpty($feed);
        $this->assertContains('Call Logged', array_column($feed, 'activity_type'));
    }

    public function test_activity_timeline_period_filter_and_pagination(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();
        $employee = $this->createEmployee();

        FollowUpHistory::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'event_type' => 'Call Logged',
            'outcome' => 'Connected',
            'remarks' => 'Today activity',
            'performed_by' => 'Admin',
            'created_at' => now(),
        ]);

        FollowUpHistory::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'event_type' => 'Follow-up Created',
            'outcome' => 'Open',
            'remarks' => 'Older activity',
            'performed_by' => 'Admin',
            'created_at' => now()->subDays(20),
        ]);

        $all = $this->getJson('/follow-ups/activity-timeline?ca_id='.$lead->ca_id.'&per_page=10&page=1')
            ->assertOk()
            ->json('data');

        $this->assertGreaterThanOrEqual(2, $all['pagination']['total']);
        $this->assertSame(10, $all['pagination']['per_page']);
        $this->assertArrayHasKey('from', $all['pagination']);
        $this->assertArrayHasKey('to', $all['pagination']);

        $today = $this->getJson('/follow-ups/activity-timeline?ca_id='.$lead->ca_id.'&period=today&per_page=10')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $today['pagination']['total']);
        $this->assertGreaterThan($today['pagination']['total'], $all['pagination']['total']);

        $month = $this->getJson('/follow-ups/activity-timeline?ca_id='.$lead->ca_id.'&period=this_month&per_page=10')
            ->assertOk()
            ->json('data');

        $this->assertGreaterThanOrEqual(1, $month['pagination']['total']);
    }

    public function test_activity_timeline_rejects_oversized_per_page(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();

        foreach ([10, 25, 50, 100, 200] as $size) {
            $this->getJson('/follow-ups/activity-timeline?ca_id='.$lead->ca_id.'&per_page='.$size)
                ->assertOk()
                ->assertJsonPath('data.pagination.per_page', $size);
        }

        $this->getJson('/follow-ups/activity-timeline?ca_id='.$lead->ca_id.'&per_page=500')
            ->assertOk()
            ->assertJsonPath('data.pagination.per_page', 10);

        $this->getJson('/follow-ups/activity-timeline?ca_id='.$lead->ca_id.'&per_page=1000')
            ->assertOk()
            ->assertJsonPath('data.pagination.per_page', 10);
    }

    public function test_activity_timeline_admin_feed_uses_batch_loader(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();
        $employee = $this->createEmployee();

        FollowUpHistory::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'event_type' => 'Call Logged',
            'outcome' => 'Connected',
            'remarks' => 'Batch feed activity',
            'performed_by' => 'Admin',
            'created_at' => now(),
        ]);

        $response = $this->getJson('/follow-ups/activity-timeline?per_page=10&page=1&period=today')
            ->assertOk()
            ->json('data');

        $this->assertGreaterThanOrEqual(1, $response['pagination']['total']);
        $types = array_column($response['items'] ?? [], 'activity_type');
        $this->assertContains('Call Logged', $types);
    }
}
