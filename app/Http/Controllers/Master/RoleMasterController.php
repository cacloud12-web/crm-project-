<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Master\Concerns\HandlesMasterRecordLifecycle;
use App\Http\Requests\Master\StoreRoleMasterRequest;
use App\Http\Requests\Master\UpdateRoleMasterRequest;
use App\Http\Resources\RoleMasterResource;
use App\Services\Master\MasterDependencyService;
use App\Services\Master\MasterRecordLifecycleService;
use App\Services\Master\RoleMasterService;
use App\Support\ApiResponse;
use App\Support\Listing\ListingResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleMasterController extends Controller
{
    use HandlesMasterRecordLifecycle;

    public function __construct(
        private readonly RoleMasterService $roleMasterService,
        private readonly MasterRecordLifecycleService $lifecycleService,
        private readonly MasterDependencyService $dependencyService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->roleMasterService->search($request->query());

        return ListingResponse::from($result, RoleMasterResource::class, 'Roles loaded');
    }

    public function store(StoreRoleMasterRequest $request): JsonResponse
    {
        $role = $this->roleMasterService->create($request->validated());

        return ApiResponse::created(new RoleMasterResource($role), 'Role added successfully');
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(
            new RoleMasterResource($this->roleMasterService->find($id)),
            'Role loaded',
        );
    }

    public function update(UpdateRoleMasterRequest $request, string $id): JsonResponse
    {
        $role = $this->roleMasterService->update(
            $this->roleMasterService->find($id),
            $request->validated(),
        );

        return ApiResponse::success(new RoleMasterResource($role), 'Role updated successfully');
    }

    public function destroy(string $id): JsonResponse
    {
        return $this->destroyWithLifecycle($id);
    }

    protected function masterEntityKey(): string
    {
        return MasterDependencyService::ENTITY_ROLE;
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
        return $this->roleMasterService->find($id);
    }

    protected function masterResource(Model $model): mixed
    {
        return new RoleMasterResource($model);
    }

    protected function masterEntityLabel(): string
    {
        return 'Role';
    }
}
