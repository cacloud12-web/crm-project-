<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use InvalidArgumentException;

class DashboardDateRange
{
    public const PRESETS = [
        'today',
        'yesterday',
        'last_7_days',
        'last_15_days',
        'last_30_days',
        'this_week',
        'last_week',
        'this_month',
        'last_month',
        'last_quarter',
        'last_half_year',
        'this_year',
        'last_year',
        'custom',
    ];

    public const LABELS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'last_7_days' => 'Last 7 Days',
        'last_15_days' => 'Last 15 Days',
        'last_30_days' => 'Last 30 Days',
        'this_week' => 'This Week',
        'last_week' => 'Last Week',
        'this_month' => 'This Month',
        'last_month' => 'Last Month',
        'last_quarter' => 'Last Quarter',
        'last_half_year' => 'Last Half Year',
        'this_year' => 'This Year',
        'last_year' => 'Last Year',
        'custom' => 'Custom Range',
    ];

    /**
     * @return array{preset: string, from: Carbon, to: Carbon, label: string}
     */
    public static function resolve(?string $preset = null, ?string $from = null, ?string $to = null): array
    {
        $preset = $preset ?: 'today';
        if (! in_array($preset, self::PRESETS, true)) {
            throw new InvalidArgumentException('Invalid date range preset.');
        }

        $now = now();

        if ($preset === 'custom') {
            if (! $from || ! $to) {
                throw new InvalidArgumentException('Custom range requires from and to dates.');
            }
            $start = Carbon::parse($from)->startOfDay();
            $end = Carbon::parse($to)->endOfDay();
            if ($start->greaterThan($end)) {
                throw new InvalidArgumentException('From date must be before To date.');
            }

            return [
                'preset' => 'custom',
                'from' => $start,
                'to' => $end,
                'label' => $start->format('d M Y').' – '.$end->format('d M Y'),
            ];
        }

        [$start, $end] = match ($preset) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'last_7_days' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            'last_15_days' => [$now->copy()->subDays(14)->startOfDay(), $now->copy()->endOfDay()],
            'last_30_days' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            'this_week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'last_week' => [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek()],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'last_quarter' => [
                $now->copy()->subQuarterNoOverflow()->startOfQuarter(),
                $now->copy()->subQuarterNoOverflow()->endOfQuarter(),
            ],
            'last_half_year' => [
                $now->copy()->subMonthsNoOverflow(6)->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            'this_year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            'last_year' => [$now->copy()->subYear()->startOfYear(), $now->copy()->subYear()->endOfYear()],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };

        return [
            'preset' => $preset,
            'from' => $start,
            'to' => $end,
            'label' => self::LABELS[$preset] ?? $preset,
        ];
    }
}
