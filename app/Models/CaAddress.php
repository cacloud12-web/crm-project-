<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaAddress extends Model
{
    protected $connection = 'ca_reference';

    protected $table = 'ca_addresses';

    protected $fillable = [
        'firm_id',
        'address_line_1',
        'address_line_2',
        'city',
        'normalized_city',
        'state',
        'pin_code',
        'country',
    ];

    public function firm(): BelongsTo
    {
        return $this->belongsTo(CaFirm::class, 'firm_id');
    }
}
