<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CaFirm extends Model
{
    protected $connection = 'ca_reference';

    protected $table = 'ca_firms';

    protected $fillable = [
        'firm_name',
        'normalized_firm_name',
        'frn',
        'firm_type',
        'partner_count',
        'address',
        'city',
        'state',
        'pin_code',
        'gst_number',
        'email',
        'phone',
        'website',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'partner_count' => 'integer',
        ];
    }

    public function partners(): HasMany
    {
        return $this->hasMany(CaPartner::class, 'firm_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CaAddress::class, 'firm_id');
    }

    public function mappingLogs(): HasMany
    {
        return $this->hasMany(MappingLog::class, 'firm_id');
    }
}
