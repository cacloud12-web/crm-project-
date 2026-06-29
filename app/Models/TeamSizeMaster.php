<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamSizeMaster extends Model
{
    protected $table = 'team_size_masters';

    protected $fillable = [
        'team_size_min',
        'team_size_max',
        'team_size_label',
    ];
}
