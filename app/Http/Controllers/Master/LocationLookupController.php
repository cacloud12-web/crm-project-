<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\SourceLead;
use App\Models\State;
use App\Services\Cache\CrmCacheService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lightweight state/city/source master data for forms and filters across the CRM.
 * Returns a flat array in data — not the paginated listing envelope.
 * Available to any authenticated role that can view the dashboard (lead forms included).
 */
class LocationLookupController extends Controller
{
    public function __construct(
        private readonly CrmCacheService $cacheService,
    ) {}

    public function states(): JsonResponse
    {
        $states = $this->cacheService->rememberMasterListing('lookup_states', function () {
            return State::query()
                ->active()
                ->orderBy('state_name')
                ->get(['state_id', 'state_name'])
                ->map(fn (State $state) => [
                    'state_id' => $state->state_id,
                    'state_name' => $state->state_name,
                ])
                ->all();
        });

        return ApiResponse::success($states, 'States loaded');
    }

    public function cities(Request $request): JsonResponse
    {
        $stateId = (int) $request->query('state_id');

        if ($stateId <= 0) {
            return ApiResponse::success([], 'Cities loaded');
        }

        $cities = $this->cacheService->rememberMasterListing('lookup_cities:'.$stateId, function () use ($stateId) {
            return City::query()
                ->active()
                ->where('state_id', $stateId)
                ->orderBy('city_name')
                ->get(['city_id', 'city_name', 'state_id'])
                ->map(fn (City $city) => [
                    'city_id' => $city->city_id,
                    'city_name' => $city->city_name,
                    'state_id' => $city->state_id,
                ])
                ->all();
        });

        return ApiResponse::success($cities, 'Cities loaded');
    }

    public function sources(): JsonResponse
    {
        $sources = $this->cacheService->rememberMasterListing('lookup_sources', function () {
            return SourceLead::query()
                ->orderBy('source_name')
                ->get(['source_id', 'source_name'])
                ->map(fn (SourceLead $source) => [
                    'source_id' => $source->source_id,
                    'source_name' => $source->source_name,
                ])
                ->all();
        });

        return ApiResponse::success($sources, 'Sources loaded');
    }
}
