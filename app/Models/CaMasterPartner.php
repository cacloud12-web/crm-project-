<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaMasterPartner extends Model
{
    protected $table = 'ca_master_partners';

    protected $fillable = [
        'ca_id',
        'ca_name',
        'membership_no',
        'mobile',
        'alternate_mobile',
        'email',
        'team_size',
        'designation',
        'is_primary',
        'status',
        'sequence_no',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sequence_no' => 'integer',
            'team_size' => 'integer',
            'ca_id' => 'integer',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'ca_id', 'ca_id');
    }
}
