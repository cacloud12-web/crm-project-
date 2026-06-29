<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StoreSourceLeadRequest;
use App\Http\Requests\Master\UpdateSourceLeadRequest;
use App\Http\Resources\SourceLeadResource;
use App\Services\Master\SourceLeadService;
use App\Support\ApiResponse;
use App\Support\Listing\ListingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SourceLeadController extends Controller
{
    public function __construct(
        private readonly SourceLeadService $sourceLeadService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->sourceLeadService->search($request->query());

        return ListingResponse::from($result, SourceLeadResource::class, 'Sources loaded');
    }

    public function store(StoreSourceLeadRequest $request): JsonResponse
    {
        $source = $this->sourceLeadService->create($request->validated());

        return ApiResponse::created(new SourceLeadResource($source), 'Lead source added successfully');
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(
            new SourceLeadResource($this->sourceLeadService->find($id)),
            'Lead source loaded',
        );
    }

    public function update(UpdateSourceLeadRequest $request, string $id): JsonResponse
    {
        $source = $this->sourceLeadService->update(
            $this->sourceLeadService->find($id),
            $request->validated(),
        );

        return ApiResponse::success(new SourceLeadResource($source), 'Lead source updated successfully');
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $this->sourceLeadService->delete($this->sourceLeadService->find($id));
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(null, 'Lead source deleted successfully');
    }
}
