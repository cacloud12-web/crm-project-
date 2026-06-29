<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsLog extends Model
{
    protected $table = 'sms_logs';

    protected $fillable = [
        'campaign_id',
        'ca_id',
        'employee_id',
        'mobile_no',
        'sender_id',
        'message',
        'sms_status',
        'queued_at',
        'sent_at',
        'delivered_at',
        'failed_reason',
        'provider_response',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SmsCampaign::class, 'campaign_id');
    }

    public function caMaster(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'ca_id', 'ca_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}
