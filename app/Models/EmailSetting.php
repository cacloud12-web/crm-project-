<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailSetting extends Model
{
    public const MODE_SIMULATION = 'simulation';

    public const MODE_LIVE = 'live';

    public const DEFAULT_PROVIDER = 'GoDaddy SMTP';

    public const DEFAULT_SMTP_HOST = 'smtpout.secureserver.net';

    public const DEFAULT_SMTP_PORT = 465;

    public const DEFAULT_ENCRYPTION = 'ssl';

    protected $fillable = [
        'provider_name',
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_encryption',
        'from_email',
        'from_name',
        'mode',
    ];

    protected function casts(): array
    {
        return [
            'smtp_port' => 'integer',
            'smtp_password' => 'encrypted',
        ];
    }

    public function isLiveMode(): bool
    {
        return $this->mode === self::MODE_LIVE;
    }

    public function isConfigured(): bool
    {
        return filled($this->smtp_host)
            && filled($this->smtp_port)
            && filled($this->smtp_username)
            && filled($this->smtp_password)
            && filled($this->from_email);
    }

    public function hasPassword(): bool
    {
        return filled($this->smtp_password);
    }
}
