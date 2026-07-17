<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MasterMappingDecision extends Model
{
    public const DECISION_AUTO_UPDATE = 'auto_update';

    public const DECISION_AUTO_CREATE = 'auto_create';

    public const DECISION_NEEDS_REVIEW = 'needs_review';

    public const DECISION_CONFLICT = 'conflict';

    public const DECISION_REJECTED = 'rejected';

    public const DECISION_SKIPPED = 'skipped';

    protected $fillable = [
        'import_batch_id',
        'source_type',
        'source_ref',
        'staging_id',
        'decision',
        'matched_ca_id',
        'confidence',
        'matched_on',
        'candidates',
        'payload_snapshot',
        'old_values',
        'new_values',
        'actor_id',
        'remarks',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'candidates' => 'array',
            'payload_snapshot' => 'array',
            'old_values' => 'array',
            'new_values' => 'array',
            'confidence' => 'decimal:4',
            'matched_ca_id' => 'integer',
            'staging_id' => 'integer',
            'actor_id' => 'integer',
            'import_batch_id' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'matched_ca_id', 'ca_id');
    }
}
