<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sales-only contact channel. Does not overwrite ca_masters mobiles/emails.
 */
class SalesContact extends Model
{
    protected $fillable = [
        'ca_id',
        'sales_master_link_id',
        'import_batch_id',
        'sales_import_row_id',
        'employee_id',
        'sales_mobile',
        'normalized_sales_mobile',
        'sales_alternate_mobile',
        'sales_email',
        'normalized_sales_email',
        'sales_website',
        'is_primary_sales',
    ];

    protected function casts(): array
    {
        return [
            'is_primary_sales' => 'boolean',
        ];
    }

    public function caMaster(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'ca_id', 'ca_id');
    }

    public function salesMasterLink(): BelongsTo
    {
        return $this->belongsTo(SalesMasterLink::class, 'sales_master_link_id');
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
