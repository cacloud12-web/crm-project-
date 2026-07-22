<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesImportRow extends Model
{
    protected $fillable = [
        'import_batch_id',
        'source_file_name',
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
    ];

    public function ca()
    {
        return $this->belongsTo(CaMaster::class, 'matched_ca_id', 'ca_id');
    }
}
