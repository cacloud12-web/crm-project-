<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MasterImportBatch extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $fillable = [
        'source_type',
        'source_ref',
        'file_name',
        'file_hash',
        'status',
        'total_records',
        'created_count',
        'updated_count',
        'duplicate_count',
        'review_count',
        'conflict_count',
        'failed_count',
        'progress_stage',
        'progress_pct',
        'created_ca_ids',
        'updated_snapshots',
        'actor_id',
        'rolled_back_at',
        'rolled_back_by',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'created_ca_ids' => 'array',
            'updated_snapshots' => 'array',
            'column_map' => 'array',
            'rolled_back_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'total_records' => 'integer',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'duplicate_count' => 'integer',
            'matched_count' => 'integer',
            'review_count' => 'integer',
            'rejected_count' => 'integer',
            'skipped_count' => 'integer',
            'unmatched_count' => 'integer',
            'conflict_count' => 'integer',
            'failed_count' => 'integer',
            'progress_pct' => 'integer',
            'processing_ms' => 'integer',
            'actor_id' => 'integer',
            'employee_id' => 'integer',
            'rolled_back_by' => 'integer',
        ];
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(MasterMappingDecision::class, 'import_batch_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function salesImportRows(): HasMany
    {
        return $this->hasMany(SalesImportRow::class, 'import_batch_id');
    }

    public function salesMasterLinks(): HasMany
    {
        return $this->hasMany(SalesMasterLink::class, 'import_batch_id');
    }

    public function salesHistories(): HasMany
    {
        return $this->hasMany(SalesHistory::class, 'import_batch_id');
    }

    public function salesMappingReviews(): HasMany
    {
        return $this->hasMany(SalesMappingReview::class, 'import_batch_id');
    }

    public function salesContacts(): HasMany
    {
        return $this->hasMany(SalesContact::class, 'import_batch_id');
    }

    public function isRollbackable(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && $this->rolled_back_at === null
            && (
                ! empty($this->created_ca_ids)
                || ! empty($this->updated_snapshots)
            );
    }
}
