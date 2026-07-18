<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TEST FIXTURES ONLY — generic demo provider tiers with is_demo = true.
 */
class DemoProviderTestFixtureSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        if (! Schema::hasTable('demo_providers') || DB::table('demo_providers')->exists()) {
            return;
        }

        $defaults = config('demo_providers', []);
        $workingDays = $defaults['default_working_days'] ?? [1, 2, 3, 4, 5, 6];
        $hasDemoFlag = Schema::hasColumn('demo_providers', 'is_demo');
        $tiers = [
            ['name' => 'Fixture Provider Small', 'min' => 1, 'max' => 1, 'link' => 'https://meet.example.com/fixture-small', 'sort' => 1],
            ['name' => 'Fixture Provider Mid', 'min' => 2, 'max' => 10, 'link' => 'https://meet.example.com/fixture-mid', 'sort' => 2],
            ['name' => 'Fixture Provider Large', 'min' => 11, 'max' => null, 'link' => 'https://meet.example.com/fixture-large', 'sort' => 3],
        ];

        foreach ($tiers as $tier) {
            $row = [
                'name' => $tier['name'],
                'default_meeting_link' => $tier['link'],
                'min_team_size' => $tier['min'],
                'max_team_size' => $tier['max'],
                'slot_duration_minutes' => (int) ($defaults['default_slot_duration_minutes'] ?? 60),
                'buffer_minutes' => (int) ($defaults['default_buffer_minutes'] ?? 15),
                'max_demos_per_day' => (int) ($defaults['default_max_demos_per_day'] ?? 6),
                'work_start_time' => $defaults['default_work_start'] ?? '10:00:00',
                'work_end_time' => $defaults['default_work_end'] ?? '19:00:00',
                'break_start_time' => $defaults['default_break_start'] ?? '13:00:00',
                'break_end_time' => $defaults['default_break_end'] ?? '14:00:00',
                'working_days' => json_encode($workingDays),
                'is_active' => true,
                'sort_order' => $tier['sort'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if ($hasDemoFlag) {
                $row['is_demo'] = true;
            }
            DB::table('demo_providers')->insert($row);
        }
    }
}
