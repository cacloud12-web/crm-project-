<?php

namespace App\Models;

use App\Models\Concerns\HasMasterRecordLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class City extends Model
{
    use HasMasterRecordLifecycle;

    protected $primaryKey = 'city_id';

    protected $fillable = [
        'city_name',
        'state_id',
        'is_active',
        'deactivated_at',
        'deactivated_by',
        'is_system',
    ];

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class, 'state_id', 'state_id');
    }
}
