<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MappingLog extends Model
{
    protected $connection = 'ca_reference';

    protected $table = 'mapping_logs';

    protected $fillable = [
        'firm_id',
        'crm_record_id',
        'mapping_type',
        'confidence_score',
        'status',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'crm_record_id' => 'integer',
            'confidence_score' => 'decimal:4',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(CaFirm::class, 'firm_id');
    }
}
