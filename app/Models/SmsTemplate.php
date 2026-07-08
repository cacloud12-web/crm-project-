<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmsTemplate extends Model
{
    public const STATUS_APPROVED = 'approved';

    public const STATUS_PENDING = 'pending';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'template_name',
        'sender_id',
        'dlt_template_id',
        'body_template',
        'variable_map',
        'status',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'variable_map' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(SmsCampaign::class, 'sms_template_id');
    }

    public function smsLogs(): HasMany
    {
        return $this->hasMany(SmsLog::class, 'sms_template_id');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED && $this->is_active;
    }

    public function placeholderCount(): int
    {
        return preg_match_all('/\{#var#\}/', (string) $this->body_template) ?: 0;
    }
}
