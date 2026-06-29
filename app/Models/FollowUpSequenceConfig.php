<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FollowUpSequenceConfig extends Model
{
    protected $primaryKey = 'config_id';

    protected $table = 'follow_up_sequence_configs';

    protected $fillable = [
        'name',
        'is_active',
        'sequence_days',
        'trigger_outcomes',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sequence_days' => 'array',
            'trigger_outcomes' => 'array',
        ];
    }
}
