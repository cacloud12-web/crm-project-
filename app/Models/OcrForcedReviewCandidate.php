<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OcrForcedReviewCandidate extends Model
{
    public const DISPOSITION_QUARANTINED = 'quarantined';

    public const DISPOSITION_ELIGIBLE = 'eligible_for_master';

    public const DISPOSITION_IMPORTED = 'imported';

    public const DISPOSITION_LINKED = 'linked_existing';

    public const DISPOSITION_SKIPPED = 'skipped';

    public const DISPOSITION_REJECTED = 'rejected';

    public const DISPOSITION_IGNORED = 'ignored';

    public const DISPOSITION_ROLLED_BACK = 'rolled_back';

    protected $table = 'ocr_forced_review_candidates';

    protected $fillable = [
        'batch_id',
        'ocr_parsed_firm_id',
        'ocr_document_id',
        'source_row_number',
        'firm_name',
        'ca_name',
        'city',
        'address',
        'membership_no',
        'frn',
        'partners',
        'original_ocr_payload',
        'validation_problems',
        'confidence_score',
        'category',
        'disposition',
        'block_reason',
        'crm_ca_id',
        'master_created',
        'master_overwritten',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'partners' => 'array',
            'original_ocr_payload' => 'array',
            'validation_problems' => 'array',
            'meta' => 'array',
            'confidence_score' => 'decimal:4',
            'master_created' => 'boolean',
            'master_overwritten' => 'boolean',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(OcrParsedFirm::class, 'ocr_parsed_firm_id');
    }
}
