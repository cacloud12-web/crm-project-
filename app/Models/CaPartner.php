<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaPartner extends Model
{
    protected $connection = 'ca_reference';

    protected $table = 'ca_partners';

    protected $fillable = [
        'firm_id',
        'partner_name',
        'membership_number',
        'designation',
        'mobile',
        'email',
        'status',
    ];

    public function firm(): BelongsTo
    {
        return $this->belongsTo(CaFirm::class, 'firm_id');
    }
}
