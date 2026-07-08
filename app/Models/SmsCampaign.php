<?php

namespace App\Models;

use App\Models\Concerns\HasCampaignMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmsCampaign extends Model
{
    use HasCampaignMetadata;

    protected $table = 'sms_campaigns';

    protected $fillable = [
        'campaign_uuid',
        'campaign_name',
        'campaign_type',
        'audience_mode',
        'audience_label',
        'audience_filters',
        'selected_ca_ids',
        'sender_id',
        'sms_template_id',
        'message_template',
        'scheduled_at',
        'status',
        'performed_by',
        'created_by_user_id',
        'sender_config_id',
        'sender_snapshot',
        'template_snapshot',
        'status_history',
        'total_sms',
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
            'total_sms' => 'integer',
            'delivered_count' => 'integer',
            'failed_count' => 'integer',
            'queued_count' => 'integer',
            'skipped_count' => 'integer',
        ]);
    }

    public function smsLogs(): HasMany
    {
        return $this->hasMany(SmsLog::class, 'campaign_id');
    }

    public function smsTemplate(): BelongsTo
    {
        return $this->belongsTo(SmsTemplate::class, 'sms_template_id');
    }
}
