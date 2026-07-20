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
        'parse_run_id',
        'source_fingerprint',
        'business_fingerprint',
        'sequence_no',
        'raw_firm_name',
        'firm_name',
        'normalized_firm_name',
        'firm_type',
        'frn',
        'gst_no',
        'pan_no',
        'address',
        'city',
        'district',
        'state',
        'pincode',
        'phone',
        'email',
        'website',
        'partner_count',
        'review_status',
        'overall_confidence',
        'page_number',
        'column_number',
        'matched_reference_firm_id',
        'crm_ca_id',
        'matched_ca_id',
        'match_status',
        'match_confidence',
        'match_reason',
        'match_candidates',
        'mapped_at',
        'source_data',
        'notes',
        'field_meta',
        'row_number',
        'bounding_box',
        'validation_errors',
        'is_noise',
    ];

    protected function casts(): array
    {
        return [
            'field_meta' => 'array',
            'source_data' => 'array',
            'match_candidates' => 'array',
            'bounding_box' => 'array',
            'validation_errors' => 'array',
            'overall_confidence' => 'decimal:4',
            'match_confidence' => 'decimal:4',
            'sequence_no' => 'integer',
            'page_number' => 'integer',
            'partner_count' => 'integer',
            'matched_reference_firm_id' => 'integer',
            'crm_ca_id' => 'integer',
            'matched_ca_id' => 'integer',
            'mapped_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(OcrDocument::class, 'ocr_document_id');
    }

    public function crmMaster(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'crm_ca_id', 'ca_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(OcrParsedMember::class, 'ocr_parsed_firm_id')
            ->orderBy('sequence_no');
    }
}
