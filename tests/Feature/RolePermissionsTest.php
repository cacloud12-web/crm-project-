<?php

namespace Tests\Feature;

use App\Services\Rbac\PermissionService;
use App\Services\Rbac\RbacMatrixService;
use App\Services\Rbac\RbacService;
use App\Services\Rbac\RbacUserOverrideService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesCrmUsers;
use Tests\TestCase;

class RolePermissionsTest extends TestCase
{
    use CreatesCrmUsers;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(RbacMatrixService::class)->flushCache();
    }

    public function test_super_admin_can_load_role_permissions_matrix(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $this->actingAs($superAdmin);

        $this->getJson('/admin/role-permissions')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['matrix', 'modules', 'permissions', 'can_edit'],
            ])
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.supports_user_overrides', true);
    }

    public function test_manager_cannot_access_role_permissions_api(): void
    {
        $manager = $this->createManager();
        $this->actingAs($manager);

        $this->getJson('/admin/role-permissions')->assertForbidden();
    }

    public function test_employee_cannot_access_role_permissions_api(): void
    {
        $employee = $this->createEmployeeUser();
        $this->actingAs($employee);

        $this->getJson('/admin/role-permissions')->assertForbidden();
    }

    public function test_super_admin_can_save_and_reset_role_permissions(): void
    {
        $superAdmin = $this->createSuperAdmin();
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
        $admin = $this->createAdmin();
        $this->actingAs($admin);

        $this->putJson('/admin/role-permissions', [
            'role' => 'employee',
            'grants' => ['dashboard' => ['view']],
        ])->assertForbidden();
    }

    public function test_legacy_campaigns_action_is_normalized_and_save_succeeds(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $this->actingAs($superAdmin);

        $response = $this->putJson('/admin/role-permissions', [
            'role' => 'employee',
            'grants' => [
                'dashboard' => ['view'],
                'leads' => ['view'],
                'campaigns' => ['campaigns'],
            ],
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        app(RbacMatrixService::class)->flushCache();
        $matrix = $this->getJson('/admin/role-permissions')->json('data.matrix');
        $campaigns = $matrix['employee']['campaigns'] ?? [];

        $this->assertNotContains('campaigns', $campaigns);
        $this->assertContains('view', $campaigns);
        $this->assertContains('send_email', $campaigns);
        $this->assertContains('send_sms', $campaigns);
    }

    public function test_employee_permissions_persist_after_reload(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $this->actingAs($superAdmin);

        $this->putJson('/admin/role-permissions', [
            'role' => 'employee',
            'grants' => [
                'dashboard' => ['view'],
                'leads' => ['view', 'edit'],
                'campaigns' => [],
            ],
        ])->assertOk();

        app(RbacMatrixService::class)->flushCache();

        $matrix = $this->getJson('/admin/role-permissions')->json('data.matrix');
        $this->assertSame([], $matrix['employee']['campaigns'] ?? []);
    }

    public function test_employee_without_communication_view_gets_api_403(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $employee = $this->createEmployeeUser();

        $this->actingAs($superAdmin);
        $this->putJson('/admin/role-permissions', [
            'role' => 'employee',
            'grants' => [
                'dashboard' => ['view'],
                'leads' => ['view'],
                'campaigns' => [],
            ],
        ])->assertOk();

        app(RbacMatrixService::class)->flushCache();

        $this->actingAs($employee);
        $this->assertFalse(app(RbacService::class)->can($employee, 'campaigns', 'view'));
        $this->getJson('/campaigns')->assertForbidden();
        $this->getJson('/email-campaigns')->assertForbidden();
    }

    public function test_employee_with_view_but_without_send_email_cannot_send(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $employee = $this->createEmployeeUser();

        $this->actingAs($superAdmin);
        $this->putJson('/admin/role-permissions', [
            'role' => 'employee',
            'grants' => [
                'dashboard' => ['view'],
                'leads' => ['view'],
                'campaigns' => ['view'],
            ],
        ])->assertOk();

        app(RbacMatrixService::class)->flushCache();

        $rbac = app(RbacService::class);
        $this->assertTrue($rbac->can($employee->fresh(), 'campaigns', 'view'));
        $this->assertFalse($rbac->can($employee->fresh(), 'campaigns', 'send_email'));

        $this->actingAs($employee);
        $this->getJson('/campaigns')->assertOk();
        $this->postJson('/email-campaigns', [])->assertForbidden();
    }

    public function test_user_allow_override_grants_communication_when_role_denies(): void
    {
        if (! Schema::hasTable('crm_user_permission_overrides')) {
            $this->markTestSkipped('crm_user_permission_overrides table missing');
        }

        $superAdmin = $this->createSuperAdmin();
        $allowedEmployee = $this->createEmployeeUser(['name' => 'Test User Allowed']);
        $deniedEmployee = $this->createEmployeeUser(['name' => 'Test User Baseline']);

        $this->actingAs($superAdmin);
        $this->putJson('/admin/role-permissions', [
            'role' => 'employee',
            'grants' => [
                'dashboard' => ['view'],
                'leads' => ['view'],
                'campaigns' => [],
            ],
        ])->assertOk();

        $this->putJson('/admin/role-permissions/users', [
            'user_id' => $allowedEmployee->id,
            'allows' => ['campaigns' => ['view']],
            'denies' => [],
        ])->assertOk()->assertJsonPath('success', true);

        app(RbacMatrixService::class)->flushCache();
        app(RbacUserOverrideService::class)->forgetUserCache($allowedEmployee->id);
        app(RbacUserOverrideService::class)->forgetUserCache($deniedEmployee->id);

        $rbac = app(RbacService::class);
        $this->assertTrue($rbac->can($allowedEmployee->fresh(), 'campaigns', 'view'));
        $this->assertFalse($rbac->can($deniedEmployee->fresh(), 'campaigns', 'view'));
        $this->assertTrue(app(PermissionService::class)->can($allowedEmployee->fresh(), 'communication.view'));

        $this->actingAs($allowedEmployee);
        $this->getJson('/campaigns')->assertOk();

        $this->actingAs($deniedEmployee);
        $this->getJson('/campaigns')->assertForbidden();
    }

    public function test_user_deny_override_blocks_communication_when_role_allows(): void
    {
        if (! Schema::hasTable('crm_user_permission_overrides')) {
            $this->markTestSkipped('crm_user_permission_overrides table missing');
        }

        $superAdmin = $this->createSuperAdmin();
        $blockedEmployee = $this->createEmployeeUser(['name' => 'Test User Blocked']);
        $otherEmployee = $this->createEmployeeUser(['name' => 'Test User Other']);

        $this->actingAs($superAdmin);
        $this->putJson('/admin/role-permissions', [
            'role' => 'employee',
            'grants' => [
                'dashboard' => ['view'],
                'leads' => ['view'],
                'campaigns' => ['view', 'send_email', 'send_sms'],
            ],
        ])->assertOk();

        $this->putJson('/admin/role-permissions/users', [
            'user_id' => $blockedEmployee->id,
            'allows' => [],
            'denies' => ['campaigns' => ['view']],
        ])->assertOk();

        app(RbacMatrixService::class)->flushCache();
        app(RbacUserOverrideService::class)->forgetUserCache($blockedEmployee->id);
        app(RbacUserOverrideService::class)->forgetUserCache($otherEmployee->id);

        $rbac = app(RbacService::class);
        $this->assertFalse($rbac->can($blockedEmployee->fresh(), 'campaigns', 'view'));
        $this->assertTrue($rbac->can($otherEmployee->fresh(), 'campaigns', 'view'));

        $this->actingAs($blockedEmployee);
        $this->getJson('/campaigns')->assertForbidden();
    }

    public function test_reset_user_overrides_returns_to_role_defaults(): void
    {
        if (! Schema::hasTable('crm_user_permission_overrides')) {
            $this->markTestSkipped('crm_user_permission_overrides table missing');
        }

        $superAdmin = $this->createSuperAdmin();
        $employee = $this->createEmployeeUser();

        $this->actingAs($superAdmin);
        $this->putJson('/admin/role-permissions', [
            'role' => 'employee',
            'grants' => [
                'dashboard' => ['view'],
                'campaigns' => [],
            ],
        ])->assertOk();

        $this->putJson('/admin/role-permissions/users', [
            'user_id' => $employee->id,
            'allows' => ['campaigns' => ['view']],
            'denies' => [],
        ])->assertOk();

        $this->assertTrue(app(RbacService::class)->can($employee->fresh(), 'campaigns', 'view'));

        $this->postJson('/admin/role-permissions/users/'.$employee->id.'/reset')
            ->assertOk();

        app(RbacUserOverrideService::class)->forgetUserCache($employee->id);
        app(RbacMatrixService::class)->flushCache();

        $this->assertFalse(app(RbacService::class)->can($employee->fresh(), 'campaigns', 'view'));
    }

    public function test_invalid_permission_key_returns_useful_validation_error(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $this->actingAs($superAdmin);

        $this->putJson('/admin/role-permissions', [
            'role' => 'employee',
            'grants' => [
                'dashboard' => ['not_a_real_permission'],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['grants.dashboard.0']);
    }
}
