<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CaReferenceImportBatch extends Model
{
    protected $connection = 'ca_reference';

    protected $table = 'ca_reference_import_batches';

    protected $fillable = [
        'source_file', 'source_file_hash', 'status', 'dry_run', 'chunk_size',
        'source_rows', 'imported_firms', 'imported_partners', 'imported_cities',
        'duplicate_count', 'skipped_count', 'failed_count', 'reused_firms',
        'reconciliation', 'error_message', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'reconciliation' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(CaReferenceImportRow::class, 'batch_id');
    }
}
