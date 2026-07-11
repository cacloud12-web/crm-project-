<?php

namespace Tests\Feature;

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
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
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
}
