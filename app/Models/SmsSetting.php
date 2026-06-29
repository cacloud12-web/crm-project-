<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsSetting extends Model
{
    public const MODE_SIMULATION = 'simulation';

    public const MODE_LIVE = 'live';

    public const DEFAULT_API_URL = 'https://www.smsalert.co.in/api/push.json';

    public const DEFAULT_PROVIDER = 'SMS Alert';

    protected $fillable = [
        'provider_name',
        'api_url',
        'api_key',
        'sender_id',
        'mode',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public function isLiveMode(): bool
    {
        return $this->mode === self::MODE_LIVE;
    }

    public function isConfigured(): bool
    {
        return $this->hasApiKey() && filled($this->sender_id) && filled($this->api_url);
    }

    public function hasApiKey(): bool
    {
        return filled($this->api_key);
    }
}
