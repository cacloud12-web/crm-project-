<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Http\Requests\WhatsApp\StoreWhatsAppCampaignRequest;
use App\Http\Resources\WaMessageLogResource;
use App\Http\Resources\WhatsAppCampaignResource;
use App\Services\WhatsApp\WhatsAppCampaignService;
use App\Support\ApiResponse;
use App\Support\Listing\ListingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppCampaignController extends Controller
{
    public function __construct(
        private readonly WhatsAppCampaignService $whatsAppCampaignService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->whatsAppCampaignService->search($request->query());

        return ListingResponse::from($result, WhatsAppCampaignResource::class, 'WhatsApp campaigns loaded');
    }

    public function store(StoreWhatsAppCampaignRequest $request): JsonResponse
    {
        try {
            $campaign = $this->whatsAppCampaignService->create($request->validated());
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::created(
            new WhatsAppCampaignResource($campaign),
            'WhatsApp campaign created successfully',
        );
    }

    public function show(string $id): JsonResponse
    {
        $campaign = $this->whatsAppCampaignService->find($id);

        return ApiResponse::success(
            new WhatsAppCampaignResource($campaign),
            'WhatsApp campaign loaded',
        );
    }

    public function process(string $id): JsonResponse
    {
        try {
            $campaign = $this->whatsAppCampaignService->process($id);
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(
            new WhatsAppCampaignResource($campaign),
            'WhatsApp campaign processed successfully',
        );
    }

    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $campaign = $this->whatsAppCampaignService->update(
                $this->whatsAppCampaignService->find($id),
                $request->validate([
                    'campaign_name' => 'sometimes|string|max:255',
                    'message_template' => 'sometimes|string',
                    'scheduled_at' => 'nullable|date',
                ]),
            );
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(
            new WhatsAppCampaignResource($campaign),
            'WhatsApp campaign updated successfully',
        );
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $this->whatsAppCampaignService->delete($this->whatsAppCampaignService->find($id));
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(null, 'WhatsApp campaign deleted successfully');
    }

    public function messageLogs(Request $request): JsonResponse
    {
        $result = $this->whatsAppCampaignService->searchMessageLogs($request->query());

        return ListingResponse::from($result, WaMessageLogResource::class, 'WhatsApp message logs loaded');
    }
}
