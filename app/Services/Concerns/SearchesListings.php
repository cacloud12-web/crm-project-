<?php

namespace App\Services\Concerns;

use App\Services\Cache\CrmCacheService;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Support\Listing\ListingQueryApplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

trait SearchesListings
{
    protected function searchListing(Builder $query, array $params, string $configKey): array
    {
        $config = ListingQueryApplier::config($configKey);
        $scope = app(EmployeeDataScopeService::class);
        $params = $scope->stripScopedParams($params, $config);
        $scope->applyToListing($query, $config);

        $all = filter_var($params['all'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($all && ($config['cacheable'] ?? false)) {
            return app(CrmCacheService::class)->rememberMasterListing(
                $configKey,
                fn () => ListingQueryApplier::apply($query, $params, $config),
            );
        }

        return ListingQueryApplier::apply($query, $params, $config);
    }

    protected function listAllFromSearch(Builder $query, array $params, string $configKey): Collection
    {
        return collect($this->searchListing($query, array_merge($params, ['all' => true]), $configKey)['items']);
    }
}
