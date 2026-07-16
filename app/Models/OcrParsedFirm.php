<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OcrParsedFirm extends Model
{
    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    protected $fillable = [
        'ocr_document_id',
        'sequence_no',
        'firm_name',
        'firm_type',
        'frn',
        'gst_no',
        'pan_no',
        'address',
        'city',
        'state',
        'pincode',
        'phone',
        'email',
        'website',
        'review_status',
        'overall_confidence',
        'page_number',
        'field_meta',
    ];

    protected function casts(): array
    {
        return [
            'field_meta' => 'array',
            'overall_confidence' => 'decimal:4',
            'sequence_no' => 'integer',
            'page_number' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(OcrDocument::class, 'ocr_document_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(OcrParsedMember::class, 'ocr_parsed_firm_id')
            ->orderBy('sequence_no');
    }
}
