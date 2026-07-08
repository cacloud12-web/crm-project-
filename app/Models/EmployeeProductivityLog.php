<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeProductivityLog extends Model
{
    protected $fillable = [
        'employee_id',
        'log_date',
        'leads_assigned',
        'unique_leads_added',
        'duplicate_attempts',
        'wrong_numbers',
        'verified_leads',
        'followups_completed',
        'sms_failed',
        'whatsapp_failed',
        'email_failed',
        'invalid_leads',
        'quality_score',
        'rank',
    ];

    protected function casts(): array
    {
        return [
            'log_date' => 'date',
            'leads_assigned' => 'integer',
            'unique_leads_added' => 'integer',
            'duplicate_attempts' => 'integer',
            'wrong_numbers' => 'integer',
            'verified_leads' => 'integer',
            'followups_completed' => 'integer',
            'sms_failed' => 'integer',
            'whatsapp_failed' => 'integer',
            'email_failed' => 'integer',
            'invalid_leads' => 'integer',
            'quality_score' => 'integer',
            'rank' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}
