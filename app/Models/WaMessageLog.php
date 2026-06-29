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
        'mobile_no',
        'message',
        'message_status',
        'queued_at',
        'sent_at',
        'delivered_at',
        'failed_reason',
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
        return $this->belongsTo(WhatsAppCampaign::class, 'campaign_id');
    }

    public function caMaster(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'ca_id', 'ca_id');
    }
}
