<?php

namespace App\Jobs\Email;

use App\Models\EmailLog;
use App\Services\Email\EmailRecipientValidationService;
use App\Services\Email\EmailSettingsService;
use App\Services\Email\EmailSmtpDispatchService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendGoDaddyEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $emailLogId,
    ) {}

    public function uniqueId(): string
    {
        return 'email-log-send-'.$this->emailLogId;
    }

    public function handle(
        EmailSettingsService $emailSettingsService,
        EmailSmtpDispatchService $smtpDispatchService,
        EmailRecipientValidationService $recipientValidationService,
    ): void {
        $log = EmailLog::query()->with('caMaster')->findOrFail($this->emailLogId);

        if (in_array($log->email_status, [
            EmailRecipientValidationService::STATUS_SENT,
            'Delivered',
            EmailRecipientValidationService::STATUS_FAILED,
            EmailRecipientValidationService::STATUS_SKIPPED,
            EmailRecipientValidationService::STATUS_INVALID_EMAIL,
            EmailRecipientValidationService::STATUS_INVALID_DOMAIN,
            EmailRecipientValidationService::STATUS_DUPLICATE,
        ], true)) {
            return;
        }

        $settings = $emailSettingsService->resolve($log->email_setting_id);

        if (! $settings->isLiveMode()) {
            return;
        }

        $validation = $recipientValidationService->validate((string) $log->recipient_email, checkMx: true);
        if (! $validation['valid']) {
            $log->update([
                'email_status' => $validation['status'],
                'failed_reason' => $validation['reason'],
                'error_message' => $validation['reason'],
            ]);

            return;
        }

        $log->update(['email_status' => EmailRecipientValidationService::STATUS_PROCESSING]);

        $result = $smtpDispatchService->send(
            $settings,
            (string) $log->recipient_email,
            (string) $log->subject,
            (string) $log->body,
        );

        $smtpDispatchService->applyDispatchResult($log, $result);
    }

    public function failed(Throwable $exception): void
    {
        $log = EmailLog::query()->find($this->emailLogId);

        if (! $log) {
            return;
        }

        $log->update([
            'email_status' => EmailRecipientValidationService::STATUS_FAILED,
            'failed_reason' => $exception->getMessage(),
            'error_message' => $exception->getMessage(),
            'smtp_error' => $exception->getMessage(),
        ]);
    }
}
