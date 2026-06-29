<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StoreRoleMasterRequest;
use App\Http\Requests\Master\UpdateRoleMasterRequest;
use App\Http\Resources\RoleMasterResource;
use App\Services\Master\RoleMasterService;
use App\Support\ApiResponse;
use App\Support\Listing\ListingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleMasterController extends Controller
{
    public function __construct(
        private readonly RoleMasterService $roleMasterService,
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
        try {
            $this->roleMasterService->delete($this->roleMasterService->find($id));
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(null, 'Role deleted successfully');
    }
}
