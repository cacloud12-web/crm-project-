<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Models\CaMaster;
use App\Models\City;
use App\Models\DemoProvider;
use App\Models\DemoSchedule;
use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DemoCalendarTest extends TestCase
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

        return CaMaster::query()->create([
            'firm_name' => 'Calendar Firm '.random_int(1000, 9999),
            'ca_name' => 'Calendar CA',
            'mobile_no' => '9'.substr(str_pad((string) random_int(100000000, 999999999), 9, '0'), -9),
            'email_id' => 'cal.'.random_int(1000, 9999).'@test.local',
            'city_id' => $city->city_id,
            'state_id' => $state->state_id,
            'status' => 'Hot',
            'team_size' => 1,
        ]);
    }

    private function createEmployee(): Employee
    {
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();

        return Employee::query()->create([
            'name' => 'Calendar Exec '.random_int(100, 999),
            'email_id' => 'cal.exec.'.random_int(100, 999).'@test.local',
            'mobile_no' => '8'.substr(str_pad((string) random_int(100000000, 999999999), 9, '0'), -9),
            'role' => 'Sales Executive',
            'city_id' => $city->city_id,
            'status' => 'Active',
            'date_of_joining' => now()->toDateString(),
        ]);
    }

    private function provider(): DemoProvider
    {
        return DemoProvider::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail();
    }

    public function test_calendar_summary_and_events_load(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/demo-calendar/summary?date='.now()->toDateString())
            ->assertOk()
            ->assertJsonStructure(['success', 'data' => ['total_demos', 'available_slots']]);

        $this->getJson('/demo-calendar/events?view=week&date='.now()->toDateString())
            ->assertOk()
            ->assertJsonStructure(['success', 'data']);
    }

    public function test_double_booking_same_provider_is_blocked(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();
        $employee = $this->createEmployee();
        $provider = $this->provider();

        LeadAssignmentEngine::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'assigned_date' => now()->toDateString(),
            'assignment_type' => 'Manual',
            'status' => 'Active',
        ]);

        $demoAt = now()->addDay()->setTime(16, 0);
        DemoSchedule::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'demo_provider_id' => $provider->id,
            'demo_provider_name' => $provider->name,
            'demo_at' => $demoAt,
            'demo_end_at' => $demoAt->copy()->addHour(),
            'meeting_link' => $provider->default_meeting_link,
            'status' => DemoSchedule::STATUS_SCHEDULED,
            'customer_name' => $lead->ca_name,
            'firm_name' => $lead->firm_name,
        ]);

        $leadTwo = $this->createLead();
        $check = $this->postJson('/demo-calendar/check-conflict', [
            'ca_id' => $leadTwo->ca_id,
            'demo_provider_id' => $provider->id,
            'demo_at' => $demoAt->toDateTimeString(),
        ])->assertOk()->json('data');

        $this->assertFalse($check['available']);
        $this->assertNotEmpty($check['conflict']['message'] ?? null);
    }

    public function test_available_slots_returns_open_times(): void
    {
        $this->actingAsAdmin();
        $provider = $this->provider();
        $date = Carbon::now()->next('Monday')->toDateString();

        $this->getJson('/demo-calendar/available-slots?demo_provider_id='.$provider->id.'&date='.$date)
            ->assertOk()
            ->assertJsonPath('data.provider.id', $provider->id);
    }

    public function test_sunday_schedule_is_blocked_by_company_rules(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();
        $provider = $this->provider();
        $sunday = Carbon::parse('next Sunday')->toDateString();

        $response = $this->postJson('/demo-calendar/check-conflict', [
            'ca_id' => $lead->ca_id,
            'demo_provider_id' => $provider->id,
            'demo_at' => $sunday.' 10:00:00',
            'demo_end_at' => $sunday.' 11:00:00',
        ])->assertOk()->json('data');

        $this->assertFalse($response['available']);
        $this->assertSame('Demos cannot be scheduled on Sundays.', $response['conflict']['message'] ?? null);
    }

    public function test_before_ten_am_is_blocked_by_company_rules(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();
        $provider = $this->provider();
        $monday = Carbon::now()->next('Monday')->toDateString();

        $response = $this->postJson('/demo-calendar/check-conflict', [
            'ca_id' => $lead->ca_id,
            'demo_provider_id' => $provider->id,
            'demo_at' => $monday.' 09:30:00',
            'demo_end_at' => $monday.' 10:30:00',
        ])->assertOk()->json('data');

        $this->assertFalse($response['available']);
        $this->assertSame('Demo start time must be 10:00 AM or later.', $response['conflict']['message'] ?? null);
    }

    public function test_demo_provider_can_be_deleted_from_settings(): void
    {
        $this->actingAsAdmin();

        $provider = DemoProvider::query()->create([
            'name' => 'Delete Me '.random_int(100, 999),
            'default_meeting_link' => 'https://meet.google.com/test-delete',
            'slot_duration_minutes' => 60,
            'buffer_minutes' => 15,
            'max_demos_per_day' => 6,
            'work_start_time' => '10:00:00',
            'work_end_time' => '18:00:00',
            'break_start_time' => '13:00:00',
            'break_end_time' => '14:00:00',
            'working_days' => [1, 2, 3, 4, 5, 6],
            'is_active' => true,
            'sort_order' => 999,
        ]);

        $this->deleteJson('/demo-calendar/providers/'.$provider->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('demo_providers', ['id' => $provider->id]);
    }
}
