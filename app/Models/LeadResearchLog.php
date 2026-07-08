<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadResearchLog extends Model
{
    protected $fillable = [
        'ca_id',
        'user_id',
        'query',
        'source',
        'place_id',
        'result_payload',
        'saved_fields',
        'action',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'result_payload' => 'array',
            'saved_fields' => 'array',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'ca_id', 'ca_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
