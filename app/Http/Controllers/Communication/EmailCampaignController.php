<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Email\StoreEmailCampaignRequest;
use App\Http\Resources\EmailCampaignResource;
use App\Http\Resources\EmailLogResource;
use App\Services\Email\EmailCampaignService;
use App\Support\ApiResponse;
use App\Support\Listing\ListingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailCampaignController extends Controller
{
    public function __construct(
        private readonly EmailCampaignService $emailCampaignService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->emailCampaignService->search($request->query());

        return ListingResponse::from($result, EmailCampaignResource::class, 'Email campaigns loaded');
    }

    public function store(StoreEmailCampaignRequest $request): JsonResponse
    {
        try {
            $campaign = $this->emailCampaignService->create($request->validated());
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::created(
            new EmailCampaignResource($campaign),
            $campaign->status === 'Processing'
                ? 'Email campaign queued successfully.'
                : 'Email campaign created successfully',
        );
    }

    public function show(string $id): JsonResponse
    {
        $campaign = $this->emailCampaignService->find($id);

        return ApiResponse::success(
            new EmailCampaignResource($campaign),
            'Email campaign loaded',
        );
    }

    public function process(string $id): JsonResponse
    {
        try {
            $campaign = $this->emailCampaignService->process($id);
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(
            new EmailCampaignResource($campaign),
            $campaign->status === 'Processing'
                ? 'Email campaign queued successfully.'
                : 'Email campaign processed successfully.',
        );
    }

    public function retryFailed(string $id): JsonResponse
    {
        try {
            $campaign = $this->emailCampaignService->retryFailed($id);
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(
            new EmailCampaignResource($campaign),
            'Failed email messages queued for retry',
        );
    }

    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $campaign = $this->emailCampaignService->update(
                $this->emailCampaignService->find($id),
                $request->validate([
                    'campaign_name' => 'sometimes|string|max:255',
                    'subject' => 'sometimes|string|max:255',
                    'body_template' => 'sometimes|string',
                    'message_template' => 'sometimes|string',
                    'scheduled_at' => 'nullable|date',
                ]),
            );
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(
            new EmailCampaignResource($campaign),
            'Email campaign updated successfully',
        );
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $this->emailCampaignService->delete($this->emailCampaignService->find($id));
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(null, 'Email campaign deleted successfully');
    }

    public function payloadPreview(string $id): JsonResponse
    {
        return ApiResponse::success(
            $this->emailCampaignService->payloadPreview($id),
            'Email campaign mail mapping prepared',
        );
    }

    public function emailLogs(Request $request): JsonResponse
    {
        $result = $this->emailCampaignService->searchEmailLogs($request->query());

        return ListingResponse::from($result, EmailLogResource::class, 'Email logs loaded');
    }
}
