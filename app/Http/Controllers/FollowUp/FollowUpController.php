<?php

namespace App\Http\Controllers\FollowUp;

use App\Http\Controllers\Controller;
use App\Http\Requests\FollowUp\SetFollowUpNextDateRequest;
use App\Http\Requests\FollowUp\StoreFollowUpRequest;
use App\Http\Requests\FollowUp\UpdateFollowUpRequest;
use App\Http\Resources\FollowUpResource;
use App\Models\FollowUp;
use App\Services\FollowUp\FollowUpService;
use App\Support\ApiResponse;
use App\Support\Listing\ListingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FollowUpController extends Controller
{
    public function __construct(
        private readonly FollowUpService $followUpService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->followUpService->search($request->query());

        return ListingResponse::from($result, FollowUpResource::class, 'Follow-ups loaded');
    }

    public function create()
    {
        return redirect('/');
    }

    public function store(StoreFollowUpRequest $request): JsonResponse
    {
        $followUp = $this->followUpService->create($request->validated());

        return ApiResponse::created(
            new FollowUpResource($followUp->load(['caMaster', 'employee'])),
            'Follow-up scheduled successfully',
        );
    }

    public function show(string $id): JsonResponse
    {
        $followUp = $this->followUpService->find($id);

        return ApiResponse::success(new FollowUpResource($followUp));
    }

    public function edit(string $id)
    {
        return redirect('/');
    }

    public function update(UpdateFollowUpRequest $request, string $id): JsonResponse
    {
        $followUp = $this->followUpService->find($id);

        $followUp = $this->followUpService->update(
            $followUp,
            $request->validated(),
        );

        return ApiResponse::success(
            new FollowUpResource($followUp),
            'Follow-up updated successfully',
        );
    }

    public function setNextFollowUpDate(SetFollowUpNextDateRequest $request, string $id): JsonResponse
    {
        $followUp = $this->followUpService->find($id);
        $result = $this->followUpService->setNextFollowUpDate($followUp, $request->validated());
        $cleared = ($result['follow_up']->next_followup_date ?? null) === null
            && ! ($result['next_follow_up'] ?? null);

        return ApiResponse::success([
            'follow_up' => new FollowUpResource($result['follow_up']),
            'next_follow_up' => $result['next_follow_up']
                ? new FollowUpResource($result['next_follow_up'])
                : null,
        ], $cleared
            ? 'Next follow-up date cleared'
            : ($result['next_follow_up'] ? 'Next follow-up scheduled' : 'Next follow-up date saved'));
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $this->followUpService->delete($this->followUpService->find($id));

            return ApiResponse::success(null, 'Follow-up deleted successfully');
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }
}
