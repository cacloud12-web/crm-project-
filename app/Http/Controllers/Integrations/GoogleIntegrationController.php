<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\CaMaster;
use App\Services\Leads\GooglePlacesApiService;
use App\Services\Leads\LeadResearchService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GoogleIntegrationController extends Controller
{
    public function __construct(
        private readonly GooglePlacesApiService $googlePlacesApi,
        private readonly LeadResearchService $leadResearchService,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ca_id' => 'nullable|integer|exists:ca_masters,ca_id',
            'firm_name' => 'nullable|string|max:255',
            'ca_name' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'q' => 'nullable|string|max:500',
        ]);

        $lead = null;
        if (! empty($data['ca_id'])) {
            $lead = CaMaster::query()->with(['city', 'state'])->findOrFail($data['ca_id']);

            try {
                $this->leadResearchService->assertCanResearch($request->user(), $lead);
            } catch (AuthorizationException $exception) {
                return ApiResponse::error($exception->getMessage(), 403);
            }
        }

        $query = $this->resolveSearchQuery($data, $lead);
        if ($query === '') {
            Log::warning('Google Places search rejected: empty query', [
                'ca_id' => $data['ca_id'] ?? null,
                'user_id' => $request->user()?->id,
            ]);

            return ApiResponse::success([
                'status' => 'INVALID_QUERY',
                'query' => '',
                'results' => [],
                'place' => null,
                'multiple_results' => false,
                'api_configured' => $this->leadResearchService->isApiConfigured(),
                'api_error' => LeadResearchService::INSUFFICIENT_LOOKUP_MESSAGE,
                'api_recommendation' => null,
                'api_google_reason' => null,
            ], 'Search query is incomplete');
        }

        if (! $this->leadResearchService->isApiConfigured()) {
            Log::warning('Google Places search failed: API key missing', [
                'ca_id' => $data['ca_id'] ?? null,
                'query' => $query,
                'user_id' => $request->user()?->id,
            ]);

            return ApiResponse::success([
                'status' => 'MISSING_API_KEY',
                'query' => $query,
                'results' => [],
                'place' => null,
                'multiple_results' => false,
                'api_configured' => false,
                'api_error' => 'Google Places API key is not configured. Set GOOGLE_PLACES_API_KEY or GOOGLE_MAPS_API_KEY in .env.',
                'api_recommendation' => 'Add the key in .env or Settings → Google API Settings. Enable Places API (New) and billing in Google Cloud.',
                'api_google_reason' => 'MISSING_API_KEY',
            ], 'Google Places API key is not configured');
        }

        $search = $this->googlePlacesApi->searchText($query);
        $status = (string) ($search['status'] ?? 'API_ERROR');

        if ($status !== 'OK') {
            $this->logSearchFailure($status, $search, $query, $data['ca_id'] ?? null, $request->user()?->id);

            return ApiResponse::success([
                'status' => $status,
                'query' => $query,
                'results' => [],
                'place' => null,
                'multiple_results' => false,
                'api_configured' => true,
                'api_error' => $search['message'] ?? 'Google Places search failed.',
                'api_recommendation' => $search['recommendation'] ?? null,
                'api_google_reason' => $search['google_reason'] ?? null,
                'http_status' => $search['http_status'] ?? null,
            ], $search['message'] ?? 'Google Places search failed');
        }

        $rawResults = $search['results'] ?? [];
        $scoredResults = $lead
            ? $this->leadResearchService->scoreResults($rawResults, $lead)
            : $rawResults;

        $formattedResults = array_map(
            fn (array $place) => $this->formatPlaceResult($place),
            $scoredResults,
        );

        $place = $formattedResults[0] ?? null;
        $encoded = rawurlencode($query);

        Log::info('Google Places search completed', [
            'ca_id' => $data['ca_id'] ?? null,
            'query' => $query,
            'result_count' => count($formattedResults),
            'user_id' => $request->user()?->id,
        ]);

        return ApiResponse::success([
            'status' => 'OK',
            'query' => $query,
            'results' => $formattedResults,
            'place' => $place,
            'multiple_results' => count($formattedResults) > 1,
            'api_configured' => true,
            'api_error' => null,
            'api_recommendation' => null,
            'api_google_reason' => null,
            'google_maps_url' => $place['google_maps_url'] ?? 'https://www.google.com/maps/search/?api=1&query='.$encoded,
            'google_maps_embed_url' => $place && $place['latitude'] && $place['longitude']
                ? 'https://www.google.com/maps?q='.$place['latitude'].','.$place['longitude'].'&output=embed'
                : 'https://www.google.com/maps?q='.$encoded.'&output=embed',
        ], count($formattedResults) > 1
            ? 'Multiple Google results found — select the correct CA firm'
            : ($place ? 'Google Places match found' : 'No Google Places match found'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveSearchQuery(array $data, ?CaMaster $lead): string
    {
        if ($lead) {
            return $this->leadResearchService->buildQuery($lead);
        }

        if (! empty($data['q'])) {
            return trim((string) $data['q']);
        }

        return $this->leadResearchService->buildQueryFromFields([
            'firm_name' => $data['firm_name'] ?? null,
            'ca_name' => $data['ca_name'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'mobile_no' => $data['mobile_no'] ?? null,
            'alternate_mobile_no' => $data['alternate_mobile_no'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $place
     * @return array<string, mixed>
     */
    private function formatPlaceResult(array $place): array
    {
        return [
            'place_id' => $place['place_id'] ?? $place['google_place_id'] ?? null,
            'google_place_id' => $place['google_place_id'] ?? $place['place_id'] ?? null,
            'business_name' => $place['business_name'] ?? null,
            'address' => $place['verified_address'] ?? $place['address'] ?? null,
            'verified_address' => $place['verified_address'] ?? $place['address'] ?? null,
            'phone' => $place['mobile_no'] ?? null,
            'mobile_no' => $place['mobile_no'] ?? null,
            'city_name' => $place['city_name'] ?? $place['city'] ?? null,
            'state_name' => $place['state_name'] ?? $place['state'] ?? null,
            'city' => $place['city_name'] ?? $place['city'] ?? null,
            'state' => $place['state_name'] ?? $place['state'] ?? null,
            'website' => $place['website'] ?? null,
            'rating' => $place['google_rating'] ?? null,
            'google_rating' => $place['google_rating'] ?? null,
            'google_review_count' => $place['google_review_count'] ?? null,
            'google_business_status' => $place['google_business_status'] ?? null,
            'google_maps_url' => $place['google_maps_url'] ?? null,
            'latitude' => $place['latitude'] ?? null,
            'longitude' => $place['longitude'] ?? null,
            'open_status' => $place['open_status'] ?? null,
            'confidence' => $place['confidence'] ?? null,
            'confidence_score' => $place['confidence_score'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $search
     */
    private function logSearchFailure(string $status, array $search, string $query, ?int $caId, ?int $userId): void
    {
        $context = [
            'status' => $status,
            'query' => $query,
            'ca_id' => $caId,
            'user_id' => $userId,
            'google_reason' => $search['google_reason'] ?? null,
            'http_status' => $search['http_status'] ?? null,
            'message' => $search['message'] ?? null,
            'api_key_masked' => $this->googlePlacesApi->maskApiKey($this->googlePlacesApi->apiKey()),
        ];

        if ($status === 'ZERO_RESULTS') {
            Log::info('Google Places search returned no results', $context);

            return;
        }

        Log::warning('Google Places search failed', $context);
    }
}
