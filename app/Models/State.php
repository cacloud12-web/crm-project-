<?php

namespace App\Models;

use App\Models\Concerns\HasMasterRecordLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class State extends Model
{
    use HasMasterRecordLifecycle;

    protected $primaryKey = 'state_id';

    protected $fillable = [
        'state_name',
        'is_active',
        'deactivated_at',
        'deactivated_by',
        'is_system',
    ];

    public function cities(): HasMany
    {
        return $this->hasMany(City::class, 'state_id', 'state_id');
    }
}
