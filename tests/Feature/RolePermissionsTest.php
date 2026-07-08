<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Rbac\RbacMatrixService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class RolePermissionsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(RbacMatrixService::class)->flushCache();
    }

    public function test_super_admin_can_load_role_permissions_matrix(): void
    {
        $superAdmin = User::query()->where('email', 'superadmin@ca.local')->firstOrFail();
        $this->actingAs($superAdmin);

        $this->getJson('/admin/role-permissions')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['matrix', 'modules', 'permissions', 'can_edit'],
            ])
            ->assertJsonPath('data.can_edit', true);
    }

    public function test_manager_cannot_access_role_permissions_api(): void
    {
        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $this->actingAs($manager);

        $this->getJson('/admin/role-permissions')->assertForbidden();
    }

    public function test_employee_cannot_access_role_permissions_api(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employee);

        $this->getJson('/admin/role-permissions')->assertForbidden();
    }

    public function test_super_admin_can_save_and_reset_role_permissions(): void
    {
        $superAdmin = User::query()->where('email', 'superadmin@ca.local')->firstOrFail();
        $this->actingAs($superAdmin);

        $this->putJson('/admin/role-permissions', [
            'role' => 'employee',
            'grants' => [
                'dashboard' => ['view'],
                'leads' => ['view', 'edit'],
                'followups' => ['view', 'schedule_followup'],
            ],
        ])->assertOk()->assertJsonPath('success', true);

        app(RbacMatrixService::class)->flushCache();

        $matrix = $this->getJson('/admin/role-permissions')->json('data.matrix');
        $this->assertContains('schedule_followup', $matrix['employee']['followups'] ?? []);

        $this->postJson('/admin/role-permissions/reset', [
            'role' => 'employee',
        ])->assertOk()->assertJsonPath('success', true);
    }

    public function test_admin_cannot_update_role_permissions(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $this->putJson('/admin/role-permissions', [
            'role' => 'employee',
            'grants' => ['dashboard' => ['view']],
        ])->assertForbidden();
    }
}
