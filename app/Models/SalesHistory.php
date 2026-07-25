<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only Sales History. One row per sales_import_row.
 * May link to Needs Verification masters without changing verification fields.
 */
class SalesHistory extends Model
{
    protected $fillable = [
        'ca_id',
        'import_batch_id',
        'sales_import_row_id',
        'employee_id',
        'employee_name',
        'remarks',
        'remarks_2',
        'employee_notes',
        'call_status',
        'follow_up',
        'software',
        'sales_source',
        'csv_filename',
        'csv_row_number',
        'call_date',
        'extra_columns',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'call_date' => 'date',
            'extra_columns' => 'array',
            'imported_at' => 'datetime',
            'csv_row_number' => 'integer',
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
