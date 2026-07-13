<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Services\Rbac\RbacMatrixService;
use App\Services\Rbac\RbacService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RbacPermissionSeeder;
use Illuminate\Support\Facades\Artisan;

trait PreparesCrmDatabase
{
    protected static bool $crmDatabasePrepared = false;

    protected function prepareCrmDatabaseForTesting(): void
    {
        if (! static::$crmDatabasePrepared) {
            if (! \Illuminate\Support\Facades\Schema::hasTable('migrations')) {
                Artisan::call('migrate', ['--force' => true]);
            } else {
                Artisan::call('migrate', ['--force' => true]);
            }
            $this->seed(DatabaseSeeder::class);
            $this->ensureRbacPermissions();
            static::$crmDatabasePrepared = true;

            return;
        }

        if (! User::query()->where('email', 'admin@ca.local')->exists()) {
            if (! \Illuminate\Support\Facades\Schema::hasTable('users')) {
                Artisan::call('migrate', ['--force' => true]);
            }
            $this->seed(DatabaseSeeder::class);
            $this->ensureRbacPermissions();
        }
    }

    protected function ensureRbacPermissions(): void
    {
        app(RbacMatrixService::class)->flushCache();

        $admin = User::query()->where('email', 'admin@ca.local')->first();
        if (! $admin) {
            return;
        }

        $rbac = app(RbacService::class);
        if ($rbac->can($admin, 'dashboard', 'view') && $rbac->can($admin, 'ca_master', 'create')) {
            return;
        }

        $this->seed(RbacPermissionSeeder::class);
        app(RbacMatrixService::class)->flushCache();
    }
}
