<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Master\Concerns\HandlesMasterRecordLifecycle;
use App\Http\Requests\Master\StoreStateRequest;
use App\Http\Requests\Master\UpdateStateRequest;
use App\Http\Resources\StateResource;
use App\Services\Master\MasterDependencyService;
use App\Services\Master\MasterRecordLifecycleService;
use App\Services\Master\StateService;
use App\Support\ApiResponse;
use App\Support\Listing\ListingResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StateController extends Controller
{
    use HandlesMasterRecordLifecycle;

    public function __construct(
        private readonly StateService $stateService,
        private readonly MasterRecordLifecycleService $lifecycleService,
        private readonly MasterDependencyService $dependencyService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->stateService->search($request->query());

        return ListingResponse::from($result, StateResource::class, 'States loaded');
    }

    public function store(StoreStateRequest $request): JsonResponse
    {
        $state = $this->stateService->create($request->validated());

        return ApiResponse::created(new StateResource($state), 'State added successfully');
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(
            new StateResource($this->stateService->find($id)),
            'State loaded',
        );
    }

    public function update(UpdateStateRequest $request, string $id): JsonResponse
    {
        $state = $this->stateService->update(
            $this->stateService->find($id),
            $request->validated(),
        );

        return ApiResponse::success(new StateResource($state), 'State updated successfully');
    }

    public function destroy(string $id): JsonResponse
    {
        return $this->destroyWithLifecycle($id);
    }

    protected function masterEntityKey(): string
    {
        return MasterDependencyService::ENTITY_STATE;
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
        return $this->stateService->find($id);
    }

    protected function masterResource(Model $model): mixed
    {
        return new StateResource($model);
    }

    protected function masterEntityLabel(): string
    {
        return 'State';
    }
}
