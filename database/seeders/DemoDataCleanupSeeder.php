<?php

namespace Database\Seeders;

use App\Services\Demo\DemoDataCleanupService;
use Illuminate\Database\Seeder;

/**
 * Safe demo reset: removes QA/test transactional rows and reseeds Manager Demo data.
 * Run: php artisan db:seed --class=DemoDataCleanupSeeder --force
 */
class DemoDataCleanupSeeder extends Seeder
{
    public function run(): void
    {
        $counts = app(DemoDataCleanupService::class)->cleanup(true);

        $this->command?->info('Demo cleanup complete. Rows removed: '.array_sum($counts));
    }
}
