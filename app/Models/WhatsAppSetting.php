<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppSetting extends Model
{
    public const MODE_SIMULATION = 'simulation';

    public const MODE_LIVE = 'live';

    public const DEFAULT_PROVIDER = 'Meta WhatsApp Cloud API';

    public const DEFAULT_API_VERSION = 'v23.0';

    public const INTEGRATION_NOT_CONFIGURED = 'not_configured';

    public const INTEGRATION_CONNECTED = 'connected';

    public const INTEGRATION_INTEGRATED = 'integrated';

    public const INTEGRATION_FAILED = 'failed';

    public const INTEGRATION_DISABLED = 'disabled';

    protected $table = 'whatsapp_settings';

    protected $fillable = [
        'provider_name',
        'phone_number_id',
        'business_account_id',
        'access_token',
        'webhook_verify_token',
        'api_version',
        'mode',
        'is_active',
        'integration_status',
        'last_tested_at',
        'last_test_status',
        'last_test_response',
        'last_successful_send_at',
        'test_mobile_number',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'webhook_verify_token' => 'encrypted',
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_successful_send_at' => 'datetime',
        ];
    }

    public function isLiveMode(): bool
    {
        return $this->mode === self::MODE_LIVE;
    }

    public function hasAccessToken(): bool
    {
        return filled($this->access_token);
    }

    public function hasWebhookVerifyToken(): bool
    {
        return filled($this->webhook_verify_token);
    }

    public function isConfigured(): bool
    {
        return filled($this->phone_number_id)
            && filled($this->business_account_id)
            && $this->hasAccessToken();
    }
}
