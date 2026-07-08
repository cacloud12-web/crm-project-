<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadView extends Model
{
    protected $primaryKey = 'lead_view_id';

    protected $fillable = [
        'ca_id',
        'user_id',
        'employee_id',
        'ip_address',
        'user_agent',
        'viewed_at',
    ];

    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
        ];
    }

    public function caMaster(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'ca_id', 'ca_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
