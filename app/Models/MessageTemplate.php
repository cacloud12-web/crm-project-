<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MessageTemplate extends Model
{
    public const STATUS_APPROVED = 'approved';

    public const STATUS_PENDING = 'pending';

    public const STATUS_REJECTED = 'rejected';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    protected $fillable = [
        'channel',
        'template_name',
        'meta_api_name',
        'meta_template_id',
        'meta_status',
        'meta_rejection_reason',
        'meta_status_payload',
        'meta_submitted_at',
        'meta_status_updated_at',
        'display_name',
        'language_code',
        'body_template',
        'status',
        'category',
        'header',
        'variable_map',
        'meta_components',
        'footer',
        'is_active',
        'publish_status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'variable_map' => 'array',
            'meta_components' => 'array',
            'meta_status_payload' => 'array',
            'is_active' => 'boolean',
            'meta_submitted_at' => 'datetime',
            'meta_status_updated_at' => 'datetime',
        ];
    }

    public function whatsappCampaigns(): HasMany
    {
        return $this->hasMany(WhatsAppCampaign::class, 'message_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isApproved(): bool
    {
        if ($this->meta_status && ! in_array(strtoupper($this->meta_status), ['APPROVED', 'REINSTATED'], true)) {
            return false;
        }

        return $this->status === self::STATUS_APPROVED && $this->is_active;
    }

    public function isDispatchable(): bool
    {
        if (! filled($this->meta_api_name)) {
            return false;
        }

        if ($this->meta_status && ! in_array(strtoupper($this->meta_status), ['APPROVED', 'REINSTATED'], true)) {
            return false;
        }

        return $this->isApproved();
    }

    public function metaApiTemplateName(): string
    {
        return filled($this->meta_api_name)
            ? (string) $this->meta_api_name
            : (string) $this->template_name;
    }

    public function metaApiLanguageCode(): string
    {
        $code = trim((string) $this->language_code) ?: 'en';

        if (str_contains($code, '_')) {
            [$lang, $region] = explode('_', $code, 2);

            return strtolower($lang).'_'.strtoupper($region);
        }

        if (str_contains($code, '-')) {
            [$lang, $region] = explode('-', $code, 2);

            return strtolower($lang).'_'.strtoupper($region);
        }

        return strtolower($code);
    }
}
