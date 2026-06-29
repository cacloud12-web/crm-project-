<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BulkAction extends Model
{
    protected $primaryKey = 'bulk_action_id';

    protected $fillable = [
        'action_type',
        'file_name',
        'export_format',
        'export_filters',
        'output_path',
        'total_records',
        'processed_records',
        'success_records',
        'duplicate_records',
        'skipped_records',
        'failed_records',
        'initiated_by',
        'imported_by',
        'status',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'export_filters' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(BulkActionLog::class, 'bulk_action_id', 'bulk_action_id');
    }

    public function importedLeads(): HasMany
    {
        return $this->hasMany(CaMaster::class, 'bulk_action_id', 'bulk_action_id');
    }
}
