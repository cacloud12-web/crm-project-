<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class State extends Model
{
    protected $primaryKey = 'state_id';

    protected $fillable = [
        'state_name',
    ];

    public function cities(): HasMany
    {
        return $this->hasMany(City::class, 'state_id', 'state_id');
    }
}
