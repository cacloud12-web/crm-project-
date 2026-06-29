<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StoreStateRequest;
use App\Http\Requests\Master\UpdateStateRequest;
use App\Http\Resources\StateResource;
use App\Services\Master\StateService;
use App\Support\ApiResponse;
use App\Support\Listing\ListingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StateController extends Controller
{
    public function __construct(
        private readonly StateService $stateService,
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
        try {
            $this->stateService->delete($this->stateService->find($id));
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(null, 'State deleted successfully');
    }
}
