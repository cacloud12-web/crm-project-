<?php

namespace App\Services\Email;

use App\Mail\CrmHtmlMail;
use App\Models\EmailLog;
use App\Models\EmailSetting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class EmailSmtpDispatchService
{
    public function __construct(
        private readonly GoDaddyMailService $mailMappingService,
        private readonly EmailRecipientValidationService $recipientValidationService,
    ) {}

    /**
     * @return array{success: bool, status: string, provider_response: array<string, mixed>, error_message: ?string, smtp_error: ?string}
     */
    public function send(
        EmailSetting $settings,
        string $recipientEmail,
        string $subject,
        string $body,
        array $options = [],
    ): array {
        $errors = $this->mailMappingService->validateDispatchPrerequisites(
            $settings,
            $recipientEmail,
            $subject,
            $body,
        );

        if ($errors !== []) {
            return [
                'success' => false,
                'status' => EmailRecipientValidationService::STATUS_FAILED,
                'provider_response' => ['errors' => $errors],
                'error_message' => implode(' ', $errors),
                'smtp_error' => null,
            ];
        }

        $mailerName = 'crm_smtp_'.$settings->id;
        $this->configureMailer($settings, $mailerName);

        $transport = (string) config("mail.mailers.{$mailerName}.transport", '');
        if ($transport !== 'smtp') {
            return [
                'success' => false,
                'status' => EmailRecipientValidationService::STATUS_FAILED,
                'provider_response' => ['errors' => ['SMTP transport is not configured.']],
                'error_message' => 'SMTP is not configured for outbound email. Configure Email Configuration → SMTP and set mode to Live.',
                'smtp_error' => 'mailer_transport='.$transport,
            ];
        }

        $htmlBody = $this->mailMappingService->toHtmlBody($body);
        $cc = $options['cc'] ?? [];
        $bcc = $options['bcc'] ?? [];
        $messageId = Str::uuid().'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'caclouddesk.local');

        try {
            Mail::mailer($mailerName)
                ->to($recipientEmail)
                ->send(new CrmHtmlMail(
                    mailSubject: $subject,
                    htmlBody: $htmlBody,
                    fromEmail: $settings->from_email,
                    fromName: $settings->from_name,
                    replyToEmail: $settings->reply_to_email ?: $settings->from_email,
                    ccRecipients: $cc,
                    bccRecipients: $bcc,
                    messageId: $messageId,
                ));

            return [
                'success' => true,
                'status' => EmailRecipientValidationService::STATUS_SENT,
                'message_id' => $messageId,
                'provider_response' => [
                    'provider' => $settings->provider_name,
                    'mode' => $settings->mode,
                    'transport' => $this->mailMappingService->buildMailTransport($settings),
                    'to' => $recipientEmail,
                    'subject' => $subject,
                    'sent' => true,
                ],
                'error_message' => null,
                'smtp_error' => null,
            ];
        } catch (Throwable $exception) {
            $smtpError = $this->humanizeSmtpError($exception);

            return [
                'success' => false,
                'status' => EmailRecipientValidationService::STATUS_FAILED,
                'provider_response' => [
                    'provider' => $settings->provider_name,
                    'mode' => $settings->mode,
                    'error' => $exception->getMessage(),
                ],
                'error_message' => $smtpError,
                'smtp_error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array{success: bool, status: string, provider_response: array<string, mixed>, error_message: ?string, smtp_error?: ?string}  $result
     */
    public function applyDispatchResult(EmailLog $log, array $result): EmailLog
    {
        $log->update([
            'email_status' => $result['status'],
            'message_id' => $result['message_id'] ?? $log->message_id,
            'direction' => $log->direction ?? 'outbound',
            'provider_response' => json_encode($result['provider_response']),
            'error_message' => $result['error_message'],
            'smtp_error' => $result['smtp_error'] ?? null,
            'failed_reason' => $result['success'] ? null : $result['error_message'],
            'sent_at' => now(),
            'delivered_at' => $result['success'] ? now() : null,
        ]);

        return $log->fresh();
    }

    public function configureMailer(EmailSetting $settings, string $mailerName = 'crm_smtp'): void
    {
        $normalized = app(EmailSmtpConnectionService::class)->normalizeConfig([
            'smtp_host' => $settings->smtp_host,
            'smtp_port' => $settings->smtp_port,
            'smtp_username' => $settings->smtp_username,
            'smtp_password' => $settings->smtp_password,
            'smtp_encryption' => $settings->smtp_encryption,
            'from_email' => $settings->from_email,
        ]);

        Config::set("mail.mailers.{$mailerName}", [
            'transport' => 'smtp',
            'host' => $normalized['smtp_host'],
            'port' => (int) $normalized['smtp_port'],
            'encryption' => $this->resolveEncryption($settings, $normalized),
            'username' => $normalized['smtp_username'],
            'password' => $normalized['smtp_password'],
            'timeout' => 30,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
        ]);
    }

    private function resolveEncryption(EmailSetting $settings, ?array $normalized = null): ?string
    {
        $encryption = strtolower((string) (($normalized['smtp_encryption'] ?? null) ?: $settings->smtp_encryption ?: ''));

        if ($encryption === 'starttls') {
            return 'tls';
        }

        if ($encryption === 'tls' && (int) ($normalized['smtp_port'] ?? $settings->smtp_port) === 465) {
            return 'ssl';
        }

        return match ($encryption) {
            'ssl', 'tls' => $encryption,
            default => (int) ($normalized['smtp_port'] ?? $settings->smtp_port) === 465 ? 'ssl' : 'tls',
        };
    }

    private function humanizeSmtpError(Throwable $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains(strtolower($message), 'authentication')) {
            return 'SMTP authentication failed. Check username and password.';
        }

        if (str_contains(strtolower($message), 'connection could not be established')) {
            return 'Could not connect to SMTP server. Check host, port, and encryption.';
        }

        return $message;
    }
}
