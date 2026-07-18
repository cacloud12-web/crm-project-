<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaReferenceImportRow extends Model
{
    protected $connection = 'ca_reference';

    protected $table = 'ca_reference_import_rows';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'batch_id', 'row_number', 'source_file',
        'raw_firm_name', 'raw_ca_name', 'raw_city',
        'normalized_firm_name', 'normalized_ca_name', 'normalized_city',
        'firm_id', 'partner_id', 'address_id',
        'status', 'is_duplicate', 'failure_reason', 'details',
    ];

    protected function casts(): array
    {
        return [
            'is_duplicate' => 'boolean',
            'details' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CaReferenceImportBatch::class, 'batch_id');
    }
}
