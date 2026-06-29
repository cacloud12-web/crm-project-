<?php

namespace App\Services\Email;

use App\Models\EmailLog;
use App\Services\Activity\ActivityLogService;
use App\Services\Concerns\SearchesListings;

class EmailLogService
{
    use SearchesListings;

    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly GoDaddyMailService $goDaddyMailService,
    ) {}

    public function search(array $params = []): array
    {
        return $this->searchListing(
            EmailLog::query()->with(['caMaster:ca_id,firm_name', 'campaign:id,campaign_name,subject', 'employee:employee_id,name']),
            $params,
            'email_logs',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function mapLogRecord(EmailLog $log): array
    {
        return [
            'campaign_id' => $log->campaign_id,
            'lead_id' => $log->ca_id,
            'employee_id' => $log->employee_id,
            'recipient_email' => $log->recipient_email,
            'subject' => $log->subject,
            'message' => $log->body,
            'status' => $log->email_status,
            'provider_response' => $log->provider_response,
            'error_message' => $log->error_message ?? $log->failed_reason,
            'sent_at' => $log->sent_at,
            'opened_at' => $log->opened_at,
            'clicked_at' => $log->clicked_at,
        ];
    }

    public function markQueued(EmailLog $log, string $performedBy = 'System'): EmailLog
    {
        $log = $this->goDaddyMailService->prepareQueuedDispatch($log);

        $this->activityLogService->log(
            'EMAIL_LOG',
            'Email Queued',
            (string) $log->id,
            $log->recipient_email.' · '.$log->subject,
            $performedBy,
        );

        return $log->fresh();
    }

    public function markSent(EmailLog $log, array $providerResponse = [], string $performedBy = 'System'): EmailLog
    {
        $attributes = $this->goDaddyMailService->mapProviderResponseToLogAttributes(
            array_merge(['success' => true], $providerResponse),
        );

        $log->update($attributes);

        $this->activityLogService->log(
            'EMAIL_LOG',
            'Email Sent',
            (string) $log->id,
            $log->recipient_email.' · mapped response stored',
            $performedBy,
        );

        return $log->fresh();
    }

    public function markFailed(EmailLog $log, string $errorMessage, string $performedBy = 'System'): EmailLog
    {
        $log->update([
            'email_status' => 'Failed',
            'error_message' => $errorMessage,
            'failed_reason' => $errorMessage,
        ]);

        $this->activityLogService->log(
            'EMAIL_LOG',
            'Email Failed',
            (string) $log->id,
            $errorMessage,
            $performedBy,
        );

        return $log->fresh();
    }
}
