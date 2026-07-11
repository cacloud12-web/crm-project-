<?php

namespace App\Models;

use App\Models\Concerns\HasMasterRecordLifecycle;
use Illuminate\Database\Eloquent\Model;

class TeamSizeMaster extends Model
{
    use HasMasterRecordLifecycle;

    protected $table = 'team_size_masters';

    protected $fillable = [
        'team_size_min',
        'team_size_max',
        'team_size_label',
        'is_active',
        'deactivated_at',
        'deactivated_by',
        'is_system',
    ];
}
