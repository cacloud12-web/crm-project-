<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Search\GlobalSearchService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    public function __construct(
        private readonly GlobalSearchService $globalSearchService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = (string) $request->query('q', '');

        return ApiResponse::success([
            'results' => $this->globalSearchService->search($query, (int) $request->query('limit', 8)),
        ], 'Search results loaded');
    }
}
