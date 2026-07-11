<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Master\Concerns\HandlesMasterRecordLifecycle;
use App\Http\Requests\Master\StoreCityRequest;
use App\Http\Requests\Master\UpdateCityRequest;
use App\Http\Resources\CityResource;
use App\Services\Master\CityService;
use App\Services\Master\MasterDependencyService;
use App\Services\Master\MasterRecordLifecycleService;
use App\Support\ApiResponse;
use App\Support\Listing\ListingResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CityController extends Controller
{
    use HandlesMasterRecordLifecycle;

    public function __construct(
        private readonly CityService $cityService,
        private readonly MasterRecordLifecycleService $lifecycleService,
        private readonly MasterDependencyService $dependencyService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->cityService->search($request->query());

        return ListingResponse::from($result, CityResource::class, 'Cities loaded');
    }

    public function store(StoreCityRequest $request): JsonResponse
    {
        $city = $this->cityService->create($request->validated());

        return ApiResponse::created(new CityResource($city), 'City added successfully');
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(
            new CityResource($this->cityService->find($id)),
            'City loaded',
        );
    }

    public function update(UpdateCityRequest $request, string $id): JsonResponse
    {
        $city = $this->cityService->update(
            $this->cityService->find($id),
            $request->validated(),
        );

        return ApiResponse::success(new CityResource($city), 'City updated successfully');
    }

    public function destroy(string $id): JsonResponse
    {
        return $this->destroyWithLifecycle($id);
    }

    protected function masterEntityKey(): string
    {
        return MasterDependencyService::ENTITY_CITY;
    }

    protected function masterLifecycleService(): MasterRecordLifecycleService
    {
        return $this->lifecycleService;
    }

    protected function masterDependencyService(): MasterDependencyService
    {
        return $this->dependencyService;
    }

    protected function masterFind(int|string $id): Model
    {
        return $this->cityService->find($id);
    }

    protected function masterResource(Model $model): mixed
    {
        return new CityResource($model);
    }

    protected function masterEntityLabel(): string
    {
        return 'City';
    }
}
