<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sales → Master enrichment link. Never writes Master identity fields.
 */
class SalesMasterLink extends Model
{
    protected $fillable = [
        'ca_id',
        'import_batch_id',
        'sales_import_row_id',
        'employee_id',
        'match_tier',
        'confidence',
        'sales_source',
        'csv_filename',
        'csv_row_number',
        'linked_at',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:4',
            'csv_row_number' => 'integer',
            'linked_at' => 'datetime',
        ];
    }

    public function caMaster(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'ca_id', 'ca_id');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(MasterImportBatch::class, 'import_batch_id');
    }

    public function salesImportRow(): BelongsTo
    {
        return $this->belongsTo(SalesImportRow::class, 'sales_import_row_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}
