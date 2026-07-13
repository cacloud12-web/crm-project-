<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DatabaseHealthAccessTest extends TestCase
{
    use DatabaseTransactions;

    public function test_db_health_requires_authentication(): void
    {
        $this->getJson('/admin/db-health')
            ->assertUnauthorized();
    }

    public function test_admin_cannot_access_db_health(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $this->getJson('/admin/db-health')
            ->assertForbidden();
    }

    public function test_manager_cannot_access_db_health(): void
    {
        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $this->actingAs($manager);

        $this->getJson('/admin/db-health')
            ->assertForbidden();
    }

    public function test_employee_cannot_access_db_health(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employee);

        $this->getJson('/admin/db-health')
            ->assertForbidden();
    }

    public function test_super_admin_can_access_db_health(): void
    {
        $superAdmin = User::query()->where('email', 'superadmin@ca.local')->firstOrFail();
        $this->actingAs($superAdmin);

        $this->getJson('/admin/db-health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['tables', 'summary'],
            ]);
    }
}
