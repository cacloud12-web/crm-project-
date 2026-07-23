<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OcrStagingCorrectionAudit extends Model
{
    protected $table = 'ocr_staging_correction_audits';

    protected $fillable = [
        'ocr_parsed_firm_id',
        'ocr_document_id',
        'category',
        'raw_values',
        'old_parsed_values',
        'new_parsed_values',
        'old_review_status',
        'new_review_status',
        'old_match_status',
        'new_match_status',
        'correction_reason',
        'confidence',
        'actor_id',
        'dry_run',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'raw_values' => 'array',
            'old_parsed_values' => 'array',
            'new_parsed_values' => 'array',
            'meta' => 'array',
            'dry_run' => 'boolean',
            'confidence' => 'decimal:4',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(OcrParsedFirm::class, 'ocr_parsed_firm_id');
    }
}
