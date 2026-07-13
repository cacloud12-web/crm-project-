<?php

namespace App\Services\Demo;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

class DemoSchedulingRulesService
{
    public function startTime(): string
    {
        return (string) config('crm_demo_calendar.start_time', '10:00');
    }

    public function endTime(): string
    {
        return (string) config('crm_demo_calendar.end_time', '19:00');
    }

    public function slotMinutes(): int
    {
        return (int) config('crm_demo_calendar.slot_minutes', 30);
    }

    /**
     * @return list<int>
     */
    public function closedWeekdays(): array
    {
        return array_map('intval', config('crm_demo_calendar.closed_weekdays', [0]));
    }

    public function isClosedWeekday(Carbon|string $date): bool
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        return in_array((int) $date->dayOfWeek, $this->closedWeekdays(), true);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function validate(string $date, string $startTime, string $endTime): void
    {
        $messages = config('crm_demo_calendar.messages', []);
        $day = Carbon::parse($date)->startOfDay();

        if ($this->isClosedWeekday($day)) {
            throw new InvalidArgumentException($messages['sunday'] ?? 'Demos cannot be scheduled on Sundays.');
        }

        $start = $this->combine($day, $startTime);
        $end = $this->combine($day, $endTime);
        $workStart = $this->combine($day, $this->startTime());
        $workEnd = $this->combine($day, $this->endTime());

        if ($start->lt($workStart)) {
            throw new InvalidArgumentException($messages['start_time'] ?? 'Demo start time must be 10:00 AM or later.');
        }

        if ($end->gt($workEnd)) {
            throw new InvalidArgumentException($messages['end_time'] ?? 'Demo end time must be 7:00 PM or earlier.');
        }

        if (! $end->gt($start)) {
            throw new InvalidArgumentException($messages['end_after_start'] ?? 'Demo end time must be after the start time.');
        }
    }

    /**
     * @return array{valid: bool, message: ?string}
     */
    public function check(string $date, string $startTime, string $endTime): array
    {
        try {
            $this->validate($date, $startTime, $endTime);

            return ['valid' => true, 'message' => null];
        } catch (InvalidArgumentException $e) {
            return ['valid' => false, 'message' => $e->getMessage()];
        }
    }

    private function combine(Carbon $date, string $time): Carbon
    {
        $time = strlen($time) === 5 ? $time.':00' : $time;

        return Carbon::parse($date->toDateString().' '.$time);
    }
}
