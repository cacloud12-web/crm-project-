<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Services\Campaign\CampaignActionService;
use App\Services\Campaign\UnifiedCampaignService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class UnifiedCampaignController extends Controller
{
    public function __construct(
        private readonly UnifiedCampaignService $unifiedCampaignService,
        private readonly CampaignActionService $campaignActionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->unifiedCampaignService->search($request->query());

        return ApiResponse::success($result, 'Campaigns loaded');
    }

    public function show(string $channel, string $id): JsonResponse
    {
        return ApiResponse::success(
            $this->unifiedCampaignService->detail($channel, (int) $id),
            'Campaign detail loaded',
        );
    }

    public function duplicate(string $channel, string $id): JsonResponse
    {
        try {
            $campaign = $this->campaignActionService->duplicate($channel, $id);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::created([
            'channel' => $channel,
            'id' => $campaign->id,
            'campaign_uuid' => $campaign->campaign_uuid,
        ], 'Campaign duplicated');
    }

    public function pause(string $channel, string $id): JsonResponse
    {
        try {
            $campaign = $this->campaignActionService->pause($channel, $id);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['status' => $campaign->status], 'Campaign paused');
    }

    public function resume(string $channel, string $id): JsonResponse
    {
        try {
            $campaign = $this->campaignActionService->resume($channel, $id);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['status' => $campaign->status], 'Campaign resumed');
    }

    public function cancel(string $channel, string $id): JsonResponse
    {
        try {
            $campaign = $this->campaignActionService->cancel($channel, $id);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['status' => $campaign->status], 'Campaign cancelled');
    }

    public function retryFailed(string $channel, string $id): JsonResponse
    {
        try {
            $campaign = $this->campaignActionService->retryFailed($channel, $id);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success([
            'channel' => $channel,
            'id' => $campaign->id,
            'status' => $campaign->status,
        ], 'Failed messages queued for retry');
    }

    public function destroy(string $channel, string $id): JsonResponse
    {
        try {
            $this->campaignActionService->delete($channel, $id);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(null, 'Campaign deleted');
    }

    public function export(string $channel, string $id): Response
    {
        $report = $this->campaignActionService->exportReport($channel, $id);

        return response($report['content'], 200, [
            'Content-Type' => $report['mime'],
            'Content-Disposition' => 'attachment; filename="'.$report['filename'].'"',
        ]);
    }
}
