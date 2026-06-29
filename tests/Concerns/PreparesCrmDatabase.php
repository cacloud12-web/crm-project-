<?php

namespace Tests\Concerns;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;

trait PreparesCrmDatabase
{
    protected static bool $crmDatabasePrepared = false;

    protected function prepareCrmDatabaseForTesting(): void
    {
        if (! static::$crmDatabasePrepared) {
            Artisan::call('migrate', ['--force' => true]);
            $this->seed(DatabaseSeeder::class);
            static::$crmDatabasePrepared = true;

            return;
        }

        if (! User::query()->where('email', 'admin@ca.local')->exists()) {
            $this->seed(DatabaseSeeder::class);
        }
    }
}
