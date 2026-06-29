<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailCampaign extends Model
{
    protected $table = 'email_campaigns';

    protected $fillable = [
        'campaign_name',
        'campaign_type',
        'audience_mode',
        'audience_label',
        'audience_filters',
        'selected_ca_ids',
        'subject',
        'body_template',
        'scheduled_at',
        'status',
        'performed_by',
        'total_emails',
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
            'total_emails' => 'integer',
            'delivered_count' => 'integer',
            'failed_count' => 'integer',
            'queued_count' => 'integer',
            'skipped_count' => 'integer',
        ];
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class, 'campaign_id');
    }
}
