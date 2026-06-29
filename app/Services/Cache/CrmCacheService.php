<?php

namespace App\Services\Cache;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class CrmCacheService
{
    public function rememberMasterListing(string $listingKey, Closure $callback): mixed
    {
        $ttl = (int) config('crm_cache.master_ttl', 300);

        return Cache::remember(
            'crm:master:'.$listingKey,
            $ttl,
            fn () => self::normalizeForCache($callback()),
        );
    }

    public function rememberDashboardMetrics(string $scopeKey, Closure $callback): mixed
    {
        $ttl = (int) config('crm_cache.dashboard_ttl', 60);

        return Cache::remember(
            'crm:dashboard:metrics:'.$scopeKey,
            $ttl,
            fn () => self::normalizeForCache($callback()),
        );
    }

    public function rememberReportSummary(string $scopeKey, array $filterKey, Closure $callback): mixed
    {
        $ttl = (int) config('crm_cache.reports_summary_ttl', 60);

        return Cache::remember(
            'crm:reports:summary:'.$scopeKey.':'.md5(json_encode($filterKey)),
            $ttl,
            fn () => self::normalizeForCache($callback()),
        );
    }

    /**
     * Ensure cached values are plain arrays/scalars — never serialized Eloquent models.
     */
    public static function normalizeForCache(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return $value->toArray();
        }

        if ($value instanceof Collection) {
            return $value
                ->map(fn ($item) => self::normalizeForCache($item))
                ->values()
                ->all();
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => self::normalizeForCache($item), $value);
        }

        if (isset($value['items'])) {
            $value['items'] = self::normalizeForCache($value['items']);
        }

        return $value;
    }

    public function forgetMasterListings(): void
    {
        foreach (['states', 'cities', 'source_leads', 'team_sizes', 'role_masters', 'lookup_states'] as $key) {
            Cache::forget('crm:master:'.$key);
        }
    }

    public function forgetDashboardMetrics(?string $scopeKey = null): void
    {
        if ($scopeKey) {
            Cache::forget('crm:dashboard:metrics:'.$scopeKey);

            return;
        }

        Cache::forget('crm:dashboard:metrics:org');
    }

    /**
     * Bust admin org metrics and per-employee dashboard caches after assignment changes.
     *
     * @param  array<int>  $employeeIds
     */
    public function forgetDashboardMetricsAfterAssignment(array $employeeIds = []): void
    {
        $this->forgetDashboardMetrics('org');

        foreach (array_unique(array_filter(array_map('intval', $employeeIds))) as $employeeId) {
            $this->forgetDashboardMetrics('employee:'.$employeeId);
        }
    }
}
