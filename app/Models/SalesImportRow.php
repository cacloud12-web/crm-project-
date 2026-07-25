<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesImportRow extends Model
{
    protected $fillable = [
        'import_batch_id',
        'source_file_name',
        'source_file_hash',
        'row_fingerprint',
        'source_sheet_name',
        'source_row_number',
        'employee_name',

        'call_date',

        'ca_name',
        'firm_name',

        'mobile_no',
        'alternate_mobile_no',

        'city_name',

        'remarks_1',
        'remarks_2',

        'normalized_ca_name',
        'normalized_firm_name',
        'normalized_city',

        'matched_ca_id',
        'matched_reference_firm_id',
        'mapping_status',
        'matched_on',
        'match_score',
        'review_reason',
        'match_candidates',
        'mapped_at',

        'raw_payload',
    ];

    protected $casts = [
        'call_date' => 'date',
        'mapped_at' => 'datetime',
        'raw_payload' => 'array',
        'match_candidates' => 'array',
        'extra_columns' => 'array',
    ];

    public function ca(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'matched_ca_id', 'ca_id');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(MasterImportBatch::class, 'import_batch_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function salesMasterLink(): HasOne
    {
        return $this->hasOne(SalesMasterLink::class, 'sales_import_row_id');
    }

    public function salesHistory(): HasOne
    {
        return $this->hasOne(SalesHistory::class, 'sales_import_row_id');
    }

    public function salesMappingReview(): HasOne
    {
        return $this->hasOne(SalesMappingReview::class, 'sales_import_row_id');
    }

    public function salesContact(): HasOne
    {
        return $this->hasOne(SalesContact::class, 'sales_import_row_id');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_row_id');
    }
}
