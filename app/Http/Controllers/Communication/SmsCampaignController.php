<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sms\StoreSmsCampaignRequest;
use App\Http\Resources\SmsCampaignResource;
use App\Http\Resources\SmsLogResource;
use App\Services\Sms\SmsCampaignService;
use App\Support\ApiResponse;
use App\Support\Listing\ListingResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmsCampaignController extends Controller
{
    public function __construct(
        private readonly SmsCampaignService $smsCampaignService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->smsCampaignService->search($request->query());

        return ListingResponse::from($result, SmsCampaignResource::class, 'SMS campaigns loaded');
    }

    public function store(StoreSmsCampaignRequest $request): JsonResponse
    {
        try {
            $campaign = $this->smsCampaignService->create($request->validated());
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::created(
            new SmsCampaignResource($campaign),
            'SMS campaign draft saved',
        );
    }

    public function show(string $id): JsonResponse
    {
        $campaign = $this->smsCampaignService->find($id);

        return ApiResponse::success(
            new SmsCampaignResource($campaign),
            'SMS campaign loaded',
        );
    }

    public function process(string $id): JsonResponse
    {
        try {
            $campaign = $this->smsCampaignService->process($id);
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(
            new SmsCampaignResource($campaign),
            $campaign->status === 'Processing'
                ? 'SMS campaign queued successfully.'
                : 'SMS campaign processed successfully.',
        );
    }

    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $campaign = $this->smsCampaignService->update(
                $this->smsCampaignService->find($id),
                $request->validate([
                    'campaign_name' => 'sometimes|string|max:255',
                    'message_template' => 'sometimes|string',
                    'scheduled_at' => 'nullable|date',
                ]),
            );
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(
            new SmsCampaignResource($campaign),
            'SMS campaign updated successfully',
        );
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $this->smsCampaignService->delete($this->smsCampaignService->find($id));
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(null, 'SMS campaign deleted successfully');
    }

    public function payloadPreview(string $id): JsonResponse
    {
        return ApiResponse::success(
            $this->smsCampaignService->payloadPreview($id),
            'SMS campaign payload mapping prepared',
        );
    }

    public function generatePayloadPreview(string $id): JsonResponse
    {
        try {
            $preview = $this->smsCampaignService->generateMappedPayloadPreview($id);
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(
            $preview,
            'SMS payload mapped and stored for audit',
        );
    }

    public function validatePreparation(Request $request): JsonResponse
    {
        try {
            $this->smsCampaignService->ensureCanSendSms(auth()->user());
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        $data = $request->validate([
            'message_template' => 'nullable|string',
            'sms_template_id' => 'required|integer|exists:sms_templates,id',
            'audience_mode' => 'required|string',
            'ca_ids' => 'nullable|array',
            'city_id' => 'nullable|integer',
            'state_id' => 'nullable|integer',
            'source_id' => 'nullable|integer',
            'rating' => 'nullable|integer',
            'team_size' => 'nullable|integer',
            'existing_software' => 'nullable|string',
        ]);

        $validation = $this->smsCampaignService->validatePreparation($data);

        return ApiResponse::success(
            $validation,
            $validation['valid'] ? 'SMS campaign validation passed' : 'SMS campaign validation failed',
        );
    }

    public function previewMessage(Request $request): JsonResponse
    {
        try {
            $this->smsCampaignService->ensureCanSendSms(auth()->user());
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        $data = $request->validate([
            'message_template' => 'nullable|string',
            'sms_template_id' => 'nullable|integer|exists:sms_templates,id',
            'lead_id' => 'required|integer|exists:ca_masters,ca_id',
        ]);

        return ApiResponse::success(
            $this->smsCampaignService->previewMessage(
                (string) ($data['message_template'] ?? ''),
                (int) $data['lead_id'],
                isset($data['sms_template_id']) ? (int) $data['sms_template_id'] : null,
            ),
            'Message preview generated',
        );
    }

    public function smsLogs(Request $request): JsonResponse
    {
        $result = $this->smsCampaignService->searchSmsLogs($request->query());

        return ListingResponse::from($result, SmsLogResource::class, 'SMS logs loaded');
    }
}
