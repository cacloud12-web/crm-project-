<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OcrQuarantineImportBatch extends Model
{
    protected $table = 'ocr_quarantine_import_batches';

    protected $fillable = [
        'batch_id',
        'status',
        'actor_id',
        'dry_run',
        'chunk_size',
        'last_ocr_parsed_firm_id',
        'summary',
        'backup_paths',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'backup_paths' => 'array',
            'dry_run' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(OcrForcedReviewCandidate::class, 'batch_id', 'batch_id');
    }
}
