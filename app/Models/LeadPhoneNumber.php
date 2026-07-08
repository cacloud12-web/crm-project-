<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadPhoneNumber extends Model
{
    protected $fillable = [
        'ca_id',
        'normalized_number',
        'phone_type',
    ];

    public function caMaster(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'ca_id', 'ca_id');
    }
}
