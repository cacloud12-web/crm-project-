<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailSetting extends Model
{
    public const MODE_SIMULATION = 'simulation';

    public const MODE_LIVE = 'live';

    public const DEFAULT_PROVIDER = 'cloud desk';

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
        'imap_host',
        'imap_port',
        'imap_encryption',
        'imap_username',
        'imap_password',
        'imap_enabled',
        'from_email',
        'from_name',
        'display_name',
        'reply_to_email',
        'mode',
        'is_active',
        'is_default',
        'last_tested_at',
        'last_test_status',
        'last_test_response',
        'smtp_last_tested_at',
        'smtp_last_test_status',
        'smtp_last_test_response',
        'imap_last_tested_at',
        'imap_last_test_status',
        'imap_last_test_response',
        'last_imap_sync_at',
        'imap_sync_state',
    ];

    protected function casts(): array
    {
        return [
            'smtp_port' => 'integer',
            'imap_port' => 'integer',
            'smtp_password' => 'encrypted',
            'imap_password' => 'encrypted',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'imap_enabled' => 'boolean',
            'last_tested_at' => 'datetime',
            'smtp_last_tested_at' => 'datetime',
            'imap_last_tested_at' => 'datetime',
            'last_imap_sync_at' => 'datetime',
            'imap_sync_state' => 'array',
        ];
    }

    public function inboundMessages(): HasMany
    {
        return $this->hasMany(EmailInboundMessage::class);
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
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

    public function isImapConfigured(): bool
    {
        if (! $this->is_active || ! $this->isConfigured()) {
            return false;
        }

        $credentials = $this->resolveImapCredentials();

        if (! filled($credentials['imap_password'] ?? null)
            || ! filled($credentials['imap_host'] ?? null)
            || ! filled($credentials['imap_username'] ?? null)) {
            return false;
        }

        if ($this->imap_enabled) {
            return true;
        }

        return $this->canInferImapFromSmtp();
    }

    /**
     * Gmail (and Google Workspace) accounts can use the same app password for IMAP as SMTP.
     *
     * @return array<string, mixed>
     */
    public function resolveImapCredentials(): array
    {
        $fromEmail = strtolower(trim((string) $this->from_email));

        if ($this->imap_enabled || filled($this->imap_host) || filled($this->imap_password)) {
            return [
                'imap_host' => $this->imap_host,
                'imap_port' => $this->imap_port ?: 993,
                'imap_encryption' => $this->imap_encryption ?: 'ssl',
                'imap_username' => $this->imap_username ?: $this->smtp_username ?: $this->from_email,
                'imap_password' => $this->imap_password ?: $this->smtp_password,
                'from_email' => $fromEmail !== '' ? $fromEmail : $this->from_email,
            ];
        }

        if ($this->canInferImapFromSmtp()) {
            return [
                'imap_host' => 'imap.gmail.com',
                'imap_port' => 993,
                'imap_encryption' => 'ssl',
                'imap_username' => $this->smtp_username ?: $this->from_email,
                'imap_password' => $this->smtp_password,
                'from_email' => $fromEmail !== '' ? $fromEmail : $this->from_email,
            ];
        }

        return [];
    }

    public function canInferImapFromSmtp(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $email = strtolower((string) $this->from_email);
        $smtpHost = strtolower((string) $this->smtp_host);

        return str_ends_with($email, '@gmail.com')
            || str_contains($smtpHost, 'gmail.com');
    }

    public function hasPassword(): bool
    {
        return filled($this->smtp_password);
    }

    public function hasImapPassword(): bool
    {
        return filled($this->imap_password);
    }

    public function resolvedDisplayName(): string
    {
        return $this->display_name ?: $this->from_name ?: $this->from_email ?: 'Email Account';
    }
}
