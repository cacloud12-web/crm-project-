<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * @deprecated Tests bootstrap via Tests\Support\CrmTestAccounts (factories).
 * Kept so legacy `db:seed --class=CrmTestFixtureSeeder` calls remain harmless outside production.
 */
class CrmTestFixtureSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            if ($this->command) {
                $this->command->error('CrmTestFixtureSeeder is blocked in production.');
            }

            return;
        }

        if (class_exists(\Tests\Support\CrmTestAccounts::class)) {
            \Tests\Support\CrmTestAccounts::bootstrap();
        }
    }
}
