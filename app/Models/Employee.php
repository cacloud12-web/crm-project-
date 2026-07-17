<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'employee_id';

    protected $fillable = [
        'user_id',
        'name',
        'email_id',
        'mobile_no',
        'city_id',
        'role',
        'date_of_joining',
        'status',
        'work_type',
        'demo_meeting_link',
        'demo_min_team_size',
        'demo_max_team_size',
        'active_for_demo',
    ];

    protected function casts(): array
    {
        return [
            'date_of_joining' => 'date',
            'demo_min_team_size' => 'integer',
            'demo_max_team_size' => 'integer',
            'active_for_demo' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id', 'city_id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class, 'employee_id', 'employee_id');
    }

    public function leadAssignments(): HasMany
    {
        return $this->hasMany(LeadAssignmentEngine::class, 'employee_id', 'employee_id');
    }
}
