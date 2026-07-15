<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Rbac\PermissionService;
use App\Services\Rbac\RbacMatrixService;
use App\Services\Rbac\RbacService;
use App\Services\Rbac\RbacUserOverrideService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
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
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.supports_user_overrides', true);
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

    public function test_legacy_campaigns_action_is_normalized_and_save_succeeds(): void
    {
        $superAdmin = User::query()->where('email', 'superadmin@ca.local')->firstOrFail();
        $this->actingAs($superAdmin);

        $response = $this->putJson('/admin/role-permissions', [
            'role' => 'employee',
            'grants' => [
                'dashboard' => ['view'],
                'leads' => ['view'],
                // Legacy ghost key that previously caused grants.campaigns.0 invalid
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
        $superAdmin = User::query()->where('email', 'superadmin@ca.local')->firstOrFail();
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
        $superAdmin = User::query()->where('email', 'superadmin@ca.local')->firstOrFail();
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();

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
        $superAdmin = User::query()->where('email', 'superadmin@ca.local')->firstOrFail();
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();

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

        $superAdmin = User::query()->where('email', 'superadmin@ca.local')->firstOrFail();
        $ankit = User::query()->create([
            'name' => 'Ankit Bhardwaj',
            'email' => 'ankit.rbac.test@ca.local',
            'password' => bcrypt('password'),
            'crm_role' => 'employee',
            'is_active' => true,
        ]);
        $soniya = User::query()->where('email', 'employee@ca.local')->firstOrFail();

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
            'user_id' => $ankit->id,
            'allows' => ['campaigns' => ['view']],
            'denies' => [],
        ])->assertOk()->assertJsonPath('success', true);

        app(RbacMatrixService::class)->flushCache();
        app(RbacUserOverrideService::class)->forgetUserCache($ankit->id);
        app(RbacUserOverrideService::class)->forgetUserCache($soniya->id);

        $rbac = app(RbacService::class);
        $this->assertTrue($rbac->can($ankit->fresh(), 'campaigns', 'view'));
        $this->assertFalse($rbac->can($soniya->fresh(), 'campaigns', 'view'));
        $this->assertTrue(app(PermissionService::class)->can($ankit->fresh(), 'communication.view'));

        $this->actingAs($ankit);
        $this->getJson('/campaigns')->assertOk();

        $this->actingAs($soniya);
        $this->getJson('/campaigns')->assertForbidden();
    }

    public function test_user_deny_override_blocks_communication_when_role_allows(): void
    {
        if (! Schema::hasTable('crm_user_permission_overrides')) {
            $this->markTestSkipped('crm_user_permission_overrides table missing');
        }

        $superAdmin = User::query()->where('email', 'superadmin@ca.local')->firstOrFail();
        $soniya = User::query()->create([
            'name' => 'Soniya',
            'email' => 'soniya.rbac.test@ca.local',
            'password' => bcrypt('password'),
            'crm_role' => 'employee',
            'is_active' => true,
        ]);
        $other = User::query()->where('email', 'employee@ca.local')->firstOrFail();

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
            'user_id' => $soniya->id,
            'allows' => [],
            'denies' => ['campaigns' => ['view']],
        ])->assertOk();

        app(RbacMatrixService::class)->flushCache();
        app(RbacUserOverrideService::class)->forgetUserCache($soniya->id);
        app(RbacUserOverrideService::class)->forgetUserCache($other->id);

        $rbac = app(RbacService::class);
        $this->assertFalse($rbac->can($soniya->fresh(), 'campaigns', 'view'));
        $this->assertTrue($rbac->can($other->fresh(), 'campaigns', 'view'));

        $this->actingAs($soniya);
        $this->getJson('/campaigns')->assertForbidden();
    }

    public function test_reset_user_overrides_returns_to_role_defaults(): void
    {
        if (! Schema::hasTable('crm_user_permission_overrides')) {
            $this->markTestSkipped('crm_user_permission_overrides table missing');
        }

        $superAdmin = User::query()->where('email', 'superadmin@ca.local')->firstOrFail();
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();

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
        $superAdmin = User::query()->where('email', 'superadmin@ca.local')->firstOrFail();
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
