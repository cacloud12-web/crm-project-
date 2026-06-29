<?php

namespace App\Http\Controllers\Leads;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeadAction\StoreLeadActionRequest;
use App\Http\Resources\LeadActionResource;
use App\Services\LeadAction\LeadActionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class LeadActionController extends Controller
{
    public function __construct(
        private readonly LeadActionService $leadActionService,
    ) {}

    public function store(StoreLeadActionRequest $request): JsonResponse
    {
        try {
            $record = $this->leadActionService->apply(
                (int) $request->validated('ca_id'),
                $request->validated('action_type'),
                $request->validated('remarks'),
            );

            return ApiResponse::success(
                new LeadActionResource($record),
                'Lead action applied successfully',
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }
}
