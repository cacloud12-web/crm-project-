<?php

namespace Tests\Feature;

use App\Models\CrmSetting;
use App\Models\User;
use App\Services\Rbac\RbacMatrixService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SecurityMatrixTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(RbacMatrixService::class)->flushCache();
    }

    public function test_super_admin_can_load_security_matrix(): void
    {
        $superAdmin = User::query()->where('email', 'superadmin@ca.local')->firstOrFail();
        $this->actingAs($superAdmin);

        $this->getJson('/admin/security-matrix')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['matrix', 'modules', 'permissions', 'can_edit', 'users'],
            ])
            ->assertJsonPath('data.can_edit', true);
    }

    public function test_manager_gets_read_only_security_matrix(): void
    {
        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $this->actingAs($manager);

        $this->getJson('/admin/security-matrix')
            ->assertForbidden();
    }

    public function test_super_admin_can_update_and_persist_permission(): void
    {
        $superAdmin = User::query()->where('email', 'superadmin@ca.local')->firstOrFail();
        $this->actingAs($superAdmin);

        $response = $this->putJson('/admin/security-matrix', [
            'role' => 'employee',
            'module' => 'reports',
            'permission' => 'export',
            'granted' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        app(RbacMatrixService::class)->flushCache();

        $reload = $this->getJson('/admin/security-matrix');
        $matrix = $reload->json('data.matrix');
        $employeeReports = $matrix['employee']['reports'] ?? [];

        $this->assertContains('export', $employeeReports);
    }

    public function test_employee_cannot_update_security_matrix(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employee);

        $this->putJson('/admin/security-matrix', [
            'role' => 'employee',
            'module' => 'reports',
            'permission' => 'export',
            'granted' => true,
        ])->assertForbidden();
    }

    public function test_admin_cannot_modify_super_admin_role(): void
    {
        $superAdmin = User::query()->where('email', 'superadmin@ca.local')->firstOrFail();
        $this->actingAs($superAdmin);

        $this->putJson('/admin/security-matrix', [
            'role' => 'super_admin',
            'module' => 'dashboard',
            'permission' => 'view',
            'granted' => false,
        ])->assertStatus(422);
    }
}
