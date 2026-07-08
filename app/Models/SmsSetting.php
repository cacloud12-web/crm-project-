<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsSetting extends Model
{
    public const MODE_SIMULATION = 'simulation';

    public const MODE_LIVE = 'live';

    public const DEFAULT_API_URL = 'https://www.smsalert.co.in/api/push.json';

    public const DEFAULT_PROVIDER = 'SMS Alert';

    public const INTEGRATION_NOT_CONFIGURED = 'not_configured';

    public const INTEGRATION_CONNECTED = 'connected';

    public const INTEGRATION_INTEGRATED = 'integrated';

    public const INTEGRATION_FAILED = 'failed';

    public const INTEGRATION_DISABLED = 'disabled';

    protected $fillable = [
        'provider_name',
        'api_url',
        'api_key',
        'sender_id',
        'dlt_template_id',
        'mode',
        'is_active',
        'integration_status',
        'last_tested_at',
        'last_test_status',
        'last_test_response',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
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
        $raw = $this->getRawOriginal('api_key');

        return filled($raw);
    }

    public function safeApiKey(): ?string
    {
        if (! $this->hasApiKey()) {
            return null;
        }

        try {
            return $this->api_key;
        } catch (\Illuminate\Contracts\Encryption\DecryptException $exception) {
            \Illuminate\Support\Facades\Log::warning('SMS API key could not be decrypted', [
                'sms_setting_id' => $this->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
