<?php

namespace Tests\Feature;

use App\Services\Rbac\RbacMatrixService;
use App\Services\Rbac\RbacService;
use Database\Seeders\RbacPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\CreatesCrmUsers;
use Tests\TestCase;

class RbacPermissionSeederTest extends TestCase
{
    use CreatesCrmUsers;
    use DatabaseTransactions;

    public function test_admin_role_receives_wildcard_module_permissions(): void
    {
        $this->seed(RbacPermissionSeeder::class);
        app(RbacMatrixService::class)->flushCache();

        $admin = $this->createAdmin();
        $rbac = app(RbacService::class);

        $this->assertTrue($rbac->can($admin, 'dashboard', 'view'));
        $this->assertTrue($rbac->can($admin, 'ca_master', 'create'));
        $this->assertTrue($rbac->can($admin, 'ca_master', 'delete'), 'Admin must retain ca_master.delete from config wildcard');
        $this->assertTrue($rbac->can($admin, 'bulk', 'import'), 'Admin must retain bulk.import from config wildcard');
        $this->assertTrue($rbac->can($admin, 'reports', 'export'));
    }

    public function test_ensure_config_defaults_restores_missing_admin_delete(): void
    {
        $this->seed(RbacPermissionSeeder::class);
        app(RbacMatrixService::class)->flushCache();

        $adminRole = \App\Models\CrmRole::query()->where('key', 'admin')->firstOrFail();
        $deletePermission = \App\Models\CrmPermission::query()
            ->where('module', 'ca_master')
            ->where('action', 'delete')
            ->firstOrFail();

        \Illuminate\Support\Facades\DB::table('crm_role_permissions')
            ->where('crm_role_id', $adminRole->id)
            ->where('crm_permission_id', $deletePermission->id)
            ->delete();
        app(RbacMatrixService::class)->flushCache();

        $admin = $this->createAdmin();
        $rbac = app(RbacService::class);
        $this->assertFalse($rbac->can($admin, 'ca_master', 'delete'));

        $inserted = app(\App\Services\Rbac\RbacDatabaseService::class)->ensureConfigDefaultGrants();
        app(RbacMatrixService::class)->flushCache();

        $this->assertGreaterThanOrEqual(1, $inserted);
        $this->assertTrue($rbac->can($admin, 'ca_master', 'delete'));
    }
}
