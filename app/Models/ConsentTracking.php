<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsentTracking extends Model
{
    protected $table = 'consent_trackings';

    protected $fillable = [
        'ca_id',
        'consent_type',
        'consent_status',
        'consent_date',
    ];

    protected function casts(): array
    {
        return [
            'consent_date' => 'datetime',
        ];
    }

    public function caMaster(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'ca_id', 'ca_id');
    }
}
