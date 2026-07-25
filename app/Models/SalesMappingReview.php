<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Manual Sales Mapping review. Default status = pending. Never auto-approved.
 */
class SalesMappingReview extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_IGNORED = 'ignored';

    protected $fillable = [
        'import_batch_id',
        'sales_import_row_id',
        'candidate_ca_ids',
        'confidence',
        'match_tier',
        'reason',
        'status',
        'approved_ca_id',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'candidate_ca_ids' => 'array',
            'confidence' => 'decimal:4',
            'reviewed_at' => 'datetime',
        ];
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(MasterImportBatch::class, 'import_batch_id');
    }

    public function salesImportRow(): BelongsTo
    {
        return $this->belongsTo(SalesImportRow::class, 'sales_import_row_id');
    }

    public function approvedCaMaster(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'approved_ca_id', 'ca_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
