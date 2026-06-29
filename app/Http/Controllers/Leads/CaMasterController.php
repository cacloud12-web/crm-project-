<?php

namespace App\Http\Controllers\Leads;

use App\Http\Controllers\Controller;
use App\Http\Requests\CaMaster\StoreCaMasterRequest;
use App\Http\Requests\CaMaster\UpdateCaMasterContactRequest;
use App\Http\Requests\CaMaster\UpdateCaMasterRequest;
use App\Http\Requests\CaMaster\UpdateCaMasterStatusRequest;
use App\Http\Resources\CaMasterResource;
use App\Services\Leads\CaMasterService;
use App\Support\ApiResponse;
use App\Support\Listing\ListingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CaMasterController extends Controller
{
    public function __construct(
        private readonly CaMasterService $caMasterService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->caMasterService->search($request->query());

        return ListingResponse::from($result, CaMasterResource::class, 'Leads loaded');
    }

    public function create()
    {
        return redirect('/');
    }

    public function store(StoreCaMasterRequest $request): JsonResponse
    {
        $lead = $this->caMasterService->create($request->validated());

        return ApiResponse::created(
            new CaMasterResource($lead),
            'Lead added successfully',
        );
    }

    public function show(string $id): JsonResponse
    {
        $lead = $this->caMasterService->find($id);

        return ApiResponse::success(new CaMasterResource($lead));
    }

    public function edit(string $id)
    {
        return redirect('/');
    }

    public function update(UpdateCaMasterRequest $request, string $id): JsonResponse
    {
        $lead = $this->caMasterService->find($id);

        $lead = $this->caMasterService->update($lead, $request->validated());

        return ApiResponse::success(
            new CaMasterResource($lead),
            'Lead updated successfully',
        );
    }

    public function updateStatus(UpdateCaMasterStatusRequest $request, string $id): JsonResponse
    {
        $lead = $this->caMasterService->find($id);
        $lead = $this->caMasterService->updateStatus($lead, $request->validated('status'));

        return ApiResponse::success(
            new CaMasterResource($lead),
            'Lead status updated successfully',
        );
    }

    public function updateContact(UpdateCaMasterContactRequest $request, string $id): JsonResponse
    {
        $lead = $this->caMasterService->find($id);
        $lead = $this->caMasterService->updateContact($lead, $request->validated());

        return ApiResponse::success(
            new CaMasterResource($lead),
            'Contact details updated successfully',
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $lead = $this->caMasterService->find($id);
        $this->caMasterService->delete($lead);

        return ApiResponse::success(null, 'Lead deleted successfully');
    }
}
