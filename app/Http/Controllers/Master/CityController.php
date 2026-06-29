<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StoreCityRequest;
use App\Http\Requests\Master\UpdateCityRequest;
use App\Http\Resources\CityResource;
use App\Services\Master\CityService;
use App\Support\ApiResponse;
use App\Support\Listing\ListingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CityController extends Controller
{
    public function __construct(
        private readonly CityService $cityService,
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
        try {
            $this->cityService->delete($this->cityService->find($id));
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(null, 'City deleted successfully');
    }
}
