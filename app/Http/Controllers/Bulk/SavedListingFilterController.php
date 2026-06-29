<?php

namespace App\Http\Controllers\Bulk;

use App\Http\Controllers\Controller;
use App\Services\Listing\SavedListingFilterService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedListingFilterController extends Controller
{
    public function __construct(
        private readonly SavedListingFilterService $savedListingFilterService,
    ) {}

    public function index(string $listingKey): JsonResponse
    {
        return ApiResponse::success(
            $this->savedListingFilterService->list($listingKey),
            'Saved filters loaded',
        );
    }

    public function store(Request $request, string $listingKey): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'filters' => ['required', 'array'],
            'user_id' => ['nullable', 'string', 'max:80'],
        ]);

        $filter = $this->savedListingFilterService->store(
            $listingKey,
            $validated['name'],
            $validated['filters'],
            $validated['user_id'] ?? null,
        );

        return ApiResponse::created($filter, 'Filter saved');
    }

    public function destroy(int $id): JsonResponse
    {
        $this->savedListingFilterService->delete($id);

        return ApiResponse::success(null, 'Filter deleted');
    }
}
