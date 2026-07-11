<?php

namespace App\Models;

use App\Models\Concerns\HasMasterRecordLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceLead extends Model
{
    use HasMasterRecordLifecycle;

    protected $table = 'source_leads';

    protected $primaryKey = 'source_id';

    protected $fillable = [
        'source_name',
        'is_active',
        'deactivated_at',
        'deactivated_by',
        'is_system',
    ];

    public function caMasters(): HasMany
    {
        return $this->hasMany(CaMaster::class, 'source_id', 'source_id');
    }
}
