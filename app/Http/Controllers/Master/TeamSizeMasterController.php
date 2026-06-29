<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StoreTeamSizeMasterRequest;
use App\Http\Requests\Master\UpdateTeamSizeMasterRequest;
use App\Http\Resources\TeamSizeMasterResource;
use App\Services\Master\TeamSizeMasterService;
use App\Support\ApiResponse;
use App\Support\Listing\ListingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamSizeMasterController extends Controller
{
    public function __construct(
        private readonly TeamSizeMasterService $teamSizeMasterService,
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
        try {
            $this->teamSizeMasterService->delete($this->teamSizeMasterService->find($id));
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(null, 'Team size deleted successfully');
    }
}
