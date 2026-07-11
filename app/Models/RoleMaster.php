<?php

namespace App\Models;

use App\Models\Concerns\HasMasterRecordLifecycle;
use Illuminate\Database\Eloquent\Model;

class RoleMaster extends Model
{
    use HasMasterRecordLifecycle;

    protected $table = 'role_masters';

    protected $fillable = [
        'role_name',
        'description',
        'is_active',
        'deactivated_at',
        'deactivated_by',
        'is_system',
    ];
}
