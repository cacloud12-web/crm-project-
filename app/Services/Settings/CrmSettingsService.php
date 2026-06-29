<?php

namespace App\Services\Settings;

use App\Models\CrmSetting;
use App\Services\Activity\ActivityLogService;
use Illuminate\Support\Facades\Cache;

class CrmSettingsService
{
    private const CACHE_KEY = 'crm:settings:all';

    public function __construct(
        private readonly ActivityLogService $activityLogService,
    ) {}

    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, function () {
            return CrmSetting::query()
                ->orderBy('group')
                ->orderBy('key')
                ->get()
                ->groupBy('group')
                ->map(fn ($items) => $items->pluck('value', 'key')->all())
                ->all();
        });
    }

    public function save(array $payload, string $performedBy): array
    {
        foreach ($payload as $group => $items) {
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $key => $value) {
                CrmSetting::query()->updateOrCreate(
                    ['group' => (string) $group, 'key' => (string) $key],
                    ['value' => is_scalar($value) || $value === null ? (string) $value : json_encode($value)],
                );
            }
        }

        Cache::forget(self::CACHE_KEY);

        $this->activityLogService->log(
            'SETTINGS',
            'Save Settings',
            'crm_settings',
            'CRM settings updated',
            $performedBy,
        );

        return $this->all();
    }
}
