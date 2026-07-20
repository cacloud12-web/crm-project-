<?php

namespace App\Services\Dashboard;

use App\Models\CallLog;
use App\Models\CaMaster;
use App\Models\EmailLog;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\LeadAssignmentEngine;
use App\Models\SmsLog;
use App\Models\User;
use App\Models\WaMessageLog;
use App\Services\Assignment\EmployeeTargetService;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Services\Rbac\RbacService;
use App\Support\Database\SqlAggregate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class ManagerEmployeeProductivityService
{
    private const OPEN_FOLLOWUP = ['Pending', 'Scheduled', 'Open', 'Overdue'];

    private const COMPLETED_FOLLOWUP = ['Completed', 'Closed'];

    private const PIPELINE_STATUSES = [
        'Pipeline',
        'Warm',
        'Demo Scheduled',
        'Demo Completed',
        'Negotiation',
        'Details Shared',
    ];

    private const DEMO_TYPES = ['Demo Scheduled', 'Demo Completed', 'Meeting'];

    public function __construct(
        private readonly RbacService $rbacService,
        private readonly EmployeeDataScopeService $employeeDataScope,
        private readonly DemoMetricsService $demoMetricsService,
        private readonly DashboardMetricsService $dashboardMetrics,
        private readonly EmployeeTargetService $employeeTargetService,
    ) {}

    /**
     * @return list<array{employee_id: int, name: string, initials: string, role: ?string, city: ?string}>
     */
    public function listEmployees(?User $viewer = null): array
    {
        $viewer ??= auth()->user();
        $this->assertCanViewSelector($viewer);

        return $this->visibleEmployeesQuery($viewer)
            ->with('city:city_id,city_name')
            ->orderBy('name')
            ->get(['employee_id', 'name', 'role', 'city_id'])
            ->map(fn (Employee $employee) => [
                'employee_id' => (int) $employee->employee_id,
                'name' => $employee->name,
                'initials' => $this->initials($employee->name),
                'role' => $employee->role,
                'city' => $employee->city?->city_name,
            ])
            ->all();
    }

    /**
     * @return list<int>
     */
    public function visibleEmployeeIds(?User $viewer = null): array
    {
        $viewer ??= auth()->user();
        $this->assertCanViewSelector($viewer);

        return $this->visibleEmployeesQuery($viewer)
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function canViewEmployee(int $employeeId, ?User $viewer = null): bool
    {
        $viewer ??= auth()->user();

        return $this->visibleEmployeesQuery($viewer)
            ->where('employee_id', $employeeId)
            ->exists();
    }

    /**
     * @param  array{from: Carbon, to: Carbon, preset?: string, label?: string}|null  $range
     * @return array<string, mixed>
     */
    public function productivity(?int $employeeId = null, ?User $viewer = null, ?array $range = null): array
    {
        $viewer ??= auth()->user();
        $this->assertCanViewSelector($viewer);
        $range ??= DashboardDateRange::resolve('today');

        if ($employeeId) {
            $this->assertCanViewEmployee($viewer, $employeeId);
            $employee = Employee::query()->with('city:city_id,city_name')->findOrFail($employeeId);
            $metrics = $this->buildMetrics($employeeId, $range['from'], $range['to']);

            return [
                'scope' => 'employee',
                'employee' => [
                    'employee_id' => (int) $employee->employee_id,
                    'name' => $employee->name,
                    'initials' => $this->initials($employee->name),
                    'role' => $employee->role,
                    'city' => $employee->city?->city_name,
                ],
                'date_range' => [
                    'preset' => $range['preset'] ?? 'today',
                    'from' => $range['from']->toDateString(),
                    'to' => $range['to']->toDateString(),
                    'label' => $range['label'] ?? '',
                ],
                'metrics' => $metrics,
                'has_activity' => $this->hasActivityFromMetrics($metrics),
            ];
        }

        $metrics = $this->buildMetrics(null, $range['from'], $range['to']);

        return [
            'scope' => 'all',
            'employee' => null,
            'date_range' => [
                'preset' => $range['preset'] ?? 'today',
                'from' => $range['from']->toDateString(),
                'to' => $range['to']->toDateString(),
                'label' => $range['label'] ?? '',
            ],
            'metrics' => $metrics,
            'has_activity' => $this->hasActivityFromMetrics($metrics),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMetrics(?int $employeeId, Carbon $from, Carbon $to): array
    {
        return [
            'leads' => $this->leadMetrics($employeeId, $from, $to),
            'daily_work' => $this->dailyWorkMetrics($employeeId, $from, $to),
            'demos' => $this->demoMetrics($employeeId, $from, $to),
            'communication' => $this->communicationMetrics($employeeId, $from, $to),
            'performance' => $this->performanceMetrics($employeeId, $from, $to),
            'followups' => $this->followUpMetrics($employeeId, $from, $to),
        ];
    }

    private function applyDateRange(Builder $query, string $column, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween($column, [$from, $to]);
    }

    /**
     * @return array<string, int|float>
     */
    private function leadMetrics(?int $employeeId, Carbon $from, Carbon $to): array
    {
        $snapshot = $this->dashboardMetrics->leadSnapshotCounts($employeeId);
        $total = (int) ($snapshot['total'] ?? 0);
        $converted = (int) ($snapshot['converted'] ?? 0);
        $assigned = $this->dashboardMetrics->assignedLeadSnapshotCount($employeeId);
        $newLeads = $this->dashboardMetrics->newLeadsInPeriod($employeeId, $from, $to);

        return [
            'total_assigned' => $assigned,
            'new_leads' => $newLeads,
            'hot_leads' => (int) ($snapshot['hot'] ?? 0),
            'warm_leads' => (int) ($snapshot['warm'] ?? 0),
            'cold_leads' => (int) ($snapshot['cold'] ?? 0),
            'in_pipeline' => (int) ($snapshot['pipeline'] ?? 0),
            'converted' => $converted,
            'lost' => (int) ($snapshot['lost'] ?? 0),
            'conversion_rate' => $total > 0 ? round(($converted / $total) * 100, 1) : 0.0,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function dailyWorkMetrics(?int $employeeId, Carbon $from, Carbon $to): array
    {
        $open = $this->quotedList(self::OPEN_FOLLOWUP);
        $meetings = $this->quotedList(self::DEMO_TYPES);

        $followUpQuery = FollowUp::query();
        $this->employeeDataScope->scopeFollowUpQuery($followUpQuery, $employeeId);
        $this->applyDateRange($followUpQuery, 'scheduled_date', $from, $to);

        $row = (clone $followUpQuery)
            ->selectRaw(SqlAggregate::countFilter('*', "followup_type = 'Call'").' as calls_today')
            ->selectRaw(SqlAggregate::countFilter('*', "status IN ({$open})").' as followups_today')
            ->selectRaw(SqlAggregate::countFilter('*', "followup_type IN ({$meetings})").' as meetings_today')
            ->first();

        $overdueQuery = FollowUp::query()->whereIn('status', self::OPEN_FOLLOWUP)
            ->where('scheduled_date', '<', $from);
        $this->employeeDataScope->scopeFollowUpQuery($overdueQuery, $employeeId);

        $callsFromLogs = CallLog::query();
        $this->applyDateRange($callsFromLogs, 'called_at', $from, $to);
        if ($employeeId) {
            $callsFromLogs->where('employee_id', $employeeId);
        }

        return [
            'todays_calls' => max((int) ($row->calls_today ?? 0), (int) $callsFromLogs->count()),
            'todays_followups' => (int) ($row->followups_today ?? 0),
            'todays_meetings' => (int) ($row->meetings_today ?? 0),
            'overdue_followups' => (int) $overdueQuery->count(),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function demoMetrics(?int $employeeId, Carbon $from, Carbon $to): array
    {
        return $this->demoMetricsService->aggregateForRange($employeeId, $from, $to);
    }

    /**
     * @return array<string, int>
     */
    private function communicationMetrics(?int $employeeId, Carbon $from, Carbon $to): array
    {
        $emailQuery = EmailLog::query();
        $smsQuery = SmsLog::query();
        $waQuery = WaMessageLog::query();

        if ($employeeId) {
            $emailQuery->where('employee_id', $employeeId);
            $smsQuery->where('employee_id', $employeeId);
            $waQuery->where('employee_id', $employeeId);
        }

        $this->applyDateRange($emailQuery, 'created_at', $from, $to);
        $this->applyDateRange($smsQuery, 'created_at', $from, $to);
        $this->applyDateRange($waQuery, 'created_at', $from, $to);

        $emailsSent = (int) (clone $emailQuery)
            ->whereIn('email_status', ['Sent', 'Delivered', 'Mapped', 'Queued'])
            ->count();
        $smsSent = (int) (clone $smsQuery)
            ->whereIn('sms_status', ['Sent', 'Delivered', 'Mapped', 'Queued', 'Pending'])
            ->count();
        $waSent = (int) (clone $waQuery)
            ->whereIn('message_status', ['Sent', 'Delivered', 'Mapped', 'Queued'])
            ->count();

        $repliesQuery = EmailLog::query();
        if ($employeeId) {
            $repliesQuery->where('employee_id', $employeeId);
        }
        $this->applyDateRange($repliesQuery, 'created_at', $from, $to);
        if (Schema::hasColumn('email_logs', 'direction')) {
            $repliesQuery->where('direction', 'inbound');
        } elseif (Schema::hasColumn('email_logs', 'is_inbound')) {
            $repliesQuery->where('is_inbound', true);
        } else {
            $repliesQuery->where(function ($query) {
                $query->where('email_status', 'Received')
                    ->orWhere('email_status', 'ilike', '%reply%')
                    ->orWhere('email_status', 'ilike', '%received%');
            });
        }

        return [
            'emails_sent' => $emailsSent,
            'sms_sent' => $smsSent,
            'whatsapp_sent' => $waSent,
            'customer_replies' => (int) $repliesQuery->count(),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function performanceMetrics(?int $employeeId, Carbon $from, Carbon $to): array
    {
        unset($from, $to);

        if ($employeeId) {
            $progress = $this->employeeTargetService->todayProgress($employeeId);

            return [
                'target_assigned' => (int) ($progress['today']['demo_target'] ?? 0),
                'target_achieved' => (int) ($progress['today']['demo_achieved'] ?? 0),
                'pending_target' => (int) ($progress['today']['demo_remaining'] ?? 0),
                'productivity_pct' => (float) ($progress['today']['demo_pct'] ?? 0),
            ];
        }

        $teamIds = $this->visibleEmployeeIds();
        $totals = $this->employeeTargetService->organizationTodayTotals($teamIds);

        return [
            'target_assigned' => (int) ($totals['daily_demo_target_total'] ?? 0),
            'target_achieved' => (int) ($totals['daily_demo_achieved_total'] ?? 0),
            'pending_target' => (int) ($totals['daily_demo_remaining_total'] ?? 0),
            'productivity_pct' => (float) ($totals['daily_demo_achievement_pct'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function followUpMetrics(?int $employeeId, Carbon $from, Carbon $to): array
    {
        $open = $this->quotedList(self::OPEN_FOLLOWUP);
        $completed = $this->quotedList(self::COMPLETED_FOLLOWUP);
        $query = FollowUp::query();
        $this->employeeDataScope->scopeFollowUpQuery($query, $employeeId);
        $this->applyDateRange($query, 'scheduled_date', $from, $to);

        $row = (clone $query)
            ->selectRaw(SqlAggregate::countFilter('*', "status IN ({$open})").' as pending')
            ->selectRaw(SqlAggregate::countFilter('*', "status IN ({$completed})").' as completed')
            ->selectRaw(SqlAggregate::countFilter('*', "status IN ({$open}) AND scheduled_date < ?").' as missed', [$from])
            ->first();

        $next = FollowUp::query();
        $this->employeeDataScope->scopeFollowUpQuery($next, $employeeId);
        $next = $next->whereIn('status', self::OPEN_FOLLOWUP)
            ->where('scheduled_date', '>=', $from)
            ->where('scheduled_date', '<=', $to)
            ->orderBy('scheduled_date')
            ->with('caMaster:ca_id,firm_name')
            ->first();

        return [
            'pending' => (int) ($row->pending ?? 0),
            'completed' => (int) ($row->completed ?? 0),
            'missed' => (int) ($row->missed ?? 0),
            'next_upcoming' => $next ? [
                'followup_id' => $next->followup_id,
                'firm_name' => $next->caMaster?->firm_name,
                'followup_type' => $next->followup_type,
                'scheduled_date' => $next->scheduled_date?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function hasActivityFromMetrics(array $metrics): bool
    {
        $leads = $metrics['leads'];
        $daily = $metrics['daily_work'];
        $demos = $metrics['demos'];
        $comm = $metrics['communication'];
        $followups = $metrics['followups'];

        return ($leads['total_assigned']
            + (int) ($leads['hot_leads'] ?? 0)
            + (int) ($leads['warm_leads'] ?? 0)
            + (int) ($leads['cold_leads'] ?? 0)
            + (int) ($leads['in_pipeline'] ?? 0)
            + $daily['todays_calls']
            + $daily['todays_followups']
            + $demos['demos_scheduled']
            + $comm['emails_sent']
            + $comm['sms_sent']
            + $comm['whatsapp_sent']
            + $followups['pending']
            + $followups['completed']) > 0;
    }

    private function visibleEmployeesQuery(User $viewer)
    {
        $query = Employee::query()->where('status', 'Active');

        // No manager→employee hierarchy column exists; managers see active sales staff.
        // Super Admin / Admin see all active employees.
        $role = $this->rbacService->roleKey($viewer);
        if ($role === 'manager') {
            $query->where(function ($q) {
                $q->whereNull('role')
                    ->orWhere('role', 'ilike', '%executive%')
                    ->orWhere('role', 'ilike', '%employee%')
                    ->orWhere('role', 'ilike', '%sales%');
            });
        }

        return $query;
    }

    private function assertCanViewSelector(?User $user): void
    {
        $role = $this->rbacService->roleKey($user);
        if (! in_array($role, ['super_admin', 'admin', 'manager'], true)) {
            abort(403, 'Employee productivity selector is only available to managers and admins.');
        }
    }

    private function assertCanViewEmployee(User $viewer, int $employeeId): void
    {
        $allowed = $this->visibleEmployeesQuery($viewer)
            ->where('employee_id', $employeeId)
            ->exists();

        if (! $allowed) {
            throw new InvalidArgumentException('You do not have access to this employee.');
        }
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = collect($parts)
            ->filter()
            ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->take(2)
            ->implode('');

        return $letters !== '' ? $letters : 'E';
    }

    private function quotedList(array $values): string
    {
        return collect($values)
            ->map(fn (string $value) => DB::getPdo()->quote($value))
            ->implode(', ');
    }
}
