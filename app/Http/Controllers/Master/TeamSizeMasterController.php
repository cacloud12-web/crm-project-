<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Master\Concerns\HandlesMasterRecordLifecycle;
use App\Http\Requests\Master\StoreTeamSizeMasterRequest;
use App\Http\Requests\Master\UpdateTeamSizeMasterRequest;
use App\Http\Resources\TeamSizeMasterResource;
use App\Services\Master\MasterDependencyService;
use App\Services\Master\MasterRecordLifecycleService;
use App\Services\Master\TeamSizeMasterService;
use App\Support\ApiResponse;
use App\Support\Listing\ListingResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamSizeMasterController extends Controller
{
    use HandlesMasterRecordLifecycle;

    public function __construct(
        private readonly TeamSizeMasterService $teamSizeMasterService,
        private readonly MasterRecordLifecycleService $lifecycleService,
        private readonly MasterDependencyService $dependencyService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->teamSizeMasterService->search($request->query());

        return ListingResponse::from($result, TeamSizeMasterResource::class, 'Team sizes loaded');
    }

    public function store(StoreTeamSizeMasterRequest $request): JsonResponse
    {
        $teamSize = $this->teamSizeMasterService->create($request->validated());

        return ApiResponse::created(new TeamSizeMasterResource($teamSize), 'Team size range added successfully');
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(
            new TeamSizeMasterResource($this->teamSizeMasterService->find($id)),
            'Team size loaded',
        );
    }

    public function update(UpdateTeamSizeMasterRequest $request, string $id): JsonResponse
    {
        $teamSize = $this->teamSizeMasterService->update(
            $this->teamSizeMasterService->find($id),
            $request->validated(),
        );

        return ApiResponse::success(new TeamSizeMasterResource($teamSize), 'Team size updated successfully');
    }

    public function destroy(string $id): JsonResponse
    {
        return $this->destroyWithLifecycle($id);
    }

    protected function masterEntityKey(): string
    {
        return MasterDependencyService::ENTITY_TEAM;
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
        return $this->teamSizeMasterService->find($id);
    }

    protected function masterResource(Model $model): mixed
    {
        return new TeamSizeMasterResource($model);
    }

    protected function masterEntityLabel(): string
    {
        return 'Team size';
    }
}
