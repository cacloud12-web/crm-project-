<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * @deprecated Use CrmBootstrapSeeder (production) or CrmTestFixtureSeeder (tests).
 */
class CrmUserSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CrmTestFixtureSeeder::class);
    }
}
