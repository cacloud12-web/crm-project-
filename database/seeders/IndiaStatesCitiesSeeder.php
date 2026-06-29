<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\State;
use App\Services\Cache\CrmCacheService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds all 28 Indian states + 8 union territories and major cities.
 * Run: php artisan db:seed --class=IndiaStatesCitiesSeeder --force
 */
class IndiaStatesCitiesSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<string, list<string>> $dataset */
        $dataset = require database_path('data/india_states_cities.php');

        DB::transaction(function () use ($dataset) {
            foreach ($dataset as $stateName => $cities) {
                $state = State::query()->updateOrCreate(
                    ['state_name' => $stateName],
                    ['state_name' => $stateName],
                );

                foreach ($cities as $cityName) {
                    City::query()->updateOrCreate(
                        [
                            'state_id' => $state->state_id,
                            'city_name' => $cityName,
                        ],
                        [
                            'state_id' => $state->state_id,
                            'city_name' => $cityName,
                        ],
                    );
                }
            }
        });

        app(CrmCacheService::class)->forgetMasterListings();

        $stateCount = State::query()->count();
        $cityCount = City::query()->count();

        $this->command?->info("India master data ready: {$stateCount} states/UTs, {$cityCount} cities.");
    }
}
