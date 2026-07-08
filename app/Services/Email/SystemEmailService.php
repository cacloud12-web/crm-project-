<?php

namespace App\Services\Email;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SystemEmailService
{
    public function __construct(
        private readonly EmailSettingsService $emailSettingsService,
        private readonly EmailSmtpDispatchService $smtpDispatchService,
    ) {}

    /**
     * Send a transactional HTML email using the default CRM SMTP account.
     *
     * @throws ValidationException
     */
    public function sendHtml(string $recipientEmail, string $subject, string $htmlBody): void
    {
        $settings = $this->emailSettingsService->resolveForSystemMail();

        if (! $settings->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Default email account is inactive. Activate SMTP in Settings → Email Configuration.'],
            ]);
        }

        if (! $settings->isConfigured()) {
            throw ValidationException::withMessages([
                'email' => ['Default SMTP is not configured. Open Email Configuration, save your SMTP account, run Test SMTP, and set mode to Live.'],
            ]);
        }

        if (! $settings->isLiveMode()) {
            throw ValidationException::withMessages([
                'email' => ['Default email account is in Simulation mode. Switch to Live mode in Email Configuration before sending verification emails.'],
            ]);
        }

        $result = $this->smtpDispatchService->send(
            $settings,
            $recipientEmail,
            $subject,
            $htmlBody,
        );

        if (! $result['success']) {
            throw ValidationException::withMessages([
                'email' => [$result['error_message'] ?? 'Unable to send email. Check SMTP configuration.'],
            ]);
        }

        Log::info('System email sent via SMTP', [
            'recipient' => $recipientEmail,
            'subject' => $subject,
            'from_email' => $settings->from_email,
            'email_setting_id' => $settings->id,
        ]);
    }
}
