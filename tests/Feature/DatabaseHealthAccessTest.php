<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

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
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        $this->getJson('/admin/db-health')
            ->assertForbidden();
    }

    public function test_manager_cannot_access_db_health(): void
    {
        $manager = CrmTestAccounts::manager();
        $this->actingAs($manager);

        $this->getJson('/admin/db-health')
            ->assertForbidden();
    }

    public function test_employee_cannot_access_db_health(): void
    {
        $employee = CrmTestAccounts::employeeUser();
        $this->actingAs($employee);

        $this->getJson('/admin/db-health')
            ->assertForbidden();
    }

    public function test_super_admin_can_access_db_health(): void
    {
        $superAdmin = CrmTestAccounts::superAdmin();
        $this->actingAs($superAdmin);

        $this->getJson('/admin/db-health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['tables', 'summary'],
            ]);
    }
}
