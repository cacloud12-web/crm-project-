<?php

namespace Tests\Concerns;

use App\Models\CaMaster;
use App\Models\User;
use App\Services\Cache\CrmCacheService;
use App\Services\Rbac\RbacMatrixService;
use App\Services\Rbac\RbacService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoProviderTestFixtureSeeder;
use Database\Seeders\ManagerDemoSeeder;
use Database\Seeders\RbacPermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CrmTestAccounts;

trait PreparesCrmDatabase
{
    protected static bool $crmDatabasePrepared = false;

    protected function prepareCrmDatabaseForTesting(): void
    {
        if (! static::$crmDatabasePrepared) {
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('migrate', [
                '--database' => 'ca_reference',
                '--path' => config('ca_reference.migrations_path', 'database/migrations/ca_reference'),
                '--force' => true,
            ]);
            $this->seed(DatabaseSeeder::class);
            CrmTestAccounts::bootstrap();
            $this->seed(DemoProviderTestFixtureSeeder::class);
            $this->ensureRbacPermissions();
            static::$crmDatabasePrepared = true;
        } elseif (! CrmTestAccounts::$admin || ! User::query()->whereKey(CrmTestAccounts::$admin->id)->exists()) {
            if (! Schema::hasTable('users')) {
                Artisan::call('migrate', ['--force' => true]);
            }
            $this->seed(DatabaseSeeder::class);
            CrmTestAccounts::reset();
            CrmTestAccounts::bootstrap();
            $this->seed(DemoProviderTestFixtureSeeder::class);
            $this->ensureRbacPermissions();
        }

        $this->ensureMinimalTestFixtures();
    }

    protected function ensureMinimalTestFixtures(): void
    {
        if (! CrmTestAccounts::$employee || ! User::query()->whereKey(CrmTestAccounts::$employee->id)->exists()) {
            CrmTestAccounts::reset();
            CrmTestAccounts::bootstrap();
        }

        if (Schema::hasTable('ca_masters') && CaMaster::query()->count() === 0) {
            $this->seed(ManagerDemoSeeder::class);
        }
    }

    protected function flushCrmCachesForTesting(): void
    {
        $cache = app(CrmCacheService::class);
        $cache->forgetDashboardMetrics();
        $cache->forgetMasterListings();
        $cache->forgetLeadSegmentCounts();
        $cache->forgetPipelineStageCounts();
        $cache->forgetEmployeeRankings();
        $cache->forgetActivityFeed();
        $cache->forgetAssignmentWidgets();
        $cache->bumpReportCacheVersion();
    }

    protected function ensureRbacPermissions(): void
    {
        app(RbacMatrixService::class)->flushCache();

        $admin = CrmTestAccounts::admin();
        $rbac = app(RbacService::class);
        if ($rbac->can($admin, 'dashboard', 'view') && $rbac->can($admin, 'ca_master', 'create')) {
            return;
        }

        $this->seed(RbacPermissionSeeder::class);
        app(RbacMatrixService::class)->flushCache();
    }
}
