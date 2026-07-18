<?php

namespace Database\Factories;

use App\Models\DemoProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DemoProvider> */
class DemoProviderFactory extends Factory
{
    protected $model = DemoProvider::class;

    public function definition(): array
    {
        return [
            'name' => 'Fixture Provider '.fake()->unique()->numberBetween(1, 99999),
            'default_meeting_link' => 'https://meet.example.com/demo-'.fake()->unique()->slug(2),
            'min_team_size' => 1,
            'max_team_size' => 10,
            'slot_duration_minutes' => 60,
            'buffer_minutes' => 15,
            'max_demos_per_day' => 6,
            'work_start_time' => '10:00:00',
            'work_end_time' => '19:00:00',
            'break_start_time' => '13:00:00',
            'break_end_time' => '14:00:00',
            'working_days' => [1, 2, 3, 4, 5, 6],
            'is_active' => true,
            'is_demo' => true,
            'sort_order' => 0,
        ];
    }

    public function forTeamRange(int $min, ?int $max = null): static
    {
        return $this->state(fn () => [
            'min_team_size' => $min,
            'max_team_size' => $max,
        ]);
    }

    public function productionSafe(): static
    {
        return $this->state(fn () => ['is_demo' => false]);
    }
}
