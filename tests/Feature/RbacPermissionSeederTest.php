<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Rbac\RbacMatrixService;
use App\Services\Rbac\RbacService;
use Database\Seeders\RbacPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class RbacPermissionSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_role_receives_wildcard_module_permissions(): void
    {
        $this->seed(RbacPermissionSeeder::class);
        app(RbacMatrixService::class)->flushCache();

        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $rbac = app(RbacService::class);

        $this->assertTrue($rbac->can($admin, 'dashboard', 'view'));
        $this->assertTrue($rbac->can($admin, 'ca_master', 'create'));
        $this->assertTrue($rbac->can($admin, 'reports', 'export'));
    }
}
