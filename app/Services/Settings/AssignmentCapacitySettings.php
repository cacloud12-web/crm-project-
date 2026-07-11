<?php

namespace App\Services\Settings;

class AssignmentCapacitySettings
{
    public const DEFAULT_DAILY_MAX = 50;

    public function __construct(
        private readonly CrmSettingsService $crmSettingsService,
    ) {}

    public function dailyMaxCapacity(): int
    {
        $settings = $this->crmSettingsService->all();
        $raw = $settings['assignment']['daily_max_capacity'] ?? null;

        if ($raw === null || $raw === '') {
            return self::DEFAULT_DAILY_MAX;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : self::DEFAULT_DAILY_MAX;
    }

    public static function capacityTier(int $percentage): string
    {
        if ($percentage >= 91) {
            return 'red';
        }

        if ($percentage >= 71) {
            return 'yellow';
        }

        return 'green';
    }

    public static function capacityPercentage(int $assignedToday, int $maxCapacity): int
    {
        if ($maxCapacity <= 0) {
            return 0;
        }

        return min(100, (int) round(($assignedToday / $maxCapacity) * 100));
    }
}
