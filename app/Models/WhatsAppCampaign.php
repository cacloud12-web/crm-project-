<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppCampaign extends Model
{
    protected $table = 'whatsapp_campaigns';

    protected $fillable = [
        'campaign_name',
        'campaign_type',
        'audience_mode',
        'audience_label',
        'audience_filters',
        'selected_ca_ids',
        'message_template',
        'scheduled_at',
        'status',
        'performed_by',
        'total_messages',
        'delivered_count',
        'failed_count',
        'queued_count',
        'skipped_count',
    ];

    protected function casts(): array
    {
        return [
            'audience_filters' => 'array',
            'selected_ca_ids' => 'array',
            'scheduled_at' => 'datetime',
            'total_messages' => 'integer',
            'delivered_count' => 'integer',
            'failed_count' => 'integer',
            'queued_count' => 'integer',
            'skipped_count' => 'integer',
        ];
    }

    public function messageLogs(): HasMany
    {
        return $this->hasMany(WaMessageLog::class, 'campaign_id');
    }
}
