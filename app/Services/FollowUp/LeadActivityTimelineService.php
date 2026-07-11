<?php

namespace App\Services\FollowUp;

use App\Models\ActivityLog;
use App\Models\CallLog;
use App\Models\CaMaster;
use App\Models\EmailLog;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\FollowUpHistory;
use App\Models\LeadAction;
use App\Models\LeadAssignmentEngine;
use App\Models\SmsLog;
use App\Models\User;
use App\Models\WaMessageLog;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Services\Rbac\RbacService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class LeadActivityTimelineService
{
    private const EMAIL_SUCCESS = ['Sent', 'Delivered', 'Mapped', 'Queued'];

    private const SMS_SUCCESS = ['Sent', 'Delivered', 'Mapped', 'Queued', 'Pending'];

    private const WA_SUCCESS = ['Sent', 'Delivered', 'Read', 'Queued'];

    public function __construct(
        private readonly EmployeeDataScopeService $employeeDataScope,
        private readonly RbacService $rbacService,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forLead(int $caId, string $sort = 'desc', int $limit = 150): Collection
    {
        $lead = CaMaster::query()
            ->select(['ca_id', 'firm_name', 'ca_name'])
            ->find($caId);

        $items = $this->buildLeadItems($caId, $lead);

        return $this->sortItems($items, $sort)->take($limit)->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forFollowUp(int $followupId, string $sort = 'desc', int $limit = 150): Collection
    {
        $followUp = FollowUp::query()->findOrFail($followupId);
        $caId = (int) $followUp->ca_id;

        $items = $this->buildLeadItems($caId, null);

        return $this->sortItems($items, $sort)->take($limit)->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: Collection<int, array<string, mixed>>, pagination: array<string, int>}
     */
    public function feed(?User $user, array $filters = []): array
    {
        $user ??= auth()->user();
        $sort = ($filters['sort'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 30)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $caIds = $this->scopedCaIds($user, $filters);
        if ($caIds === []) {
            return [
                'items' => collect(),
                'pagination' => [
                    'current_page' => $page,
                    'last_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                ],
            ];
        }

        $items = collect();
        foreach ($caIds as $caId) {
            $items = $items->merge($this->buildLeadItems((int) $caId, null));
        }

        if (! empty($filters['employee_id'])) {
            $employeeFilter = (int) $filters['employee_id'];
            $items = $items->filter(fn (array $row) => (int) ($row['employee_id'] ?? 0) === $employeeFilter);
        }

        if (! empty($filters['activity_type'])) {
            $typeFilter = (string) $filters['activity_type'];
            $items = $items->filter(fn (array $row) => ($row['activity_type'] ?? '') === $typeFilter);
        }

        $items = $this->sortItems($items, $sort)->values();
        $total = $items->count();
        $offset = ($page - 1) * $perPage;
        $pageItems = $items->slice($offset, $perPage)->values();

        return [
            'items' => $pageItems,
            'pagination' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildLeadItems(int $caId, ?CaMaster $lead): Collection
    {
        $lead ??= CaMaster::query()
            ->select(['ca_id', 'firm_name', 'ca_name'])
            ->find($caId);

        $items = collect();
        $usedKeys = collect();

        $histories = FollowUpHistory::query()
            ->with(['employee:employee_id,name', 'followUp:followup_id,followup_type,scheduled_date,next_followup_date'])
            ->where('ca_id', $caId)
            ->orderByDesc('created_at')
            ->limit(300)
            ->get();

        foreach ($histories as $history) {
            $callLogId = (int) ($history->metadata['call_log_id'] ?? 0);
            if ($callLogId > 0) {
                $usedKeys->push('call_log:'.$callLogId);
            }
            $usedKeys->push('history:'.$history->history_id);
            $items->push($this->normalizeHistory($history, $lead));
        }

        CallLog::query()
            ->with(['employee:employee_id,name', 'followUp:followup_id,scheduled_date,next_followup_date'])
            ->where('ca_id', $caId)
            ->orderByDesc('called_at')
            ->limit(200)
            ->get()
            ->each(function (CallLog $callLog) use ($items, $usedKeys, $lead) {
                $key = 'call_log:'.$callLog->id;
                if ($usedKeys->contains($key)) {
                    return;
                }
                $usedKeys->push($key);
                $items->push($this->normalizeCallLog($callLog, $lead));
            });

        LeadAction::query()
            ->with(['employee:employee_id,name'])
            ->where('ca_id', $caId)
            ->orderByDesc('action_at')
            ->limit(100)
            ->get()
            ->each(function (LeadAction $action) use ($items, $usedKeys, $lead) {
                $key = 'lead_action:'.$action->action_id;
                if ($usedKeys->contains($key)) {
                    return;
                }
                $usedKeys->push($key);
                $items->push($this->normalizeLeadAction($action, $lead));
            });

        $this->appendCommunicationLogs($caId, $lead, $items, $usedKeys);

        ActivityLog::query()
            ->where('record_id', (string) $caId)
            ->whereIn('module_name', ['FOLLOW_UP_MANAGEMENT', 'CA_MASTER', 'LEAD_ACTION'])
            ->orderByDesc('created_at')
            ->limit(80)
            ->get()
            ->each(function (ActivityLog $log) use ($items, $usedKeys, $lead) {
                if ($this->isDuplicateActivityLog($log, $usedKeys)) {
                    return;
                }
                $key = 'activity_log:'.$log->id;
                $usedKeys->push($key);
                $items->push($this->normalizeActivityLog($log, $lead));
            });

        return $items;
    }

    /**
     * @return list<int>
     */
    private function scopedCaIds(?User $user, array $filters): array
    {
        if (! empty($filters['ca_id'])) {
            $caId = (int) $filters['ca_id'];
            $this->employeeDataScope->ensureCanAccessCaMaster($caId);

            return [$caId];
        }

        $scopedEmployeeId = $this->employeeDataScope->scopedEmployeeId($user);
        if ($scopedEmployeeId !== null) {
            if ($scopedEmployeeId <= 0) {
                return [];
            }

            return LeadAssignmentEngine::query()
                ->where('employee_id', $scopedEmployeeId)
                ->where('status', 'Active')
                ->pluck('ca_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        $role = $this->rbacService->roleKey($user);
        if ($role === 'manager') {
            $employeeIds = Employee::query()
                ->where('status', 'Active')
                ->where(function ($q) {
                    $q->whereNull('role')
                        ->orWhere('role', 'ilike', '%executive%')
                        ->orWhere('role', 'ilike', '%employee%')
                        ->orWhere('role', 'ilike', '%sales%');
                })
                ->pluck('employee_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($employeeIds === []) {
                return [];
            }

            $fromAssignments = LeadAssignmentEngine::query()
                ->whereIn('employee_id', $employeeIds)
                ->where('status', 'Active')
                ->pluck('ca_id');

            $fromHistories = FollowUpHistory::query()
                ->whereIn('employee_id', $employeeIds)
                ->pluck('ca_id');

            return $fromAssignments->merge($fromHistories)
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->take(500)
                ->all();
        }

        return $this->recentCaIdsFromActivitySources(500);
    }

    /**
     * @return list<int>
     */
    private function recentCaIdsFromActivitySources(int $limit): array
    {
        $fromHistories = FollowUpHistory::query()
            ->select('ca_id')
            ->selectRaw('MAX(created_at) as latest_at')
            ->groupBy('ca_id')
            ->orderByDesc('latest_at')
            ->limit($limit)
            ->pluck('ca_id');

        $fromCalls = CallLog::query()
            ->select('ca_id')
            ->selectRaw('MAX(called_at) as latest_at')
            ->groupBy('ca_id')
            ->orderByDesc('latest_at')
            ->limit($limit)
            ->pluck('ca_id');

        return $fromHistories
            ->merge($fromCalls)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->take($limit)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeHistory(FollowUpHistory $history, ?CaMaster $lead): array
    {
        $occurredAt = $history->created_at ?? now();
        $followUp = $history->followUp;
        $eventType = (string) $history->event_type;
        $isCall = in_array($eventType, ['Call Logged', 'Call Created', 'Call Status'], true);

        return [
            'activity_id' => 'history:'.$history->history_id,
            'source_table' => 'follow_up_histories',
            'source_id' => $history->history_id,
            'history_id' => $history->history_id,
            'followup_id' => $history->followup_id,
            'ca_id' => (int) $history->ca_id,
            'employee_id' => $history->employee_id ? (int) $history->employee_id : null,
            'activity_type' => $eventType,
            'activity_label' => $eventType,
            'icon' => $this->iconForType($eventType),
            'firm_name' => $lead?->firm_name,
            'ca_name' => $lead?->ca_name,
            'call_status' => $isCall ? ($history->outcome ?? null) : null,
            'call_notes' => $isCall ? ($history->remarks ?? null) : null,
            'status' => $history->outcome,
            'notes' => $history->remarks,
            'employee_name' => $history->employee?->name ?? $history->performed_by,
            'performed_by' => $history->performed_by,
            'created_by' => $history->performed_by,
            'next_action' => $this->nextActionLabel($eventType, $history->outcome),
            'followup_date' => $followUp?->scheduled_date?->toDateString(),
            'next_followup_date' => $followUp?->next_followup_date?->toDateString(),
            'metadata' => $history->metadata ?? [],
            'occurred_at' => $occurredAt->toIso8601String(),
            'date_label' => $occurredAt->format('d M Y'),
            'time_label' => $occurredAt->format('H:i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeCallLog(CallLog $callLog, ?CaMaster $lead): array
    {
        $occurredAt = $callLog->called_at ?? $callLog->created_at ?? now();
        $followUp = $callLog->followUp;

        return [
            'activity_id' => 'call_log:'.$callLog->id,
            'source_table' => 'call_logs',
            'source_id' => $callLog->id,
            'history_id' => null,
            'followup_id' => $callLog->followup_id,
            'ca_id' => (int) $callLog->ca_id,
            'employee_id' => $callLog->employee_id ? (int) $callLog->employee_id : null,
            'activity_type' => 'Call Logged',
            'activity_label' => 'Call Logged',
            'icon' => 'phone',
            'firm_name' => $lead?->firm_name,
            'ca_name' => $lead?->ca_name,
            'call_status' => $callLog->call_status,
            'call_notes' => $callLog->call_note,
            'status' => $callLog->call_status,
            'notes' => $callLog->call_note,
            'employee_name' => $callLog->employee?->name,
            'performed_by' => $callLog->employee?->name,
            'created_by' => $callLog->employee?->name,
            'next_action' => $this->nextActionLabel('Call Logged', $callLog->call_status),
            'followup_date' => $followUp?->scheduled_date?->toDateString(),
            'next_followup_date' => $followUp?->next_followup_date?->toDateString(),
            'metadata' => ['call_log_id' => $callLog->id],
            'occurred_at' => $occurredAt->toIso8601String(),
            'date_label' => $occurredAt->format('d M Y'),
            'time_label' => $occurredAt->format('H:i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeLeadAction(LeadAction $action, ?CaMaster $lead): array
    {
        $occurredAt = $action->action_at ?? $action->created_at ?? now();

        return [
            'activity_id' => 'lead_action:'.$action->action_id,
            'source_table' => 'lead_actions',
            'source_id' => $action->action_id,
            'history_id' => null,
            'followup_id' => null,
            'ca_id' => (int) $action->ca_id,
            'employee_id' => $action->employee_id ? (int) $action->employee_id : null,
            'activity_type' => (string) $action->action_type,
            'activity_label' => (string) $action->action_type,
            'icon' => $this->iconForType((string) $action->action_type),
            'firm_name' => $lead?->firm_name,
            'ca_name' => $lead?->ca_name,
            'call_status' => null,
            'call_notes' => null,
            'status' => $action->action_type,
            'notes' => $action->remarks,
            'employee_name' => $action->employee?->name,
            'performed_by' => $action->employee?->name,
            'created_by' => $action->employee?->name,
            'next_action' => null,
            'followup_date' => null,
            'next_followup_date' => null,
            'metadata' => [],
            'occurred_at' => $occurredAt->toIso8601String(),
            'date_label' => $occurredAt->format('d M Y'),
            'time_label' => $occurredAt->format('H:i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeActivityLog(ActivityLog $log, ?CaMaster $lead): array
    {
        $occurredAt = $log->created_at ?? now();

        return [
            'activity_id' => 'activity_log:'.$log->id,
            'source_table' => 'activity_logs',
            'source_id' => $log->id,
            'history_id' => null,
            'followup_id' => is_numeric($log->record_id) ? (int) $log->record_id : null,
            'ca_id' => is_numeric($log->record_id) ? (int) $log->record_id : null,
            'employee_id' => null,
            'activity_type' => (string) $log->action,
            'activity_label' => (string) $log->action,
            'icon' => $this->iconForType((string) $log->action),
            'firm_name' => $lead?->firm_name,
            'ca_name' => $lead?->ca_name,
            'call_status' => str_contains((string) $log->action, 'Call') ? $log->description : null,
            'call_notes' => str_contains((string) $log->action, 'Call') ? $log->description : null,
            'status' => null,
            'notes' => $log->description,
            'employee_name' => $log->performed_by,
            'performed_by' => $log->performed_by,
            'created_by' => $log->performed_by,
            'next_action' => null,
            'followup_date' => null,
            'next_followup_date' => null,
            'metadata' => [],
            'occurred_at' => $occurredAt->toIso8601String(),
            'date_label' => $occurredAt->format('d M Y'),
            'time_label' => $occurredAt->format('H:i'),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @param  Collection<int, string>  $usedKeys
     */
    private function appendCommunicationLogs(int $caId, ?CaMaster $lead, Collection $items, Collection $usedKeys): void
    {
        EmailLog::query()
            ->where('ca_id', $caId)
            ->whereIn('email_status', self::EMAIL_SUCCESS)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->each(function (EmailLog $log) use ($items, $usedKeys, $lead) {
                $key = 'email_log:'.$log->id;
                if ($usedKeys->contains($key)) {
                    return;
                }
                $usedKeys->push($key);
                $items->push($this->normalizeCommunication($log, $lead, 'Email Sent', 'mail', $log->created_at, $log->subject));
            });

        SmsLog::query()
            ->where('ca_id', $caId)
            ->whereIn('sms_status', self::SMS_SUCCESS)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->each(function (SmsLog $log) use ($items, $usedKeys, $lead) {
                $key = 'sms_log:'.$log->id;
                if ($usedKeys->contains($key)) {
                    return;
                }
                $usedKeys->push($key);
                $items->push($this->normalizeCommunication($log, $lead, 'SMS Sent', 'smartphone', $log->sent_at ?? $log->created_at, $log->message));
            });

        WaMessageLog::query()
            ->where('ca_id', $caId)
            ->whereIn('message_status', self::WA_SUCCESS)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->each(function (WaMessageLog $log) use ($items, $usedKeys, $lead) {
                $key = 'wa_log:'.$log->id;
                if ($usedKeys->contains($key)) {
                    return;
                }
                $usedKeys->push($key);
                $items->push($this->normalizeCommunication($log, $lead, 'WhatsApp Sent', 'message-circle', $log->sent_at ?? $log->created_at, $log->message));
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeCommunication(object $log, ?CaMaster $lead, string $type, string $icon, mixed $at, ?string $notes): array
    {
        $occurredAt = $at instanceof Carbon ? $at : Carbon::parse($at ?? now());

        return [
            'activity_id' => strtolower(str_replace(' ', '_', $type)).':'.$log->id,
            'source_table' => $log->getTable(),
            'source_id' => $log->id,
            'history_id' => null,
            'followup_id' => null,
            'ca_id' => (int) ($log->ca_id ?? 0),
            'employee_id' => isset($log->employee_id) ? (int) $log->employee_id : null,
            'activity_type' => $type,
            'activity_label' => $type,
            'icon' => $icon,
            'firm_name' => $lead?->firm_name,
            'ca_name' => $lead?->ca_name,
            'call_status' => null,
            'call_notes' => null,
            'status' => $type,
            'notes' => $notes,
            'employee_name' => null,
            'performed_by' => null,
            'created_by' => null,
            'next_action' => null,
            'followup_date' => null,
            'next_followup_date' => null,
            'metadata' => [],
            'occurred_at' => $occurredAt->toIso8601String(),
            'date_label' => $occurredAt->format('d M Y'),
            'time_label' => $occurredAt->format('H:i'),
        ];
    }

    private function isDuplicateActivityLog(ActivityLog $log, Collection $usedKeys): bool
    {
        $action = (string) $log->action;
        $duplicateActions = [
            'Call Logged', 'Call Created', 'Follow-up Create', 'Follow-up Completed',
            'Follow-up Update', 'Demo Scheduled',
        ];

        return in_array($action, $duplicateActions, true);
    }

    private function nextActionLabel(string $eventType, ?string $outcome): ?string
    {
        if ($outcome === 'Follow-up Required') {
            return 'Schedule follow-up';
        }
        if ($outcome === 'Demo Scheduled') {
            return 'Demo scheduled';
        }
        if ($eventType === 'Follow-up Created') {
            return 'Follow-up pending';
        }

        return null;
    }

    private function iconForType(string $type): string
    {
        return match (true) {
            str_contains($type, 'Call') => 'phone',
            str_contains($type, 'Demo') => 'video',
            str_contains($type, 'Follow-up') || str_contains($type, 'Follow Up') => 'calendar',
            str_contains($type, 'Email') => 'mail',
            str_contains($type, 'SMS') => 'smartphone',
            str_contains($type, 'WhatsApp') => 'message-circle',
            str_contains($type, 'Purchased') => 'shopping-bag',
            str_contains($type, 'Not Interested') => 'x-circle',
            str_contains($type, 'Status') => 'git-branch',
            default => 'activity',
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function sortItems(Collection $items, string $sort): Collection
    {
        return $sort === 'asc'
            ? $items->sortBy('occurred_at')->values()
            : $items->sortByDesc('occurred_at')->values();
    }
}
