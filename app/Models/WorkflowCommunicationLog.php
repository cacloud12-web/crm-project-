<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowCommunicationLog extends Model
{
    protected $fillable = [
        'ca_id',
        'demo_schedule_id',
        'demo_reminder_id',
        'channel',
        'recipient',
        'template_key',
        'status',
        'message',
        'error_message',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }
}
