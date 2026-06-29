<?php

namespace App\Http\Controllers\Assignment;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssignmentHistoryResource;
use App\Services\Assignment\AssignmentHistoryService;
use App\Support\Listing\ListingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssignmentHistoryController extends Controller
{
    public function __construct(
        private readonly AssignmentHistoryService $assignmentHistoryService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->assignmentHistoryService->search($request->query());

        return ListingResponse::from($result, AssignmentHistoryResource::class, 'Assignment history loaded');
    }
}
