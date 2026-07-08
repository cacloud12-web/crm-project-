<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DuplicateAttempt extends Model
{
    public const TYPE_DUPLICATE = 'duplicate';

    public const TYPE_POTENTIAL_DUPLICATE = 'potential_duplicate';

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CHANGED = 'changed_number';

    protected $fillable = [
        'employee_id',
        'lead_id',
        'duplicate_number',
        'saved_number',
        'matched_lead_id',
        'attempt_type',
        'status',
        'field_name',
        'browser',
        'ip',
        'number_changed',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'number_changed' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'lead_id', 'ca_id');
    }

    public function matchedLead(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'matched_lead_id', 'ca_id');
    }
}
