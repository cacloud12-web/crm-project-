<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OcrParsedMember extends Model
{
    protected $fillable = [
        'ocr_parsed_firm_id',
        'sequence_no',
        'raw_ca_name',
        'ca_name',
        'normalized_ca_name',
        'membership_no',
        'mobile',
        'email',
        'pan_no',
        'role',
        'is_primary',
        'overall_confidence',
        'page_number',
        'matched_reference_member_id',
        'review_status',
        'source_data',
        'notes',
        'field_meta',
    ];

    protected function casts(): array
    {
        return [
            'field_meta' => 'array',
            'source_data' => 'array',
            'overall_confidence' => 'decimal:4',
            'sequence_no' => 'integer',
            'page_number' => 'integer',
            'is_primary' => 'boolean',
            'matched_reference_member_id' => 'integer',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(OcrParsedFirm::class, 'ocr_parsed_firm_id');
    }
}
