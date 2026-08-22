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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LeadActivityTimelineService
{
    /**
     * @var array<string, string>
     */
    private const TABLE_PRIMARY_KEYS = [
        'call_logs' => 'id',
        'follow_up_histories' => 'history_id',
        'follow_ups' => 'followup_id',
        'lead_actions' => 'action_id',
        'assignment_histories' => 'id',
        'email_logs' => 'id',
        'email_inbound_messages' => 'id',
        'wa_message_logs' => 'id',
        'sms_logs' => 'id',
        'lead_quality_histories' => 'id',
    ];

    /** @var array<string, true> */
    private const SOFT_DELETE_TABLES = [
        'follow_ups' => true,
    ];

    /**
     * Latest-activity summaries for Master/Leads list cells.
     * One SQL union of latest-per-source rows, then one overall latest per CA.
     *
     * @param  list<int>  $caIds
     * @return array<int, array<string, mixed>>
     */
    public function summariesForCaIds(array $caIds): array
    {
        $caIds = array_values(array_unique(array_filter(array_map('intval', $caIds))));
        if ($caIds === []) {
            return [];
        }

        $latestByCaId = $this->latestSummaryEventsForCaIds($caIds);
        $summaries = [];

        foreach ($latestByCaId as $caId => $event) {
            $summaries[(int) $caId] = $this->formatSummary($event);
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
     * Single-pass latest activity for list cells: one UNION of per-source latest
     * rows, then one overall latest per ca_id (avoids N schema checks + N hydrates).
     *
     * @param  list<int>  $caIds
     * @return array<int, array<string, mixed>>
     */
    private function latestSummaryEventsForCaIds(array $caIds): array
    {
        $placeholders = implode(',', array_fill(0, count($caIds), '?'));
        $unions = [];
        $bindings = [];

        $pushUnion = function (string $sql, array $bind) use (&$unions, &$bindings): void {
            $unions[] = $sql;
            foreach ($bind as $value) {
                $bindings[] = $value;
            }
        };

        // Each branch returns at most one row per ca_id (activity_rn = 1).
        $this->appendLatestSourceUnion(
            $pushUnion,
            'call_logs',
            $placeholders,
            $caIds,
            'COALESCE(called_at, created_at)',
            "'call'",
            "'Call'",
            "'phone'",
            'employee_id',
            "TRIM(COALESCE(NULLIF(call_note, ''), call_status, ''))",
            null,
        );

        $this->appendLatestSourceUnion(
            $pushUnion,
            'follow_up_histories',
            $placeholders,
            $caIds,
            'created_at',
            "'follow_up'",
            'event_type',
            "'calendar-clock'",
            'employee_id',
            "TRIM(COALESCE(NULLIF(remarks, ''), outcome, ''))",
            'performed_by',
        );

        $this->appendLatestSourceUnion(
            $pushUnion,
            'follow_ups',
            $placeholders,
            $caIds,
            'COALESCE(updated_at, created_at)',
            "'follow_up'",
            "'Follow-up'",
            "'calendar-clock'",
            'employee_id',
            "TRIM(COALESCE(NULLIF(remarks, ''), NULLIF(outcome, ''), followup_type, ''))",
            null,
        );

        $this->appendLatestSourceUnion(
            $pushUnion,
            'lead_actions',
            $placeholders,
            $caIds,
            'COALESCE(action_at, created_at)',
            "'lead_action'",
            "COALESCE(NULLIF(action_type, ''), 'Lead Action')",
            "'git-branch'",
            'employee_id',
            'TRIM(COALESCE(remarks, \'\'))',
            null,
        );

        $this->appendLatestSourceUnion(
            $pushUnion,
            'assignment_histories',
            $placeholders,
            $caIds,
            'COALESCE(assigned_at, created_at)',
            "'assignment'",
            "'Assignment Changed'",
            "'user-check'",
            'assigned_by',
            "TRIM(COALESCE(NULLIF(reason, ''), assignment_type, ''))",
            null,
            'new_employee_id',
        );

        $this->appendLatestSourceUnion(
            $pushUnion,
            'email_logs',
            $placeholders,
            $caIds,
            'COALESCE(reply_received_at, sent_at, created_at)',
            "'email'",
            "CASE WHEN reply_received_at IS NOT NULL THEN 'Email Reply' ELSE 'Email' END",
            "'mail'",
            'employee_id',
            'TRIM(COALESCE(subject, \'\'))',
            null,
        );

        $this->appendLatestSourceUnion(
            $pushUnion,
            'email_inbound_messages',
            $placeholders,
            $caIds,
            'COALESCE(received_at, created_at)',
            "'email'",
            "'Email'",
            "'mail'",
            null,
            'TRIM(COALESCE(subject, \'\'))',
            'from_email',
        );

        $this->appendLatestSourceUnion(
            $pushUnion,
            'wa_message_logs',
            $placeholders,
            $caIds,
            'COALESCE(sent_at, delivered_at, created_at)',
            "'whatsapp'",
            "'WhatsApp'",
            "'message-circle'",
            'employee_id',
            "TRIM(COALESCE(NULLIF(message, ''), template_name, ''))",
            null,
        );

        $this->appendLatestSourceUnion(
            $pushUnion,
            'sms_logs',
            $placeholders,
            $caIds,
            'COALESCE(sent_at, delivered_at, created_at)',
            "'sms'",
            "'SMS'",
            "'message-square'",
            'employee_id',
            'TRIM(COALESCE(message, \'\'))',
            null,
        );

        $this->appendLatestSourceUnion(
            $pushUnion,
            'lead_quality_histories',
            $placeholders,
            $caIds,
            'COALESCE(recorded_at, created_at)',
            "'status_changed'",
            "'Status Changed'",
            "'shield-alert'",
            'employee_id',
            "TRIM(COALESCE(NULLIF(reason, ''), event_type, ''))",
            null,
        );

        // Lead created/updated markers (always present for listed CAs).
            $pushUnion(
                "SELECT ca_id, created_at AS occurred_at, 'lead_created' AS type, 'Lead Created' AS label, 'sparkles' AS icon,
                        created_by_employee_id AS employee_id, NULL AS employee_id_alt, '' AS note, NULL AS name_fallback
                 FROM ca_masters
                 WHERE ca_id IN ({$placeholders}) AND created_at IS NOT NULL",
                $caIds,
            );
            $pushUnion(
                "SELECT ca_id, updated_at AS occurred_at, 'lead_updated' AS type, 'Lead Updated' AS label, 'edit-3' AS icon,
                        NULL AS employee_id, NULL AS employee_id_alt, '' AS note, 'System' AS name_fallback
                 FROM ca_masters
                 WHERE ca_id IN ({$placeholders})
                   AND updated_at IS NOT NULL
                   AND created_at IS NOT NULL
                   AND updated_at > created_at",
                $caIds,
            );

        if ($unions === []) {
            return [];
        }

        $unionSql = implode("\nUNION ALL\n", $unions);
        $sql = "SELECT ca_id, occurred_at, type, label, icon, employee_id, employee_id_alt, note, name_fallback
                FROM (
                    SELECT events.*,
                           ROW_NUMBER() OVER (
                               PARTITION BY ca_id
                               ORDER BY occurred_at DESC, ca_id DESC
                           ) AS summary_rn
                    FROM (
                        {$unionSql}
                    ) events
                    WHERE occurred_at IS NOT NULL
                ) ranked
                WHERE summary_rn = 1";

        try {
            $rows = DB::select($sql, $bindings);
        } catch (\Throwable) {
            // Fallback preserves prior multi-query behavior if window/union unsupported.
            return $this->latestSummaryEventsFallback($caIds);
        }

        $employeeIds = [];
        foreach ($rows as $row) {
            foreach ([(int) ($row->employee_id ?? 0), (int) ($row->employee_id_alt ?? 0)] as $employeeId) {
                if ($employeeId > 0) {
                    $employeeIds[$employeeId] = true;
                }
            }
        }

        $employeeNames = $employeeIds === []
            ? []
            : DB::table('employees')
                ->whereIn('employee_id', array_keys($employeeIds))
                ->pluck('name', 'employee_id')
                ->all();

        $events = [];
        foreach ($rows as $row) {
            $caId = (int) $row->ca_id;
            $occurredAt = Carbon::parse($row->occurred_at);
            $employeeName = $row->name_fallback ?: null;
            $primaryEmployeeId = (int) ($row->employee_id ?? 0);
            $altEmployeeId = (int) ($row->employee_id_alt ?? 0);
            if ($primaryEmployeeId > 0 && isset($employeeNames[$primaryEmployeeId])) {
                $employeeName = $employeeNames[$primaryEmployeeId];
            } elseif ($altEmployeeId > 0 && isset($employeeNames[$altEmployeeId])) {
                $employeeName = $employeeNames[$altEmployeeId];
            }
            if ($employeeName === null || $employeeName === '') {
                $employeeName = 'System';
            }

            $label = (string) ($row->label ?? '');
            if (($row->type ?? '') === 'follow_up' && $label !== 'Follow-up') {
                $label = $this->followUpHistoryLabel($label);
            }

            $events[$caId] = [
                'ca_id' => $caId,
                'occurred_at' => $occurredAt,
                'type' => (string) $row->type,
                'label' => $label !== '' ? $label : 'Activity',
                'icon' => (string) ($row->icon ?: 'activity'),
                'employee_name' => $employeeName,
                'note' => trim((string) ($row->note ?? '')),
            ];
        }

        return $events;
    }

    /**
     * @param  callable(string, array<int, mixed>): void  $pushUnion
     * @param  list<int>  $caIds
     */
    private function appendLatestSourceUnion(
        callable $pushUnion,
        string $table,
        string $placeholders,
        array $caIds,
        string $orderExpression,
        string $typeSql,
        string $labelSql,
        string $iconSql,
        ?string $employeeIdColumn,
        string $noteSql,
        ?string $nameFallbackColumn,
        ?string $altEmployeeIdColumn = null,
    ): void {
        $pk = self::TABLE_PRIMARY_KEYS[$table] ?? null;
        if ($pk === null) {
            return;
        }

        if (preg_match('/[^a-zA-Z0-9_,\s\(\)]/', $orderExpression) === 1) {
            $orderExpression = 'created_at';
        }

        $softDelete = isset(self::SOFT_DELETE_TABLES[$table]) ? ' AND deleted_at IS NULL' : '';
        $employeeSelect = $employeeIdColumn ?? 'NULL';
        $altEmployeeSelect = $altEmployeeIdColumn ?? 'NULL';
        $fallbackSelect = $nameFallbackColumn ?? 'NULL';

        $pushUnion(
            "SELECT ca_id, occurred_at, type, label, icon, employee_id, employee_id_alt, note, name_fallback
             FROM (
                SELECT ca_id,
                       {$orderExpression} AS occurred_at,
                       {$typeSql} AS type,
                       {$labelSql} AS label,
                       {$iconSql} AS icon,
                       {$employeeSelect} AS employee_id,
                       {$altEmployeeSelect} AS employee_id_alt,
                       {$noteSql} AS note,
                       {$fallbackSelect} AS name_fallback,
                       ROW_NUMBER() OVER (
                           PARTITION BY ca_id
                           ORDER BY {$orderExpression} DESC, {$pk} DESC
                       ) AS activity_rn
                FROM {$table}
                WHERE ca_id IN ({$placeholders}){$softDelete}
             ) source_ranked
             WHERE activity_rn = 1",
            $caIds,
        );
    }

    /**
     * @param  list<int>  $caIds
     * @return array<int, array<string, mixed>>
     */
    private function latestSummaryEventsFallback(array $caIds): array
    {
        $grouped = $this->collectLatestEventsForCaIds($caIds);
        $events = [];

        foreach ($grouped as $caId => $sourceEvents) {
            $latest = collect($sourceEvents)
                ->sortByDesc(fn (array $event) => $event['occurred_at']->timestamp)
                ->first();
            if ($latest !== null) {
                $events[(int) $caId] = $latest;
            }
        }

        return $events;
    }

    /**
     * Latest event only per activity source per CA (fallback path).
     *
     * @param  list<int>  $caIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function collectLatestEventsForCaIds(array $caIds): array
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

        $this->latestCallLogs($caIds)->each(function (CallLog $log) use ($append): void {
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

        $this->latestModels(FollowUpHistory::query()->with('employee:employee_id,name'), 'follow_up_histories', $caIds, 'created_at')
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

        $this->latestModels(FollowUp::query()->with('employee:employee_id,name'), 'follow_ups', $caIds, 'COALESCE(updated_at, created_at)')
            ->each(function (FollowUp $followUp) use ($append): void {
                $append([
                    'ca_id' => (int) $followUp->ca_id,
                    'occurred_at' => $followUp->updated_at ?? $followUp->created_at,
                    'type' => 'follow_up',
                    'label' => 'Follow-up',
                    'icon' => 'calendar-clock',
                    'employee_name' => $followUp->employee?->name ?? 'System',
                    'note' => trim((string) ($followUp->remarks ?: $followUp->outcome ?: $followUp->followup_type ?: '')),
                ]);
            });

        $this->latestModels(LeadAction::query()->with('employee:employee_id,name'), 'lead_actions', $caIds, 'COALESCE(action_at, created_at)')
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

        $this->latestModels(
            AssignmentHistory::query()->with(['newEmployee:employee_id,name', 'assignedByEmployee:employee_id,name']),
            'assignment_histories',
            $caIds,
            'COALESCE(assigned_at, created_at)'
        )->each(function (AssignmentHistory $history) use ($append): void {
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

        $this->latestModels(EmailLog::query()->with('employee:employee_id,name'), 'email_logs', $caIds, 'COALESCE(reply_received_at, sent_at, created_at)')
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

        $this->latestModels(EmailInboundMessage::query(), 'email_inbound_messages', $caIds, 'COALESCE(received_at, created_at)')
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

        $this->latestModels(WaMessageLog::query()->with('employee:employee_id,name'), 'wa_message_logs', $caIds, 'COALESCE(sent_at, delivered_at, created_at)')
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

        $this->latestModels(SmsLog::query()->with('employee:employee_id,name'), 'sms_logs', $caIds, 'COALESCE(sent_at, delivered_at, created_at)')
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

        $this->latestModels(LeadQualityHistory::query()->with('employee:employee_id,name'), 'lead_quality_histories', $caIds, 'COALESCE(recorded_at, created_at)')
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
     * @param  list<int>  $caIds
     * @return Collection<int, CallLog>
     */
    private function latestCallLogs(array $caIds): Collection
    {
        return $this->latestModels(
            CallLog::query()->with('employee:employee_id,name'),
            'call_logs',
            $caIds,
            'COALESCE(called_at, created_at)'
        );
    }

    /**
     * Load at most one latest row per ca_id for the given table.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<int>  $caIds
     * @return Collection<int, TModel>
     */
    private function latestModels(Builder $query, string $table, array $caIds, string $orderExpression): Collection
    {
        if ($caIds === [] || ! isset(self::TABLE_PRIMARY_KEYS[$table])) {
            return collect();
        }

        $pk = self::TABLE_PRIMARY_KEYS[$table];
        $ids = $this->latestRowIds($table, $caIds, $orderExpression);
        if ($ids === []) {
            return collect();
        }

        return $query->whereIn($table.'.'.$pk, $ids)->get();
    }

    /**
     * @param  list<int>  $caIds
     * @return list<int>
     */
    private function latestRowIds(string $table, array $caIds, string $orderExpression): array
    {
        $pk = self::TABLE_PRIMARY_KEYS[$table] ?? null;
        if ($pk === null) {
            return [];
        }

        // Allow only safe SQL identifiers / COALESCE(...) patterns built by this class.
        if (preg_match('/[^a-zA-Z0-9_,\s\(\)]/', $orderExpression) === 1) {
            $orderExpression = 'created_at';
        }

        $placeholders = implode(',', array_fill(0, count($caIds), '?'));
        $softDelete = isset(self::SOFT_DELETE_TABLES[$table]) ? ' AND deleted_at IS NULL' : '';

        try {
            $rows = DB::select(
                "SELECT {$pk} AS activity_id FROM (
                    SELECT {$pk}, ROW_NUMBER() OVER (
                        PARTITION BY ca_id
                        ORDER BY {$orderExpression} DESC, {$pk} DESC
                    ) AS activity_rn
                    FROM {$table}
                    WHERE ca_id IN ({$placeholders}){$softDelete}
                ) ranked WHERE activity_rn = 1",
                $caIds
            );

            return array_values(array_map(static fn ($row) => (int) $row->activity_id, $rows));
        } catch (\Throwable) {
            // Fallback for engines without window functions: fetch ordered rows and unique in PHP.
            $builder = DB::table($table)->whereIn('ca_id', $caIds);
            if (isset(self::SOFT_DELETE_TABLES[$table])) {
                $builder->whereNull('deleted_at');
            }

            return $builder
                ->orderByRaw($orderExpression.' DESC')
                ->orderByDesc($pk)
                ->get([$pk, 'ca_id'])
                ->unique('ca_id')
                ->pluck($pk)
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }
    }

    /**
     * Full history for drawer timeline (unchanged behavior).
     *
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
    public static function ageMetaFor(mixed $occurredAt): array
    {
        $at = $occurredAt instanceof CarbonInterface
            ? $occurredAt
            : Carbon::parse($occurredAt);
        $now = now();
        $days = (int) $at->copy()->startOfDay()->diffInDays($now->copy()->startOfDay());

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

    /**
     * @return array{age_bucket: string, relative_label: string, emoji: string}
     */
    private function resolveAgeMeta(CarbonInterface $occurredAt): array
    {
        return self::ageMetaFor($occurredAt);
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
