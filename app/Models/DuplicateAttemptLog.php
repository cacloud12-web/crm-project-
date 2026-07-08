<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DuplicateAttemptLog extends Model
{
    protected $fillable = [
        'employee_id',
        'lead_id',
        'attempted_mobile',
        'attempted_email',
        'attempted_gst',
        'attempted_pan',
        'attempted_website',
        'attempted_place_id',
        'attempted_at',
        'reason',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'lead_id', 'ca_id');
    }
}
