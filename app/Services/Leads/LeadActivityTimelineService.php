<?php

namespace App\Services\Leads;

use App\Models\AssignmentHistory;
use App\Models\CaMaster;
use App\Models\CallLog;
use App\Models\EmailInboundMessage;
use App\Models\EmailLog;
use App\Models\FollowUp;
use App\Models\FollowUpHistory;
use App\Models\LeadAction;
use App\Models\LeadQualityHistory;
use App\Models\SmsLog;
use App\Models\WaMessageLog;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class LeadActivityTimelineService
{
    /**
     * @param  list<int>  $caIds
     * @return array<int, array<string, mixed>>
     */
    public function summariesForCaIds(array $caIds): array
    {
        $caIds = array_values(array_unique(array_filter(array_map('intval', $caIds))));
        if ($caIds === []) {
            return [];
        }

        $events = $this->collectEventsForCaIds($caIds);
        $summaries = [];

        foreach ($caIds as $caId) {
            $latest = collect($events[$caId] ?? [])
                ->sortByDesc(fn (array $event) => $event['occurred_at']->timestamp)
                ->first();

            if ($latest !== null) {
                $summaries[$caId] = $this->formatSummary($latest);
            }
        }

        return $summaries;
    }

    /**
     * @return array{
     *     ca_id: int,
     *     firm_name: string|null,
     *     items: list<array<string, mixed>>
     * }
     */
    public function timelineForLead(CaMaster $lead, int $limit = 10): array
    {
        $events = $this->collectEventsForCaIds([(int) $lead->ca_id])[(int) $lead->ca_id] ?? [];

        $items = collect($events)
            ->sortByDesc(fn (array $event) => $event['occurred_at']->timestamp)
            ->take($limit)
            ->values()
            ->map(fn (array $event) => $this->formatTimelineItem($event))
            ->all();

        return [
            'ca_id' => (int) $lead->ca_id,
            'firm_name' => $lead->firm_name,
            'items' => $items,
        ];
    }

    /**
     * @param  list<int>  $caIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function collectEventsForCaIds(array $caIds): array
    {
        /** @var array<int, list<array<string, mixed>>> $grouped */
        $grouped = [];

        $append = function (array $event) use (&$grouped): void {
            $caId = (int) ($event['ca_id'] ?? 0);
            if ($caId <= 0 || ! ($event['occurred_at'] instanceof CarbonInterface)) {
                return;
            }
            $grouped[$caId][] = $event;
        };

        CallLog::query()
            ->with('employee:employee_id,name')
            ->whereIn('ca_id', $caIds)
            ->get()
            ->each(function (CallLog $log) use ($append): void {
                $append([
                    'ca_id' => (int) $log->ca_id,
                    'occurred_at' => $log->called_at ?? $log->created_at,
                    'type' => 'call',
                    'label' => 'Call',
                    'icon' => 'phone',
                    'employee_name' => $log->employee?->name ?? 'System',
                    'note' => trim((string) ($log->call_note ?: $log->call_status ?: '')),
                ]);
            });

        FollowUpHistory::query()
            ->with('employee:employee_id,name')
            ->whereIn('ca_id', $caIds)
            ->get()
            ->each(function (FollowUpHistory $history) use ($append): void {
                $append([
                    'ca_id' => (int) $history->ca_id,
                    'occurred_at' => $history->created_at,
                    'type' => 'follow_up',
                    'label' => $this->followUpHistoryLabel($history->event_type),
                    'icon' => 'calendar-clock',
                    'employee_name' => $history->employee?->name ?? ($history->performed_by ?: 'System'),
                    'note' => trim((string) ($history->remarks ?: $history->outcome ?: '')),
                ]);
            });

        FollowUp::query()
            ->with('employee:employee_id,name')
            ->whereIn('ca_id', $caIds)
            ->get()
            ->each(function (FollowUp $followUp) use ($append): void {
                $occurredAt = $followUp->updated_at ?? $followUp->created_at;
                $append([
                    'ca_id' => (int) $followUp->ca_id,
                    'occurred_at' => $occurredAt,
                    'type' => 'follow_up',
                    'label' => 'Follow-up',
                    'icon' => 'calendar-clock',
                    'employee_name' => $followUp->employee?->name ?? 'System',
                    'note' => trim((string) ($followUp->remarks ?: $followUp->outcome ?: $followUp->followup_type ?: '')),
                ]);
            });

        LeadAction::query()
            ->with('employee:employee_id,name')
            ->whereIn('ca_id', $caIds)
            ->get()
            ->each(function (LeadAction $action) use ($append): void {
                $append([
                    'ca_id' => (int) $action->ca_id,
                    'occurred_at' => $action->action_at ?? $action->created_at,
                    'type' => 'lead_action',
                    'label' => $action->action_type ?: 'Lead Action',
                    'icon' => 'git-branch',
                    'employee_name' => $action->employee?->name ?? 'System',
                    'note' => trim((string) ($action->remarks ?? '')),
                ]);
            });

        AssignmentHistory::query()
            ->with(['newEmployee:employee_id,name', 'assignedByEmployee:employee_id,name'])
            ->whereIn('ca_id', $caIds)
            ->get()
            ->each(function (AssignmentHistory $history) use ($append): void {
                $append([
                    'ca_id' => (int) $history->ca_id,
                    'occurred_at' => $history->assigned_at ?? $history->created_at,
                    'type' => 'assignment',
                    'label' => 'Assignment Changed',
                    'icon' => 'user-check',
                    'employee_name' => $history->assignedByEmployee?->name
                        ?? $history->newEmployee?->name
                        ?? 'System',
                    'note' => trim((string) ($history->reason ?: $history->assignment_type ?: '')),
                ]);
            });

        EmailLog::query()
            ->with('employee:employee_id,name')
            ->whereIn('ca_id', $caIds)
            ->get()
            ->each(function (EmailLog $log) use ($append): void {
                $append([
                    'ca_id' => (int) $log->ca_id,
                    'occurred_at' => $log->reply_received_at ?? $log->sent_at ?? $log->created_at,
                    'type' => 'email',
                    'label' => $log->reply_received_at ? 'Email Reply' : 'Email',
                    'icon' => 'mail',
                    'employee_name' => $log->employee?->name ?? 'System',
                    'note' => trim((string) ($log->subject ?: Str::limit(strip_tags((string) $log->body), 120, '…'))),
                ]);
            });

        EmailInboundMessage::query()
            ->whereIn('ca_id', $caIds)
            ->get()
            ->each(function (EmailInboundMessage $message) use ($append): void {
                $append([
                    'ca_id' => (int) $message->ca_id,
                    'occurred_at' => $message->received_at ?? $message->created_at,
                    'type' => 'email',
                    'label' => 'Email',
                    'icon' => 'mail',
                    'employee_name' => $message->from_email ?: 'Customer',
                    'note' => trim((string) ($message->subject ?: Str::limit(strip_tags((string) ($message->body_text ?: $message->body_html)), 120, '…'))),
                ]);
            });

        WaMessageLog::query()
            ->with('employee:employee_id,name')
            ->whereIn('ca_id', $caIds)
            ->get()
            ->each(function (WaMessageLog $log) use ($append): void {
                $append([
                    'ca_id' => (int) $log->ca_id,
                    'occurred_at' => $log->sent_at ?? $log->delivered_at ?? $log->created_at,
                    'type' => 'whatsapp',
                    'label' => 'WhatsApp',
                    'icon' => 'message-circle',
                    'employee_name' => $log->employee?->name ?? 'System',
                    'note' => trim((string) ($log->message ?: $log->template_name ?: '')),
                ]);
            });

        SmsLog::query()
            ->with('employee:employee_id,name')
            ->whereIn('ca_id', $caIds)
            ->get()
            ->each(function (SmsLog $log) use ($append): void {
                $append([
                    'ca_id' => (int) $log->ca_id,
                    'occurred_at' => $log->sent_at ?? $log->delivered_at ?? $log->created_at,
                    'type' => 'sms',
                    'label' => 'SMS',
                    'icon' => 'message-square',
                    'employee_name' => $log->employee?->name ?? 'System',
                    'note' => trim((string) ($log->message ?: '')),
                ]);
            });

        LeadQualityHistory::query()
            ->with('employee:employee_id,name')
            ->whereIn('ca_id', $caIds)
            ->get()
            ->each(function (LeadQualityHistory $history) use ($append): void {
                $append([
                    'ca_id' => (int) $history->ca_id,
                    'occurred_at' => $history->recorded_at ?? $history->created_at,
                    'type' => 'status_changed',
                    'label' => 'Status Changed',
                    'icon' => 'shield-alert',
                    'employee_name' => $history->employee?->name ?? 'System',
                    'note' => trim((string) ($history->reason ?: $history->event_type ?: '')),
                ]);
            });

        CaMaster::query()
            ->with('createdByEmployee:employee_id,name')
            ->whereIn('ca_id', $caIds)
            ->get(['ca_id', 'created_at', 'updated_at', 'created_by_employee_id'])
            ->each(function (CaMaster $lead) use ($append): void {
                if ($lead->created_at) {
                    $append([
                        'ca_id' => (int) $lead->ca_id,
                        'occurred_at' => $lead->created_at,
                        'type' => 'lead_created',
                        'label' => 'Lead Created',
                        'icon' => 'sparkles',
                        'employee_name' => $lead->createdByEmployee?->name ?? 'System',
                        'note' => '',
                    ]);
                }

                if ($lead->updated_at && $lead->created_at && $lead->updated_at->gt($lead->created_at)) {
                    $append([
                        'ca_id' => (int) $lead->ca_id,
                        'occurred_at' => $lead->updated_at,
                        'type' => 'lead_updated',
                        'label' => 'Lead Updated',
                        'icon' => 'edit-3',
                        'employee_name' => 'System',
                        'note' => '',
                    ]);
                }
            });

        return $grouped;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function formatSummary(array $event): array
    {
        /** @var CarbonInterface $occurredAt */
        $occurredAt = $event['occurred_at'];
        $age = $this->resolveAgeMeta($occurredAt);

        return [
            'occurred_at' => $occurredAt->toIso8601String(),
            'type' => $event['type'],
            'label' => $event['label'],
            'icon' => $event['icon'],
            'employee_name' => $event['employee_name'],
            'note' => $event['note'] ?? '',
            'relative_label' => $age['relative_label'],
            'time_label' => $occurredAt->format('h:i A'),
            'date_label' => $occurredAt->format('d M Y'),
            'age_bucket' => $age['age_bucket'],
            'emoji' => $age['emoji'],
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function formatTimelineItem(array $event): array
    {
        $summary = $this->formatSummary($event);
        $summary['group_label'] = $summary['relative_label'];
        $summary['description'] = $summary['note'];

        return $summary;
    }

    /**
     * @return array{age_bucket: string, relative_label: string, emoji: string}
     */
    private function resolveAgeMeta(CarbonInterface $occurredAt): array
    {
        $now = now();
        $days = (int) $occurredAt->copy()->startOfDay()->diffInDays($now->copy()->startOfDay());

        if ($days === 0) {
            return ['age_bucket' => 'today', 'relative_label' => 'Today', 'emoji' => '🟢'];
        }

        if ($days === 1) {
            return ['age_bucket' => 'yesterday', 'relative_label' => 'Yesterday', 'emoji' => '🟡'];
        }

        if ($days <= 7) {
            return [
                'age_bucket' => 'recent',
                'relative_label' => $days.' Days Ago',
                'emoji' => '🟠',
            ];
        }

        return [
            'age_bucket' => 'old',
            'relative_label' => $days.' Days Ago',
            'emoji' => '🔴',
        ];
    }

    private function followUpHistoryLabel(?string $eventType): string
    {
        $normalized = Str::lower(trim((string) $eventType));

        return match (true) {
            str_contains($normalized, 'call') => 'Call',
            str_contains($normalized, 'demo') => 'Follow-up',
            str_contains($normalized, 'status') => 'Status Changed',
            $eventType !== '' => Str::headline($eventType),
            default => 'Follow-up',
        };
    }
}
