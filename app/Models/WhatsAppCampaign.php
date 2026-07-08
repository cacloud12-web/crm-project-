<?php

namespace App\Models;

use App\Models\Concerns\HasCampaignMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppCampaign extends Model
{
    use HasCampaignMetadata;

    protected $table = 'whatsapp_campaigns';

    protected $fillable = [
        'campaign_uuid',
        'campaign_name',
        'campaign_type',
        'audience_mode',
        'audience_label',
        'audience_filters',
        'selected_ca_ids',
        'message_template',
        'message_template_id',
        'template_name',
        'language_code',
        'api_version',
        'payload_generated_at',
        'scheduled_at',
        'status',
        'performed_by',
        'created_by_user_id',
        'sender_config_id',
        'sender_snapshot',
        'template_snapshot',
        'status_history',
        'total_messages',
        'delivered_count',
        'failed_count',
        'queued_count',
        'skipped_count',
        'pending_count',
        'invalid_count',
        'duplicate_count',
        'bounce_count',
        'retry_count',
        'paused_at',
        'cancelled_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return array_merge($this->metadataCasts(), [
            'audience_filters' => 'array',
            'selected_ca_ids' => 'array',
            'scheduled_at' => 'datetime',
            'payload_generated_at' => 'datetime',
            'total_messages' => 'integer',
            'delivered_count' => 'integer',
            'failed_count' => 'integer',
            'queued_count' => 'integer',
            'skipped_count' => 'integer',
        ]);
    }

    public function messageTemplate(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }

    public function messageLogs(): HasMany
    {
        return $this->hasMany(WaMessageLog::class, 'campaign_id');
    }
}
