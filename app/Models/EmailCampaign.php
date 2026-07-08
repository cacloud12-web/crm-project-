<?php

namespace App\Models;

use App\Models\Concerns\HasCampaignMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailCampaign extends Model
{
    use HasCampaignMetadata;

    protected $table = 'email_campaigns';

    protected $fillable = [
        'campaign_uuid',
        'campaign_name',
        'campaign_type',
        'audience_mode',
        'audience_label',
        'audience_filters',
        'selected_ca_ids',
        'subject',
        'body_template',
        'email_template_id',
        'scheduled_at',
        'status',
        'performed_by',
        'created_by_user_id',
        'sender_config_id',
        'sender_snapshot',
        'template_snapshot',
        'status_history',
        'total_emails',
        'valid_emails_count',
        'invalid_emails_count',
        'duplicate_emails_count',
        'invalid_domain_count',
        'delivered_count',
        'sent_count',
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
        'delivery_dispatch_token',
        'delivery_started_at',
        'delivery_completed_at',
    ];

    protected function casts(): array
    {
        return array_merge($this->metadataCasts(), [
            'audience_filters' => 'array',
            'selected_ca_ids' => 'array',
            'scheduled_at' => 'datetime',
            'total_emails' => 'integer',
            'valid_emails_count' => 'integer',
            'invalid_emails_count' => 'integer',
            'duplicate_emails_count' => 'integer',
            'invalid_domain_count' => 'integer',
            'delivered_count' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'queued_count' => 'integer',
            'skipped_count' => 'integer',
            'delivery_started_at' => 'datetime',
            'delivery_completed_at' => 'datetime',
        ]);
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class, 'campaign_id');
    }
}
