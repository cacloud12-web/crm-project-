<?php

namespace App\Http\Controllers\Leads;

use App\Http\Controllers\Controller;
use App\Http\Resources\CaMasterResource;
use App\Models\CaMaster;
use App\Services\Leads\LeadResearchService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class LeadResearchController extends Controller
{
    public function __construct(
        private readonly LeadResearchService $leadResearchService,
    ) {}

    public function research(Request $request, string $caMaster): JsonResponse
    {
        $lead = CaMaster::query()->with(['city', 'state'])->findOrFail($caMaster);

        try {
            $result = $this->leadResearchService->research(
                $lead,
                $request->user(),
                $request->ip(),
            );
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        return ApiResponse::success($result, $this->researchMessage($result));
    }

    public function refresh(Request $request, string $caMaster): JsonResponse
    {
        $lead = CaMaster::query()->with(['city', 'state'])->findOrFail($caMaster);

        try {
            $result = $this->leadResearchService->refresh(
                $lead,
                $request->user(),
                $request->ip(),
            );
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        return ApiResponse::success($result, 'Google data refreshed');
    }

    public function select(Request $request, string $caMaster): JsonResponse
    {
        $lead = CaMaster::query()->with(['city', 'state'])->findOrFail($caMaster);

        $data = $request->validate([
            'place_id' => 'required|string|max:255',
        ]);

        try {
            $result = $this->leadResearchService->selectPlace(
                $lead,
                $data['place_id'],
                $request->user(),
                $request->ip(),
            );
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success($result, 'Google place selected');
    }

    public function save(Request $request, string $caMaster): JsonResponse
    {
        $lead = CaMaster::query()->with(['city', 'state'])->findOrFail($caMaster);

        $data = $request->validate([
            'fields' => 'required|array|min:1',
            'fields.*' => 'string',
            'place' => 'required|array',
            'place.google_place_id' => 'nullable|string|max:255',
            'place.place_id' => 'nullable|string|max:255',
            'place.website' => 'nullable|string|max:255',
            'place.address' => 'nullable|string|max:2000',
            'place.verified_address' => 'nullable|string|max:2000',
            'place.google_rating' => 'nullable|numeric|min:0|max:5',
            'place.google_review_count' => 'nullable|integer|min:0',
            'place.google_business_status' => 'nullable|string|max:50',
            'place.google_maps_url' => 'nullable|string|max:500',
            'place.mobile_no' => 'nullable|string|max:30',
            'place.latitude' => 'nullable|numeric',
            'place.longitude' => 'nullable|numeric',
            'place.business_name' => 'nullable|string|max:255',
        ]);

        try {
            $updated = $this->leadResearchService->saveFoundDetails(
                $lead,
                $data['fields'],
                $data['place'],
                $request->user(),
                $request->ip(),
            );
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(
            new CaMasterResource($updated),
            'Google Places details saved to lead',
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function researchMessage(array $result): string
    {
        if (! empty($result['cached'])) {
            return 'Loaded cached Google data for this lead';
        }

        if (! empty($result['api_error'])) {
            return (string) $result['api_error'];
        }

        if (! empty($result['multiple_results'])) {
            return 'Multiple Google results found — select the correct CA firm';
        }

        if (! empty($result['place'])) {
            return 'Google Places match found';
        }

        return 'Google lookup completed';
    }
}
