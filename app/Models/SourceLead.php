<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceLead extends Model
{
    protected $table = 'source_leads';

    protected $primaryKey = 'source_id';

    protected $fillable = [
        'source_name',
    ];

    public function caMasters(): HasMany
    {
        return $this->hasMany(CaMaster::class, 'source_id', 'source_id');
    }
}
