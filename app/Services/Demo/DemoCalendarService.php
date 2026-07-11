<?php

namespace App\Services\Demo;

use App\Models\DemoProvider;
use App\Models\DemoSchedule;
use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Models\User;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Services\Rbac\RbacService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DemoCalendarService
{
    public function __construct(
        private readonly DemoAvailabilityService $availabilityService,
        private readonly EmployeeDataScopeService $employeeDataScope,
        private readonly RbacService $rbacService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(?User $user, array $filters = []): array
    {
        $date = ! empty($filters['date']) ? Carbon::parse($filters['date']) : now();
        $query = $this->scopedQuery($user, $filters)->whereDate('demo_at', $date->toDateString());

        $total = (clone $query)->count();
        $completed = (clone $query)->where('status', DemoSchedule::STATUS_COMPLETED)->count();
        $upcoming = (clone $query)->whereIn('status', [DemoSchedule::STATUS_SCHEDULED, DemoSchedule::STATUS_RESCHEDULED])
            ->where('demo_at', '>=', now())->count();
        $missed = (clone $query)->where('status', DemoSchedule::STATUS_MISSED)->count();
        $cancelled = (clone $query)->where('status', DemoSchedule::STATUS_CANCELLED)->count();

        $providers = DemoProvider::query()->where('is_active', true)->orderBy('sort_order')->get();
        $availableSlots = 0;
        $fullyBookedProviders = 0;
        $providerCounts = [];

        foreach ($providers as $provider) {
            if (! empty($filters['demo_provider_id']) && (int) $filters['demo_provider_id'] !== (int) $provider->id) {
                continue;
            }
            $slots = $this->availabilityService->slotsForProviderDate($provider, $date);
            $available = collect($slots)->where('status', 'available')->count();
            $booked = collect($slots)->whereIn('status', ['scheduled', 'rescheduled', 'completed'])->count();
            $availableSlots += $available;
            $providerCounts[] = [
                'demo_provider_id' => $provider->id,
                'name' => $provider->name,
                'booked' => $booked,
                'available' => $available,
                'fully_booked' => $available === 0 && $booked > 0,
            ];
            if ($available === 0 && $booked > 0) {
                $fullyBookedProviders++;
            }
        }

        $payload = [
            'date' => $date->toDateString(),
            'date_label' => $date->format('d M Y'),
            'total_demos' => $total,
            'completed' => $completed,
            'upcoming' => $upcoming,
            'missed' => $missed,
            'cancelled' => $cancelled,
            'available_slots' => $availableSlots,
            'providers_fully_booked' => $fullyBookedProviders,
            'provider_counts' => $providerCounts,
        ];

        if ($this->canViewTeamMetrics($user)) {
            $payload['employee_counts'] = $this->employeeDemoCounts($user, $date, $filters);
            $payload['peak_hours'] = $this->peakHours($user, $date, $filters);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function events(?User $user, array $filters = []): array
    {
        $view = $filters['view'] ?? 'week';
        $anchor = ! empty($filters['date']) ? Carbon::parse($filters['date']) : now();

        [$from, $to] = match ($view) {
            'day' => [$anchor->copy()->startOfDay(), $anchor->copy()->endOfDay()],
            'month' => [$anchor->copy()->startOfMonth()->startOfDay(), $anchor->copy()->endOfMonth()->endOfDay()],
            'agenda' => [$anchor->copy()->startOfDay(), $anchor->copy()->addDays(30)->endOfDay()],
            default => [$anchor->copy()->startOfWeek(Carbon::MONDAY), $anchor->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay()],
        };

        $rows = $this->scopedQuery($user, $filters)
            ->with(['employee:employee_id,name', 'provider:id,name', 'lead:ca_id,firm_name,ca_name,team_size,city_id'])
            ->whereBetween('demo_at', [$from, $to])
            ->orderBy('demo_at')
            ->limit(500)
            ->get();

        return $rows->map(fn (DemoSchedule $schedule) => $this->serializeEvent($schedule))->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function availableSlots(?User $user, array $filters): array
    {
        $provider = $this->availabilityService->resolveProvider(
            isset($filters['demo_provider_id']) ? (int) $filters['demo_provider_id'] : null,
            isset($filters['team_size']) ? (int) $filters['team_size'] : null,
            $filters['demo_provider_name'] ?? null,
        );

        if (! $provider) {
            return [];
        }

        $date = Carbon::parse($filters['date'] ?? now()->toDateString());
        $slots = $this->availabilityService->availableSlotsForDate($provider, $date);

        $bookedToday = DemoSchedule::query()
            ->where('demo_provider_id', $provider->id)
            ->whereIn('status', [DemoSchedule::STATUS_SCHEDULED, DemoSchedule::STATUS_RESCHEDULED])
            ->whereDate('demo_at', $date->toDateString())
            ->count();

        $availabilityLabel = 'Available';
        if ($this->availabilityService->isProviderOnLeave($provider->id, $date->toDateString())) {
            $availabilityLabel = 'Provider on Leave';
        } elseif ($bookedToday >= (int) $provider->max_demos_per_day) {
            $availabilityLabel = 'Fully Booked';
        } elseif (count($slots) <= 2 && count($slots) > 0) {
            $availabilityLabel = 'Only '.count($slots).' Slots Left';
        } elseif ($slots === []) {
            $availabilityLabel = 'Fully Booked';
        }

        return [
            'provider' => [
                'id' => $provider->id,
                'name' => $provider->name,
                'meeting_link' => $provider->default_meeting_link,
            ],
            'date' => $date->toDateString(),
            'availability_label' => $availabilityLabel,
            'slots' => $slots,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function providers(): array
    {
        return DemoProvider::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (DemoProvider $provider) => [
                'id' => $provider->id,
                'name' => $provider->name,
                'default_meeting_link' => $provider->default_meeting_link,
                'min_team_size' => $provider->min_team_size,
                'max_team_size' => $provider->max_team_size,
                'slot_duration_minutes' => $provider->slot_duration_minutes,
            ])
            ->all();
    }

    public function canManageSchedule(?User $user, DemoSchedule $schedule): bool
    {
        $role = $this->rbacService->roleKey($user);
        if (in_array($role, ['super_admin', 'admin', 'manager'], true)) {
            return true;
        }

        $scopedId = $this->employeeDataScope->scopedEmployeeId($user);

        return $scopedId !== null && (int) $schedule->employee_id === (int) $scopedId
            && (int) $schedule->created_by_user_id === (int) ($user?->id ?? 0);
    }

    public function canEditSchedule(?User $user, DemoSchedule $schedule): bool
    {
        return $this->canManageSchedule($user, $schedule);
    }

    private function canViewTeamMetrics(?User $user): bool
    {
        return in_array($this->rbacService->roleKey($user), ['super_admin', 'admin', 'manager'], true);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function scopedQuery(?User $user, array $filters = []): Builder
    {
        $query = DemoSchedule::query();

        if (! empty($filters['demo_provider_id'])) {
            $query->where('demo_provider_id', (int) $filters['demo_provider_id']);
        }
        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }
        if (! empty($filters['city_id'])) {
            $query->whereHas('lead', fn ($q) => $q->where('city_id', (int) $filters['city_id']));
        }
        if (! empty($filters['team_size'])) {
            $query->where('team_size', (int) $filters['team_size']);
        }

        $scopedId = $this->employeeDataScope->scopedEmployeeId($user);
        if ($scopedId !== null) {
            if ($scopedId <= 0) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where('employee_id', $scopedId);
        }

        $role = $this->rbacService->roleKey($user);
        if ($role === 'manager') {
            $employeeIds = Employee::query()
                ->where('status', 'Active')
                ->pluck('employee_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($employeeIds === []) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereIn('employee_id', $employeeIds);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEvent(DemoSchedule $schedule): array
    {
        $end = $schedule->demo_end_at ?? $schedule->demo_at?->copy()->addHour();

        return [
            'id' => $schedule->id,
            'ca_id' => $schedule->ca_id,
            'followup_id' => $schedule->followup_id,
            'demo_provider_id' => $schedule->demo_provider_id,
            'demo_provider_name' => $schedule->demo_provider_name ?: $schedule->provider?->name,
            'employee_id' => $schedule->employee_id,
            'employee_name' => $schedule->employee?->name,
            'firm_name' => $schedule->firm_name ?: $schedule->lead?->firm_name,
            'ca_name' => $schedule->customer_name ?: $schedule->lead?->ca_name,
            'team_size' => $schedule->team_size ?: $schedule->lead?->team_size,
            'status' => $schedule->status,
            'status_label' => ucfirst(str_replace('_', ' ', $schedule->status)),
            'meeting_link' => $schedule->meeting_link,
            'notes' => $schedule->notes,
            'demo_at' => $schedule->demo_at?->toIso8601String(),
            'demo_end_at' => $end?->toIso8601String(),
            'time_label' => $schedule->demo_at?->format('g:i A'),
            'date_label' => $schedule->demo_at?->format('d M Y'),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function employeeDemoCounts(?User $user, Carbon $date, array $filters): array
    {
        return $this->scopedQuery($user, $filters)
            ->selectRaw('employee_id, COUNT(*) as total')
            ->whereDate('demo_at', $date->toDateString())
            ->groupBy('employee_id')
            ->with('employee:employee_id,name')
            ->get()
            ->map(fn ($row) => [
                'employee_id' => (int) $row->employee_id,
                'employee_name' => $row->employee?->name,
                'total' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function peakHours(?User $user, Carbon $date, array $filters): array
    {
        return $this->scopedQuery($user, $filters)
            ->whereDate('demo_at', $date->toDateString())
            ->get()
            ->groupBy(fn (DemoSchedule $s) => $s->demo_at?->format('H') ?? '00')
            ->map(fn ($group, $hour) => ['hour' => (int) $hour, 'count' => $group->count()])
            ->sortByDesc('count')
            ->values()
            ->take(5)
            ->all();
    }
}
