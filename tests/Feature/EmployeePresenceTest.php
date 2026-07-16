<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeePresenceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('users', 'last_seen_at')) {
            $this->markTestSkipped('users.last_seen_at column is not migrated yet');
        }
    }

    public function test_login_marks_user_online(): void
    {
        $employeeUser = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        // Local DBs may have rotated demo passwords — set a known hash inside this transaction.
        $employeeUser->forceFill([
            'last_seen_at' => null,
            'password' => bcrypt('password'),
            'is_active' => true,
        ])->save();

        $this->postJson('/login', [
            'email' => 'employee@ca.local',
            'password' => 'password',
        ])->assertOk();

        $employeeUser->refresh();
        $this->assertNotNull($employeeUser->last_seen_at);
        $this->assertTrue($employeeUser->last_seen_at->greaterThanOrEqualTo(now()->subMinute()));
    }

    public function test_heartbeat_updates_own_presence_only(): void
    {
        $employeeUser = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $managerSeen = now()->subHours(2);
        $manager->forceFill(['last_seen_at' => $managerSeen])->save();
        $employeeUser->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();

        $this->actingAs($employeeUser)
            ->postJson('/auth/presence/heartbeat')
            ->assertOk()
            ->assertJsonPath('data.is_online', true);

        $employeeUser->refresh();
        $manager->refresh();

        $this->assertNotNull($employeeUser->last_seen_at);
        $this->assertTrue($employeeUser->last_seen_at->greaterThanOrEqualTo(now()->subMinute()));
        $this->assertTrue(
            $manager->last_seen_at->equalTo($managerSeen->copy()->startOfSecond())
            || $manager->last_seen_at->eq($managerSeen)
            || abs($manager->last_seen_at->diffInSeconds($managerSeen)) < 2
        );
    }

    public function test_unauthenticated_heartbeat_is_rejected(): void
    {
        $this->postJson('/auth/presence/heartbeat')->assertUnauthorized();
    }

    public function test_logout_clears_presence(): void
    {
        $employeeUser = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $employeeUser->forceFill(['last_seen_at' => now()])->save();

        $this->actingAs($employeeUser)
            ->postJson('/logout')
            ->assertOk();

        $employeeUser->refresh();
        $this->assertNull($employeeUser->last_seen_at);
    }

    public function test_bulk_assignment_employees_omit_presence_fields(): void
    {
        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $this->actingAs($manager);

        $response = $this->getJson('/lead-assignments/bulk/employees?per_page=25');
        $response->assertOk();

        $items = collect($response->json('data.items') ?? []);
        $this->assertNotEmpty($items);

        foreach ($items as $item) {
            $this->assertArrayNotHasKey('is_online', $item);
            $this->assertArrayNotHasKey('last_seen_human', $item);
        }
    }

    public function test_stale_last_seen_is_offline_in_employee_api(): void
    {
        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $this->actingAs($manager);

        $employee = Employee::query()->whereNotNull('user_id')->with('user')->first();
        if (! $employee || ! $employee->user) {
            $this->markTestSkipped('No employee with linked user found');
        }

        $employee->user->forceFill(['last_seen_at' => now()->subMinutes(30)])->save();

        $this->getJson('/employees/'.$employee->employee_id)
            ->assertOk()
            ->assertJsonPath('data.is_online', false)
            ->assertJsonPath('data.last_seen_human', 'Offline');
    }
}
