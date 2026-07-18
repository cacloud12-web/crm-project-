<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemoProvider extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'default_meeting_link',
        'min_team_size',
        'max_team_size',
        'slot_duration_minutes',
        'buffer_minutes',
        'max_demos_per_day',
        'work_start_time',
        'work_end_time',
        'break_start_time',
        'break_end_time',
        'working_days',
        'is_active',
        'is_demo',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'working_days' => 'array',
            'is_active' => 'boolean',
            'is_demo' => 'boolean',
        ];
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(DemoProviderLeave::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(DemoSchedule::class);
    }
}
