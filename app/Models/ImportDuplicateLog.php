<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportDuplicateLog extends Model
{
    protected $fillable = [
        'bulk_action_id',
        'uploaded_by',
        'file_name',
        'row_number',
        'duplicate_value',
        'duplicate_type',
        'matched_lead_id',
        'action_taken',
        'ca_name',
        'firm_name',
        'mobile_no',
        'email_id',
        'source',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function matchedLead(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'matched_lead_id', 'ca_id');
    }
}
