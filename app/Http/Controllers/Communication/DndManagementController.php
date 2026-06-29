<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dnd\StoreDndManagementRequest;
use App\Http\Resources\DndManagementResource;
use App\Services\Dnd\DndManagementService;
use App\Support\ApiResponse;
use App\Support\Listing\ListingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DndManagementController extends Controller
{
    public function __construct(
        private readonly DndManagementService $dndManagementService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->dndManagementService->search($request->query());

        return ListingResponse::from($result, DndManagementResource::class, 'DND records loaded');
    }

    public function store(StoreDndManagementRequest $request): JsonResponse
    {
        $entry = $this->dndManagementService->create($request->validated());

        return ApiResponse::created(
            new DndManagementResource($entry),
            'DND entry added successfully',
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $this->dndManagementService->remove($id);

        return ApiResponse::success(null, 'DND entry removed successfully');
    }
}
