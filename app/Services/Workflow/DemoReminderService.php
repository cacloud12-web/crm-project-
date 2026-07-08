<?php

namespace App\Services\Workflow;

use App\Models\DemoReminder;
use App\Models\DemoSchedule;
use App\Models\WorkflowCommunicationLog;
use App\Services\Email\GoDaddyMailService;
use App\Services\Notifications\NotificationService;
use App\Services\Sms\SmsAlertMappingService;
use App\Services\Sms\SmsSettingsService;
use Illuminate\Support\Carbon;
use Throwable;

class DemoReminderService
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly SmsSettingsService $smsSettingsService,
        private readonly SmsAlertMappingService $smsAlertMappingService,
        private readonly GoDaddyMailService $mailService,
    ) {}

    public function scheduleForDemo(DemoSchedule $schedule): void
    {
        $demoAt = $schedule->demo_at instanceof Carbon
            ? $schedule->demo_at->copy()
            : Carbon::parse($schedule->demo_at);

        $payload = $this->buildPayload($schedule);

        DemoReminder::query()->firstOrCreate(
            [
                'demo_schedule_id' => $schedule->id,
                'reminder_type' => 'scheduled_immediate',
            ],
            [
                'channel' => 'multi',
                'remind_at' => now(),
                'status' => DemoReminder::STATUS_PENDING,
                'payload' => $payload,
            ],
        );

        $fifteen = $demoAt->copy()->subMinutes(15);
        if ($fifteen->isFuture()) {
            DemoReminder::query()->firstOrCreate(
                [
                    'demo_schedule_id' => $schedule->id,
                    'reminder_type' => '15_minutes_before',
                ],
                [
                    'channel' => 'multi',
                    'remind_at' => $fifteen,
                    'status' => DemoReminder::STATUS_PENDING,
                    'payload' => $payload,
                ],
            );
        }

        $demoDay = $demoAt->copy()->startOfDay()->setTime(9, 0);
        if ($demoDay->lessThanOrEqualTo(now())) {
            $demoDay = now()->addMinute();
        }
        if ($demoDay->lessThan($demoAt)) {
            DemoReminder::query()->firstOrCreate(
                [
                    'demo_schedule_id' => $schedule->id,
                    'reminder_type' => 'demo_day_link',
                ],
                [
                    'channel' => 'multi',
                    'remind_at' => $demoDay,
                    'status' => DemoReminder::STATUS_PENDING,
                    'payload' => $payload,
                ],
            );
        }
    }

    public function cancelPendingForDemo(int $demoScheduleId): void
    {
        DemoReminder::query()
            ->where('demo_schedule_id', $demoScheduleId)
            ->where('status', DemoReminder::STATUS_PENDING)
            ->update(['status' => DemoReminder::STATUS_CANCELLED]);
    }

    public function processDueReminders(): int
    {
        $sent = 0;

        DemoReminder::query()
            ->with(['demoSchedule.lead', 'demoSchedule.employee'])
            ->whereIn('status', [DemoReminder::STATUS_PENDING, DemoReminder::STATUS_FAILED])
            ->where('remind_at', '<=', now())
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('id')
            ->chunkById(50, function ($reminders) use (&$sent) {
                foreach ($reminders as $reminder) {
                    if ($this->dispatchReminder($reminder)) {
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    private function dispatchReminder(DemoReminder $reminder): bool
    {
        $schedule = $reminder->demoSchedule;
        if (! $schedule || $schedule->status !== DemoSchedule::STATUS_SCHEDULED) {
            $reminder->update(['status' => DemoReminder::STATUS_CANCELLED]);

            return false;
        }

        $message = $this->renderMessage($reminder->reminder_type, $schedule);
        $reminder->increment('attempts');

        try {
            $channels = $this->sendChannels($schedule, $message, $reminder);
            $anySent = collect($channels)->contains(fn ($status) => $status === 'sent' || $status === 'mapped');

            $reminder->update([
                'status' => $anySent ? DemoReminder::STATUS_SENT : DemoReminder::STATUS_FAILED,
                'sent_at' => $anySent ? now() : null,
                'last_error' => $anySent ? null : 'No configured channel accepted the reminder.',
                'payload' => array_merge($reminder->payload ?? [], ['channels' => $channels, 'message' => $message]),
            ]);

            $this->notifyEmployee($schedule, $reminder->reminder_type, $message);

            return $anySent;
        } catch (Throwable $e) {
            $reminder->update([
                'status' => DemoReminder::STATUS_FAILED,
                'last_error' => $e->getMessage(),
            ]);

            WorkflowCommunicationLog::query()->create([
                'ca_id' => $schedule->ca_id,
                'demo_schedule_id' => $schedule->id,
                'demo_reminder_id' => $reminder->id,
                'channel' => 'system',
                'status' => 'failed',
                'message' => $message ?? null,
                'error_message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return array<string, string>
     */
    private function sendChannels(DemoSchedule $schedule, string $message, DemoReminder $reminder): array
    {
        $lead = $schedule->lead;
        $results = [];

        if ($lead?->mobile_no) {
            $results['sms'] = $this->sendSms($schedule, $message, $reminder);
            $results['whatsapp'] = $this->sendWhatsApp($schedule, $message, $reminder);
        }

        if ($lead?->email_id) {
            $results['email'] = $this->sendEmail($schedule, $message, $reminder);
        }

        if ($results === []) {
            $results['notification'] = 'skipped';
        }

        return $results;
    }

    private function sendSms(DemoSchedule $schedule, string $message, DemoReminder $reminder): string
    {
        $lead = $schedule->lead;
        if (! $lead) {
            return 'skipped';
        }

        try {
            $settings = $this->smsSettingsService->current();
            $prepared = $this->smsAlertMappingService->prepareForLead($lead, $message, $settings);
            $status = ($prepared['valid'] ?? false) ? 'mapped' : 'failed';
            $error = ($prepared['valid'] ?? false) ? null : implode(' ', $prepared['errors'] ?? ['SMS not configured']);

            WorkflowCommunicationLog::query()->create([
                'ca_id' => $schedule->ca_id,
                'demo_schedule_id' => $schedule->id,
                'demo_reminder_id' => $reminder->id,
                'channel' => 'sms',
                'recipient' => $lead->mobile_no,
                'template_key' => $reminder->reminder_type,
                'status' => $status,
                'message' => $message,
                'error_message' => $error,
                'meta' => $prepared['provider_response'] ?? null,
            ]);

            return $status;
        } catch (Throwable $e) {
            WorkflowCommunicationLog::query()->create([
                'ca_id' => $schedule->ca_id,
                'demo_schedule_id' => $schedule->id,
                'demo_reminder_id' => $reminder->id,
                'channel' => 'sms',
                'recipient' => $lead->mobile_no,
                'template_key' => $reminder->reminder_type,
                'status' => 'failed',
                'message' => $message,
                'error_message' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    private function sendEmail(DemoSchedule $schedule, string $message, DemoReminder $reminder): string
    {
        $lead = $schedule->lead;
        if (! $lead?->email_id) {
            return 'skipped';
        }

        try {
            $subject = match ($reminder->reminder_type) {
                'scheduled_immediate' => 'Your demo has been scheduled',
                '15_minutes_before' => 'Reminder: demo starts in 15 minutes',
                'demo_day_link' => 'Training/demo link for today',
                default => 'Demo reminder',
            };

            $prepared = $this->mailService->prepareForLead($lead, $subject, $message);
            $status = ($prepared['valid'] ?? false) ? 'mapped' : 'failed';
            $error = ($prepared['valid'] ?? false) ? null : implode(' ', $prepared['errors'] ?? ['Email not configured']);

            WorkflowCommunicationLog::query()->create([
                'ca_id' => $schedule->ca_id,
                'demo_schedule_id' => $schedule->id,
                'demo_reminder_id' => $reminder->id,
                'channel' => 'email',
                'recipient' => $lead->email_id,
                'template_key' => $reminder->reminder_type,
                'status' => $status,
                'message' => $message,
                'error_message' => $error,
                'meta' => $prepared['provider_response'] ?? null,
            ]);

            return $status;
        } catch (Throwable $e) {
            WorkflowCommunicationLog::query()->create([
                'ca_id' => $schedule->ca_id,
                'demo_schedule_id' => $schedule->id,
                'demo_reminder_id' => $reminder->id,
                'channel' => 'email',
                'recipient' => $lead->email_id,
                'template_key' => $reminder->reminder_type,
                'status' => 'failed',
                'message' => $message,
                'error_message' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    private function sendWhatsApp(DemoSchedule $schedule, string $message, DemoReminder $reminder): string
    {
        $lead = $schedule->lead;
        if (! $lead?->mobile_no) {
            return 'skipped';
        }

        // Map-only log (same pattern as SMS/email). Live dispatch uses existing WhatsApp campaign stack.
        WorkflowCommunicationLog::query()->create([
            'ca_id' => $schedule->ca_id,
            'demo_schedule_id' => $schedule->id,
            'demo_reminder_id' => $reminder->id,
            'channel' => 'whatsapp',
            'recipient' => $lead->mobile_no,
            'template_key' => $reminder->reminder_type,
            'status' => 'mapped',
            'message' => $message,
            'meta' => ['dispatch' => 'mapped_not_sent'],
        ]);

        return 'mapped';
    }

    private function notifyEmployee(DemoSchedule $schedule, string $type, string $message): void
    {
        $title = match ($type) {
            'scheduled_immediate' => 'Demo scheduled reminder sent',
            '15_minutes_before' => 'Demo in 15 minutes',
            'demo_day_link' => 'Demo day training link',
            default => 'Demo reminder',
        };

        $userId = $this->notificationService->resolveUserIdByEmployeeEmail(
            $schedule->employee?->email_id,
        );

        if ($userId) {
            $this->notificationService->notifyUser($userId, 'demo_reminder', $title, $message, [
                'entity_type' => 'demo_schedule',
                'entity_id' => (string) $schedule->id,
                'dedup_key' => 'demo_reminder:'.$schedule->id.':'.$type,
            ]);
        } else {
            $this->notificationService->notifyManagement('demo_reminder', $title, $message, [
                'entity_type' => 'demo_schedule',
                'entity_id' => (string) $schedule->id,
                'dedup_key' => 'demo_reminder:'.$schedule->id.':'.$type,
            ]);
        }
    }

    private function renderMessage(string $type, DemoSchedule $schedule): string
    {
        $template = (string) config('lead_workflow.messages.'.$type, 'Demo reminder for {{customer_name}}');
        $demoAt = $schedule->demo_at instanceof Carbon
            ? $schedule->demo_at
            : Carbon::parse($schedule->demo_at);

        return strtr($template, [
            '{{customer_name}}' => $schedule->customer_name ?: ($schedule->lead?->ca_name ?? ''),
            '{{firm_name}}' => $schedule->firm_name ?: ($schedule->lead?->firm_name ?? ''),
            '{{demo_at}}' => $demoAt->format('d M Y, h:i A'),
            '{{employee_name}}' => $schedule->employee?->name ?? '',
            '{{meeting_link}}' => $schedule->meeting_link ?? '',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(DemoSchedule $schedule): array
    {
        return [
            'customer_name' => $schedule->customer_name,
            'firm_name' => $schedule->firm_name,
            'demo_at' => $schedule->demo_at?->toIso8601String(),
            'employee_name' => $schedule->employee?->name,
            'meeting_link' => $schedule->meeting_link,
        ];
    }
}
