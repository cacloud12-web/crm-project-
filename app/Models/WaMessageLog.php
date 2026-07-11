<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaMessageLog extends Model
{
    protected $table = 'wa_message_logs';

    protected $fillable = [
        'campaign_id',
        'ca_id',
        'employee_id',
        'template_name',
        'language_code',
        'mobile_no',
        'meta_message_id',
        'message',
        'api_payload',
        'provider_response',
        'error_message',
        'message_status',
        'queued_at',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_reason',
    ];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'api_payload' => 'array',
            'provider_response' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WhatsAppCampaign::class, 'campaign_id');
    }

    public function caMaster(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'ca_id', 'ca_id');
    }
}
