<?php

namespace App\Services\Demo;

use App\Models\DemoProvider;
use App\Models\DemoProviderLeave;
use App\Models\DemoSchedule;
use App\Support\Demo\DemoProviderResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class DemoAvailabilityService
{
    public function __construct(
        private readonly DemoSchedulingRulesService $schedulingRules,
    ) {}

    /** @var list<string> */
    private const ACTIVE_STATUSES = [
        DemoSchedule::STATUS_SCHEDULED,
        DemoSchedule::STATUS_RESCHEDULED,
    ];

    public function resolveProvider(?int $providerId, ?int $teamSize = null, ?string $providerName = null): ?DemoProvider
    {
        if ($providerId) {
            return DemoProvider::query()->where('is_active', true)->find($providerId);
        }

        if ($providerName) {
            $byName = DemoProvider::query()
                ->where('is_active', true)
                ->where('name', $providerName)
                ->first();
            if ($byName) {
                return $byName;
            }
        }

        $resolved = DemoProviderResolver::resolve($teamSize);

        if ($resolved && ! empty($resolved['demo_provider_id'])) {
            return DemoProvider::query()->where('is_active', true)->find($resolved['demo_provider_id']);
        }

        return null;
    }

    /**
     * @return array{available: bool, conflict: ?array<string, mixed>, suggestions: list<array<string, mixed>>, alternate_providers: list<array<string, mixed>>}
     */
    public function checkConflict(array $data, ?int $ignoreScheduleId = null, bool $includeAlternates = true): array
    {
        $provider = $this->resolveProvider(
            isset($data['demo_provider_id']) ? (int) $data['demo_provider_id'] : null,
            isset($data['team_size']) ? (int) $data['team_size'] : null,
            $data['demo_provider_name'] ?? null,
        );

        if (! $provider) {
            throw new InvalidArgumentException('Demo provider is required.');
        }

        [$start, $end] = $this->resolveWindow($data, $provider);
        $date = $start->toDateString();

        try {
            $this->schedulingRules->validate(
                $date,
                $start->format('H:i'),
                $end->format('H:i'),
            );
        } catch (InvalidArgumentException $e) {
            return [
                'available' => false,
                'conflict' => [
                    'message' => $e->getMessage(),
                    'type' => 'company_rules',
                ],
                'suggestions' => [],
                'alternate_providers' => [],
            ];
        }

        if ($this->isOnLeave($provider->id, $date)) {
            return [
                'available' => false,
                'conflict' => [
                    'message' => $provider->name.' is on leave on '.$start->format('d M Y').'.',
                    'type' => 'leave',
                ],
                'suggestions' => $this->nextAvailableSlots($provider, $start->copy()->addDay(), 5),
                'alternate_providers' => $includeAlternates ? $this->alternateProviders($provider->id, $data, $ignoreScheduleId) : [],
            ];
        }

        if (! $this->isWorkingDay($provider, $start)) {
            $message = $this->schedulingRules->isClosedWeekday($start)
                ? (config('crm_demo_calendar.messages.sunday') ?? 'Demos cannot be scheduled on Sundays.')
                : $provider->name.' is not available on '.$start->format('l').'.';

            return [
                'available' => false,
                'conflict' => [
                    'message' => $message,
                    'type' => 'non_working_day',
                ],
                'suggestions' => $this->nextAvailableSlots($provider, $start->copy()->addDay(), 5),
                'alternate_providers' => $includeAlternates ? $this->alternateProviders($provider->id, $data, $ignoreScheduleId) : [],
            ];
        }

        if ($this->overlapsBreak($provider, $start, $end)) {
            return [
                'available' => false,
                'conflict' => [
                    'message' => 'Selected time overlaps '.$provider->name.'\'s break.',
                    'type' => 'break',
                ],
                'suggestions' => $this->availableSlotsForDate($provider, $start->copy()->startOfDay(), $ignoreScheduleId),
                'alternate_providers' => $includeAlternates ? $this->alternateProviders($provider->id, $data, $ignoreScheduleId) : [],
            ];
        }

        if (! $this->withinWorkingHours($provider, $start, $end)) {
            return [
                'available' => false,
                'conflict' => [
                    'message' => 'Selected time is outside '.$provider->name.'\'s working hours.',
                    'type' => 'working_hours',
                ],
                'suggestions' => $this->availableSlotsForDate($provider, $start->copy()->startOfDay(), $ignoreScheduleId),
                'alternate_providers' => $includeAlternates ? $this->alternateProviders($provider->id, $data, $ignoreScheduleId) : [],
            ];
        }

        $bookedToday = $this->bookedCountForDate($provider->id, $date, $ignoreScheduleId);
        if ($bookedToday >= (int) $provider->max_demos_per_day) {
            return [
                'available' => false,
                'conflict' => [
                    'message' => $provider->name.' has reached the maximum demos for '.$start->format('d M Y').'.',
                    'type' => 'max_demos',
                ],
                'suggestions' => $this->nextAvailableSlots($provider, $start->copy()->addDay(), 5),
                'alternate_providers' => $includeAlternates ? $this->alternateProviders($provider->id, $data, $ignoreScheduleId) : [],
            ];
        }

        $overlap = $this->findOverlappingSchedule($provider->id, $start, $end, $ignoreScheduleId);
        if ($overlap) {
            return [
                'available' => false,
                'conflict' => [
                    'message' => $provider->name.' already has a demo at '.$overlap->demo_at->format('g:i A').'.',
                    'type' => 'booking',
                    'schedule_id' => $overlap->id,
                    'firm_name' => $overlap->firm_name,
                ],
                'suggestions' => $this->availableSlotsForDate($provider, $start->copy()->startOfDay(), $ignoreScheduleId, 5),
                'alternate_providers' => $includeAlternates ? $this->alternateProviders($provider->id, $data, $ignoreScheduleId) : [],
            ];
        }

        return [
            'available' => true,
            'conflict' => null,
            'suggestions' => [],
            'alternate_providers' => [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function slotsForProviderDate(DemoProvider $provider, Carbon $date, ?int $ignoreScheduleId = null): array
    {
        $dayStart = $date->copy()->startOfDay();
        $slots = $this->buildDaySlots($provider, $dayStart);
        $bookings = $this->bookingsForDate($provider->id, $dayStart->toDateString(), $ignoreScheduleId)
            ->keyBy('id');

        $onLeave = $this->isOnLeave($provider->id, $dayStart->toDateString());
        $workingDay = $this->isWorkingDay($provider, $dayStart);

        return array_map(function (array $slot) use ($bookings, $onLeave, $workingDay, $provider) {
            if ($onLeave) {
                return array_merge($slot, ['status' => 'leave', 'status_label' => 'On Leave']);
            }
            if (! $workingDay) {
                return array_merge($slot, ['status' => 'unavailable', 'status_label' => 'Unavailable']);
            }
            if ($slot['status'] === 'break') {
                return array_merge($slot, ['status_label' => 'Break']);
            }

            foreach ($bookings as $booking) {
                if ($this->timesOverlap(
                    Carbon::parse($slot['start_at']),
                    Carbon::parse($slot['end_at']),
                    $booking->demo_at,
                    $booking->demo_end_at ?? $booking->demo_at->copy()->addMinutes($provider->slot_duration_minutes),
                )) {
                    return array_merge($slot, [
                        'status' => $this->mapBookingStatus($booking->status),
                        'status_label' => ucfirst(str_replace('_', ' ', $booking->status)),
                        'schedule_id' => $booking->id,
                        'firm_name' => $booking->firm_name,
                        'ca_name' => $booking->customer_name,
                        'employee_name' => $booking->employee?->name,
                        'meeting_link' => $booking->meeting_link,
                        'notes' => $booking->notes,
                        'team_size' => $booking->team_size,
                    ]);
                }
            }

            return array_merge($slot, ['status' => 'available', 'status_label' => 'Available']);
        }, $slots);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availableSlotsForDate(DemoProvider $provider, Carbon $date, ?int $ignoreScheduleId = null, int $limit = 10): array
    {
        return collect($this->slotsForProviderDate($provider, $date, $ignoreScheduleId))
            ->filter(fn (array $slot) => ($slot['status'] ?? '') === 'available')
            ->take($limit)
            ->values()
            ->map(fn (array $slot) => [
                'start_at' => $slot['start_at'],
                'end_at' => $slot['end_at'],
                'label' => Carbon::parse($slot['start_at'])->format('g:i A'),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nextAvailableSlots(DemoProvider $provider, Carbon $fromDate, int $limit): array
    {
        $results = [];
        $cursor = $fromDate->copy()->startOfDay();

        for ($day = 0; $day < 14 && count($results) < $limit; $day++) {
            foreach ($this->availableSlotsForDate($provider, $cursor, null, $limit - count($results)) as $slot) {
                $results[] = $slot;
                if (count($results) >= $limit) {
                    break;
                }
            }
            $cursor->addDay();
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function alternateProviders(int $excludeProviderId, array $data, ?int $ignoreScheduleId): array
    {
        $alternates = [];
        $providers = DemoProvider::query()->where('is_active', true)->where('id', '!=', $excludeProviderId)->orderBy('sort_order')->get();

        foreach ($providers as $provider) {
            $payload = array_merge($data, ['demo_provider_id' => $provider->id]);
            $check = $this->checkConflict($payload, $ignoreScheduleId, false);
            if ($check['available']) {
                $alternates[] = [
                    'demo_provider_id' => $provider->id,
                    'name' => $provider->name,
                    'meeting_link' => $provider->default_meeting_link,
                ];
            }
        }

        return $alternates;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function resolveWindow(array $data, DemoProvider $provider): array
    {
        if (! empty($data['demo_at'])) {
            $start = Carbon::parse($data['demo_at']);
        } elseif (! empty($data['demo_date']) && ! empty($data['start_time'])) {
            $start = Carbon::parse($data['demo_date'].' '.$data['start_time']);
        } else {
            throw new InvalidArgumentException('Demo date and time are required.');
        }

        if (! empty($data['demo_end_at'])) {
            $end = Carbon::parse($data['demo_end_at']);
        } elseif (! empty($data['end_time'])) {
            $end = Carbon::parse(($data['demo_date'] ?? $start->toDateString()).' '.$data['end_time']);
        } else {
            $duration = (int) ($data['slot_duration_minutes'] ?? $provider->slot_duration_minutes ?? 60);
            $end = $start->copy()->addMinutes($duration);
        }

        return [$start, $end];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildDaySlots(DemoProvider $provider, Carbon $date): array
    {
        $slots = [];
        $start = $this->combineDateTime($date, (string) $provider->work_start_time);
        $end = $this->combineDateTime($date, (string) $provider->work_end_time);
        $step = max(15, (int) $provider->slot_duration_minutes + (int) $provider->buffer_minutes);
        $cursor = $start->copy();

        while ($cursor->copy()->addMinutes((int) $provider->slot_duration_minutes)->lte($end)) {
            $slotEnd = $cursor->copy()->addMinutes((int) $provider->slot_duration_minutes);
            $isBreak = $this->overlapsBreak($provider, $cursor, $slotEnd);
            $slots[] = [
                'start_at' => $cursor->toIso8601String(),
                'end_at' => $slotEnd->toIso8601String(),
                'label' => $cursor->format('g:i A').' – '.$slotEnd->format('g:i A'),
                'status' => $isBreak ? 'break' : 'open',
            ];
            $cursor->addMinutes($step);
        }

        return $slots;
    }

    private function combineDateTime(Carbon $date, string $time): Carbon
    {
        $time = strlen($time) === 5 ? $time.':00' : $time;

        return Carbon::parse($date->toDateString().' '.$time);
    }

    private function isOnLeave(int $providerId, string $date): bool
    {
        return DemoProviderLeave::query()
            ->where('demo_provider_id', $providerId)
            ->whereDate('leave_date', $date)
            ->exists();
    }

    public function isProviderOnLeave(int $providerId, string $date): bool
    {
        return $this->isOnLeave($providerId, $date);
    }

    private function isWorkingDay(DemoProvider $provider, Carbon $date): bool
    {
        $days = $provider->working_days ?: [1, 2, 3, 4, 5, 6];
        $iso = $date->dayOfWeekIso;

        return in_array($iso, array_map('intval', $days), true);
    }

    private function withinWorkingHours(DemoProvider $provider, Carbon $start, Carbon $end): bool
    {
        $workStart = $this->combineDateTime($start, (string) $provider->work_start_time);
        $workEnd = $this->combineDateTime($start, (string) $provider->work_end_time);

        return $start->gte($workStart) && $end->lte($workEnd);
    }

    private function overlapsBreak(DemoProvider $provider, Carbon $start, Carbon $end): bool
    {
        if (! $provider->break_start_time || ! $provider->break_end_time) {
            return false;
        }

        $breakStart = $this->combineDateTime($start, (string) $provider->break_start_time);
        $breakEnd = $this->combineDateTime($start, (string) $provider->break_end_time);

        return $this->timesOverlap($start, $end, $breakStart, $breakEnd);
    }

    private function timesOverlap(Carbon $aStart, Carbon $aEnd, Carbon $bStart, Carbon $bEnd): bool
    {
        return $aStart->lt($bEnd) && $aEnd->gt($bStart);
    }

    private function findOverlappingSchedule(int $providerId, Carbon $start, Carbon $end, ?int $ignoreScheduleId): ?DemoSchedule
    {
        return DemoSchedule::query()
            ->with('employee:employee_id,name')
            ->where('demo_provider_id', $providerId)
            ->when($ignoreScheduleId, fn ($q) => $q->where('id', '!=', $ignoreScheduleId))
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where(function ($q) use ($start, $end) {
                $q->where('demo_at', '<', $end)
                    ->where(function ($inner) use ($start) {
                        $inner->where('demo_end_at', '>', $start)
                            ->orWhereNull('demo_end_at');
                    });
            })
            ->orderBy('demo_at')
            ->first();
    }

    private function bookedCountForDate(int $providerId, string $date, ?int $ignoreScheduleId): int
    {
        return DemoSchedule::query()
            ->where('demo_provider_id', $providerId)
            ->when($ignoreScheduleId, fn ($q) => $q->where('id', '!=', $ignoreScheduleId))
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereDate('demo_at', $date)
            ->count();
    }

    /**
     * @return Collection<int, DemoSchedule>
     */
    private function bookingsForDate(int $providerId, string $date, ?int $ignoreScheduleId): Collection
    {
        return DemoSchedule::query()
            ->with('employee:employee_id,name')
            ->where('demo_provider_id', $providerId)
            ->when($ignoreScheduleId, fn ($q) => $q->where('id', '!=', $ignoreScheduleId))
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereDate('demo_at', $date)
            ->orderBy('demo_at')
            ->get();
    }

    private function mapBookingStatus(string $status): string
    {
        return match ($status) {
            DemoSchedule::STATUS_COMPLETED => 'completed',
            DemoSchedule::STATUS_CANCELLED => 'cancelled',
            DemoSchedule::STATUS_MISSED => 'missed',
            DemoSchedule::STATUS_RESCHEDULED => 'rescheduled',
            default => 'scheduled',
        };
    }
}
