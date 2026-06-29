<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class City extends Model
{
    protected $primaryKey = 'city_id';

    protected $fillable = [
        'city_name',
        'state_id',
    ];

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class, 'state_id', 'state_id');
    }
}
