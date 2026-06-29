<?php

namespace App\Http\Controllers\Assignment;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeadAssignment\StoreLeadAssignmentRequest;
use App\Http\Resources\LeadAssignmentResource;
use App\Models\LeadAssignmentEngine;
use App\Services\Assignment\LeadAssignmentService;
use App\Support\ApiResponse;
use App\Support\Listing\ListingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadAssignmentEngineController extends Controller
{
    public function __construct(
        private readonly LeadAssignmentService $leadAssignmentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->leadAssignmentService->search($request->query());

        return ListingResponse::from($result, LeadAssignmentResource::class, 'Assignments loaded');
    }

    public function create()
    {
        return redirect('/');
    }

    public function store(StoreLeadAssignmentRequest $request): JsonResponse
    {
        $assignment = $this->leadAssignmentService->assign($request->validated());

        return ApiResponse::created(
            new LeadAssignmentResource($assignment->load(['caMaster', 'employee'])),
            'Lead assigned successfully',
        );
    }

    public function show(string $id): JsonResponse
    {
        $assignment = $this->leadAssignmentService->find($id);

        return ApiResponse::success(new LeadAssignmentResource($assignment));
    }

    public function edit(string $id)
    {
        return redirect('/');
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $assignment = $this->leadAssignmentService->update(
            LeadAssignmentEngine::findOrFail($id),
            $request->all(),
        );

        return ApiResponse::success(
            new LeadAssignmentResource($assignment),
            'Assignment updated successfully',
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $this->leadAssignmentService->delete(LeadAssignmentEngine::findOrFail($id));

        return ApiResponse::success(null, 'Assignment deleted successfully');
    }
}
