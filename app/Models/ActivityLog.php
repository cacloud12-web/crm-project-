<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $table = 'activity_logs';

    protected $fillable = [
        'performed_by',
        'module_name',
        'record_id',
        'action',
        'description',
        'before_value',
        'after_value',
        'ip_address',
        'created_at',
        'updated_at',
    ];
}
