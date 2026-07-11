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

    public function rememberDashboardMetrics(string $scopeKey, array $filterKey, Closure $callback): mixed
    {
        $ttl = (int) config('crm_cache.dashboard_ttl', 120);
        $version = $this->dashboardCacheVersion();
        $hash = md5(json_encode($filterKey));

        return Cache::remember(
            'crm:dashboard:metrics:v'.$version.':'.$scopeKey.':'.$hash,
            $ttl,
            fn () => self::normalizeForCache($callback()),
        );
    }

    public function dashboardCacheVersion(): int
    {
        return (int) Cache::get('crm:dashboard:version', 1);
    }

    public function bumpDashboardCacheVersion(): void
    {
        if (! Cache::has('crm:dashboard:version')) {
            Cache::forever('crm:dashboard:version', 2);

            return;
        }

        Cache::increment('crm:dashboard:version');
    }

    public function rememberDashboardInsights(string $scopeKey, array $filterKey, Closure $callback): mixed
    {
        $ttl = (int) config('crm_cache.dashboard_insights_ttl', 120);
        $version = $this->dashboardCacheVersion();
        $hash = md5(json_encode($filterKey));

        return Cache::remember(
            'crm:dashboard:insights:v'.$version.':'.$scopeKey.':'.$hash,
            $ttl,
            fn () => self::normalizeForCache($callback()),
        );
    }

    public function rememberSalesFilterOptions(Closure $callback): mixed
    {
        $ttl = (int) config('crm_cache.sales_options_ttl', 300);
        $version = $this->dashboardCacheVersion();

        return Cache::remember(
            'crm:sales:filter_options:v'.$version,
            $ttl,
            fn () => self::normalizeForCache($callback()),
        );
    }

    public function rememberReportSummary(string $scopeKey, array $filterKey, Closure $callback): mixed
    {
        $ttl = (int) config('crm_cache.reports_summary_ttl', 60);
        $version = $this->reportCacheVersion();

        return Cache::remember(
            'crm:reports:summary:v'.$version.':'.$scopeKey.':'.md5(json_encode($filterKey)),
            $ttl,
            fn () => self::normalizeForCache($callback()),
        );
    }

    public function reportCacheVersion(): int
    {
        return (int) Cache::get('crm:reports:version', 1);
    }

    public function bumpReportCacheVersion(): void
    {
        if (! Cache::has('crm:reports:version')) {
            Cache::forever('crm:reports:version', 2);

            return;
        }

        Cache::increment('crm:reports:version');
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
        foreach (['states', 'cities', 'source_leads', 'team_sizes', 'role_masters', 'lookup_states', 'lookup_sources'] as $key) {
            Cache::forget('crm:master:'.$key);
        }
    }

    public function rememberActivityFeed(string $scopeKey, Closure $callback): mixed
    {
        $ttl = (int) config('crm_cache.dashboard_ttl', 60);

        return Cache::remember(
            'crm:activity:feed:'.$scopeKey,
            $ttl,
            fn () => self::normalizeForCache($callback()),
        );
    }

    public function forgetActivityFeed(?string $scopeKey = null): void
    {
        if ($scopeKey) {
            Cache::forget('crm:activity:feed:'.$scopeKey);

            return;
        }

        Cache::forget('crm:activity:feed:org');
    }

    public function forgetDashboardMetrics(?string $scopeKey = null): void
    {
        $this->bumpReportCacheVersion();
        $this->bumpDashboardCacheVersion();
    }

    /**
     * Bust admin org metrics and per-employee dashboard caches after assignment changes.
     *
     * @param  array<int>  $employeeIds
     */
    public function forgetDashboardMetricsAfterAssignment(array $employeeIds = []): void
    {
        $this->forgetDashboardMetrics('org');
        $this->forgetLeadSegmentCounts();
        $this->forgetPipelineStageCounts();
        $this->forgetEmployeeRankings();
        $this->forgetAssignmentWidgets();

        foreach (array_unique(array_filter(array_map('intval', $employeeIds))) as $employeeId) {
            $this->forgetDashboardMetrics('employee:'.$employeeId);
            $this->forgetEmployeeDashboard($employeeId);
        }
    }

    public function rememberAssignmentCapacity(string $scopeKey, Closure $callback): mixed
    {
        $ttl = (int) config('crm_cache.dashboard_ttl', 60);
        $version = $this->dashboardCacheVersion();

        return Cache::remember(
            'crm:assignment:capacity:v'.$version.':'.$scopeKey,
            $ttl,
            fn () => self::normalizeForCache($callback()),
        );
    }

    public function rememberAssignmentHeatMap(string $cacheKey, Closure $callback): mixed
    {
        $ttl = (int) config('crm_cache.dashboard_ttl', 60);
        $version = $this->dashboardCacheVersion();

        return Cache::remember(
            'crm:assignment:heatmap:v'.$version.':'.$cacheKey,
            $ttl,
            fn () => self::normalizeForCache($callback()),
        );
    }

    public function forgetAssignmentWidgets(): void
    {
        $this->bumpDashboardCacheVersion();
    }

    public function rememberDailyEmployeeTargets(string $scopeKey, Closure $callback): mixed
    {
        $ttl = (int) config('crm_cache.dashboard_ttl', 60);
        $version = $this->dashboardCacheVersion();

        return Cache::remember(
            'crm:assignment:daily-targets:v'.$version.':'.$scopeKey,
            $ttl,
            fn () => self::normalizeForCache($callback()),
        );
    }

    public function forgetDailyEmployeeTargets(?int $employeeId = null): void
    {
        $this->forgetAssignmentWidgets();

        if ($employeeId) {
            $this->forgetEmployeeDashboard($employeeId);
        }
    }

    public function rememberYearlyEmployeeTargets(string $scopeKey, Closure $callback): mixed
    {
        $ttl = (int) config('crm_cache.dashboard_ttl', 60);
        $version = $this->dashboardCacheVersion();

        return Cache::remember(
            'crm:assignment:yearly-targets:v'.$version.':'.$scopeKey,
            $ttl,
            fn () => self::normalizeForCache($callback()),
        );
    }

    public function forgetYearlyEmployeeTargets(?int $employeeId = null): void
    {
        $this->forgetAssignmentWidgets();

        if ($employeeId) {
            $this->forgetEmployeeDashboard($employeeId);
        }
    }

    public function rememberLeadSegmentCounts(string $scopeKey, Closure $callback): mixed
    {
        $ttl = (int) config('crm_cache.dashboard_ttl', 60);

        return Cache::remember(
            'crm:leads:segment_counts:'.$scopeKey,
            $ttl,
            fn () => self::normalizeForCache($callback()),
        );
    }

    public function forgetLeadSegmentCounts(?string $scopeKey = null): void
    {
        if ($scopeKey) {
            Cache::forget('crm:leads:segment_counts:'.$scopeKey);

            return;
        }

        // Scoped keys are per-user; bust org + common employee scopes via tag-less sweep of known prefixes.
        Cache::forget('crm:leads:segment_counts:org');
    }

    public function rememberPipelineStageCounts(string $scopeKey, Closure $callback): mixed
    {
        $ttl = (int) config('crm_cache.dashboard_ttl', 60);

        return Cache::remember(
            'crm:pipeline:stage_counts:'.$scopeKey,
            $ttl,
            fn () => self::normalizeForCache($callback()),
        );
    }

    public function forgetPipelineStageCounts(?string $scopeKey = null): void
    {
        if ($scopeKey) {
            Cache::forget('crm:pipeline:stage_counts:'.$scopeKey);

            return;
        }

        Cache::forget('crm:pipeline:stage_counts:org');
    }

    public function rememberEmployeeRankings(string $dateString, Closure $callback): mixed
    {
        $ttl = (int) config('crm_cache.dashboard_ttl', 60);

        return Cache::remember(
            'crm:productivity:rankings:'.$dateString,
            $ttl,
            fn () => self::normalizeForCache($callback()),
        );
    }

    public function forgetEmployeeRankings(?string $dateString = null): void
    {
        if ($dateString) {
            Cache::forget('crm:productivity:rankings:'.$dateString);

            return;
        }

        Cache::forget('crm:productivity:rankings:'.now()->toDateString());
    }

    public function rememberEmployeeDashboard(int $employeeId, Closure $callback): mixed
    {
        $ttl = (int) config('crm_cache.dashboard_ttl', 60);

        return Cache::remember(
            'crm:dashboard:employee:'.$employeeId,
            $ttl,
            fn () => self::normalizeForCache($callback()),
        );
    }

    public function forgetEmployeeDashboard(?int $employeeId = null): void
    {
        if ($employeeId) {
            Cache::forget('crm:dashboard:employee:'.$employeeId);
            $this->forgetDashboardMetrics('employee:'.$employeeId);

            return;
        }
    }
}
