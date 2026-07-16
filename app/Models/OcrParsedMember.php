<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OcrParsedMember extends Model
{
    protected $fillable = [
        'ocr_parsed_firm_id',
        'sequence_no',
        'ca_name',
        'membership_no',
        'mobile',
        'email',
        'role',
        'overall_confidence',
        'field_meta',
    ];

    protected function casts(): array
    {
        return [
            'field_meta' => 'array',
            'overall_confidence' => 'decimal:4',
            'sequence_no' => 'integer',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(OcrParsedFirm::class, 'ocr_parsed_firm_id');
    }
}
