<?php

namespace Tests\Concerns;

use App\Models\CaMaster;
use App\Models\Employee;
use App\Models\User;
use App\Services\Cache\CrmCacheService;
use App\Services\Rbac\RbacMatrixService;
use App\Services\Rbac\RbacService;
use Database\Seeders\CrmUserSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ManagerDemoSeeder;
use Database\Seeders\RbacPermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

trait PreparesCrmDatabase
{
    protected static bool $crmDatabasePrepared = false;

    protected function prepareCrmDatabaseForTesting(): void
    {
        if (! static::$crmDatabasePrepared) {
            if (! Schema::hasTable('migrations')) {
                Artisan::call('migrate', ['--force' => true]);
            } else {
                Artisan::call('migrate', ['--force' => true]);
            }
            $this->seed(DatabaseSeeder::class);
            $this->ensureRbacPermissions();
            static::$crmDatabasePrepared = true;
        } elseif (! User::query()->where('email', 'admin@ca.local')->exists()) {
            if (! Schema::hasTable('users')) {
                Artisan::call('migrate', ['--force' => true]);
            }
            $this->seed(DatabaseSeeder::class);
            $this->ensureRbacPermissions();
        }

        $this->ensureMinimalTestFixtures();
    }

    /**
     * Restore minimal leads/employees when production cleanup removed transactional rows.
     */
    protected function ensureMinimalTestFixtures(): void
    {
        $this->ensureTestUserEmployees();

        if (Schema::hasTable('ca_masters') && CaMaster::query()->count() === 0) {
            $this->seed(ManagerDemoSeeder::class);
        }
    }

    /**
     * Re-create seeded employee profiles when users exist but rows were removed
     * (e.g. after production transactional cleanup).
     */
    protected function ensureTestUserEmployees(): void
    {
        $needsEmployee = User::query()->where('email', 'employee@ca.local')->exists()
            && ! Employee::query()->where('email_id', 'employee@ca.local')->exists();

        if ($needsEmployee) {
            $this->seed(CrmUserSeeder::class);
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
