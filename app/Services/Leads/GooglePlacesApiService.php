<?php

namespace App\Services\Leads;

use App\Models\City;
use App\Models\State;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GooglePlacesApiService
{
    /**
     * @return array{status: string, message: string|null, results: array<int, array<string, mixed>>}
     */
    public function searchText(string $query): array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            return [
                'status' => 'MISSING_API_KEY',
                'message' => 'Google Places API key is not configured.',
                'results' => [],
            ];
        }

        $timeout = (int) config('crm_research.timeout_seconds', 8);
        $maxResults = (int) config('crm_research.max_search_results', 10);

        try {
            $response = Http::timeout($timeout)
                ->withHeaders($this->placesRequestHeaders(
                    (string) config('crm_research.places_new_search_field_mask')
                ))
                ->post((string) config('crm_research.places_new_search_url'), [
                    'textQuery' => $query,
                    'maxResultCount' => $maxResults,
                    'languageCode' => 'en',
                ]);
        } catch (ConnectionException $exception) {
            return [
                'status' => 'NETWORK_ERROR',
                'message' => 'Unable to reach Google Places API. Check your network connection and try again.',
                'results' => [],
            ];
        }

        if (! $response->successful()) {
            $this->logApiFailure('searchText', $query, $response);

            return $this->mapHttpFailure($response->status(), $response->json());
        }

        $payload = $response->json();
        $places = $payload['places'] ?? [];

        if ($places === []) {
            return [
                'status' => 'ZERO_RESULTS',
                'message' => 'No Google Places results matched this CA firm.',
                'results' => [],
            ];
        }

        return [
            'status' => 'OK',
            'message' => null,
            'results' => array_map(fn (array $place) => $this->normalizeNewPlace($place), $places),
        ];
    }

    /**
     * @return array{status: string, message: string|null, place: array<string, mixed>|null}
     */
    public function fetchPlaceDetails(string $placeId): array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            return [
                'status' => 'MISSING_API_KEY',
                'message' => 'Google Places API key is not configured.',
                'place' => null,
            ];
        }

        $resourceId = $this->normalizePlaceResourceId($placeId);
        $timeout = (int) config('crm_research.timeout_seconds', 8);

        try {
            $response = Http::timeout($timeout)
                ->withHeaders($this->placesRequestHeaders(
                    (string) config('crm_research.places_new_details_field_mask')
                ))
                ->get(rtrim((string) config('crm_research.places_new_details_url'), '/').'/'.rawurlencode($resourceId));
        } catch (ConnectionException $exception) {
            return [
                'status' => 'NETWORK_ERROR',
                'message' => 'Unable to reach Google Places API. Check your network connection and try again.',
                'place' => null,
            ];
        }

        if (! $response->successful()) {
            $this->logApiFailure('fetchPlaceDetails', $placeId, $response);
            $mapped = $this->mapHttpFailure($response->status(), $response->json());

            return [
                'status' => $mapped['status'],
                'message' => $mapped['message'],
                'place' => null,
            ];
        }

        $place = $response->json();
        if (! is_array($place) || $place === []) {
            return [
                'status' => 'ZERO_RESULTS',
                'message' => 'Google place details were not found.',
                'place' => null,
            ];
        }

        return [
            'status' => 'OK',
            'message' => null,
            'place' => $this->normalizeNewPlace($place),
        ];
    }

    /**
     * @param  array<string, mixed>  $place
     * @return array<string, mixed>
     */
    public function normalizeNewPlace(array $place): array
    {
        $placeId = $place['id'] ?? $place['place_id'] ?? null;
        $displayName = is_array($place['displayName'] ?? null)
            ? ($place['displayName']['text'] ?? null)
            : ($place['displayName'] ?? $place['name'] ?? null);

        $lat = data_get($place, 'location.latitude', data_get($place, 'geometry.location.lat'));
        $lng = data_get($place, 'location.longitude', data_get($place, 'geometry.location.lng'));
        $phone = $place['internationalPhoneNumber']
            ?? $place['nationalPhoneNumber']
            ?? $place['formatted_phone_number']
            ?? $place['international_phone_number']
            ?? null;

        $mapsUrl = $place['googleMapsUri']
            ?? $place['url']
            ?? ($placeId ? 'https://www.google.com/maps/place/?q=place_id:'.urlencode((string) $placeId) : null);

        $address = $place['formattedAddress'] ?? $place['formatted_address'] ?? null;
        $locationParts = $this->extractLocationParts($place, is_string($address) ? $address : null);

        return [
            'place_id' => $placeId,
            'google_place_id' => $placeId,
            'business_name' => $displayName,
            'verified_address' => $address,
            'address' => $address,
            'mobile_no' => $phone,
            'phone' => $phone,
            'city_name' => $locationParts['city_name'],
            'state_name' => $locationParts['state_name'],
            'city' => $locationParts['city_name'],
            'state' => $locationParts['state_name'],
            'website' => $place['websiteUri'] ?? $place['website'] ?? null,
            'google_rating' => isset($place['rating']) ? (float) $place['rating'] : null,
            'google_review_count' => isset($place['userRatingCount'])
                ? (int) $place['userRatingCount']
                : (isset($place['user_ratings_total']) ? (int) $place['user_ratings_total'] : null),
            'google_business_status' => $place['businessStatus'] ?? $place['business_status'] ?? null,
            'google_maps_url' => $mapsUrl,
            'latitude' => $lat !== null ? (float) $lat : null,
            'longitude' => $lng !== null ? (float) $lng : null,
            'open_status' => $this->formatBusinessStatus($place['businessStatus'] ?? $place['business_status'] ?? null),
        ];
    }

    /**
     * @return array{city_name: ?string, state_name: ?string}
     */
    private function extractLocationParts(array $place, ?string $formattedAddress): array
    {
        $city = null;
        $state = null;
        $components = $place['addressComponents'] ?? $place['address_components'] ?? [];

        if (is_array($components)) {
            foreach ($components as $component) {
                if (! is_array($component)) {
                    continue;
                }
                $types = $component['types'] ?? [];
                if (! is_array($types)) {
                    continue;
                }
                $name = $component['longText']
                    ?? $component['long_name']
                    ?? $component['shortText']
                    ?? $component['short_name']
                    ?? null;
                if (! is_string($name) || trim($name) === '') {
                    continue;
                }
                $name = trim($name);

                if ($city === null && (in_array('locality', $types, true) || in_array('postal_town', $types, true))) {
                    $city = $name;
                }
                if ($city === null && in_array('administrative_area_level_3', $types, true)) {
                    $city = $name;
                }
                if ($state === null && in_array('administrative_area_level_1', $types, true)) {
                    $state = $name;
                }
            }
        }

        if (($city === null || $state === null) && filled($formattedAddress)) {
            $parsed = $this->parseIndianAddressFallback($formattedAddress);
            $city = $city ?? $parsed['city_name'];
            $state = $state ?? $parsed['state_name'];
        }

        return [
            'city_name' => $city,
            'state_name' => $state,
        ];
    }

    /**
     * @return array{city_name: ?string, state_name: ?string}
     */
    private function parseIndianAddressFallback(string $address): array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $address))));
        $state = null;
        $city = null;

        foreach ($parts as $part) {
            $clean = trim(preg_replace('/\b\d{6}\b/', '', $part) ?? $part);
            $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);
            if ($clean === '' || strcasecmp($clean, 'India') === 0) {
                continue;
            }
            if ($state === null && State::query()->whereRaw('LOWER(state_name) = ?', [mb_strtolower($clean)])->exists()) {
                $state = State::query()->whereRaw('LOWER(state_name) = ?', [mb_strtolower($clean)])->value('state_name');
            }
        }

        if ($state !== null) {
            foreach ($parts as $part) {
                $clean = trim(preg_replace('/\b\d{6}\b/', '', $part) ?? $part);
                $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);
                if ($clean === '' || strcasecmp($clean, 'India') === 0 || strcasecmp($clean, $state) === 0) {
                    continue;
                }
                if (City::query()->whereRaw('LOWER(city_name) = ?', [mb_strtolower($clean)])->exists()) {
                    $city = City::query()->whereRaw('LOWER(city_name) = ?', [mb_strtolower($clean)])->value('city_name');
                    break;
                }
            }
        }

        return [
            'city_name' => $city,
            'state_name' => $state,
        ];
    }

    public function apiKey(): string
    {
        $envKey = (string) config('services.google.places_api_key', '');
        if (filled($envKey)) {
            return $envKey;
        }

        $stored = \App\Models\CrmSetting::query()
            ->where('group', 'google_api')
            ->where('key', 'places_api_key')
            ->value('value');

        if (filled($stored)) {
            return (string) $stored;
        }

        return '';
    }

    public function maskApiKey(string $key): string
    {
        $len = strlen($key);
        if ($len <= 8) {
            return str_repeat('•', $len);
        }

        return substr($key, 0, 6).str_repeat('•', max(4, $len - 10)).substr($key, -4);
    }

    /**
     * Run a diagnostic Places search and return full request/response metadata for admins.
     *
     * @return array<string, mixed>
     */
    public function probe(string $query = 'CA firm Mumbai'): array
    {
        $apiKey = $this->apiKey();
        $url = (string) config('crm_research.places_new_search_url');
        $fieldMask = (string) config('crm_research.places_new_search_field_mask');
        $requestBody = [
            'textQuery' => $query,
            'maxResultCount' => (int) config('crm_research.max_search_results', 3),
            'languageCode' => 'en',
        ];

        $base = [
            'endpoint' => $url,
            'field_mask' => $fieldMask,
            'request_body' => $requestBody,
            'api_key_masked' => $apiKey !== '' ? $this->maskApiKey($apiKey) : null,
            'api_key_source' => $this->apiKeySource(),
        ];

        if ($apiKey === '') {
            return array_merge($base, [
                'success' => false,
                'http_status' => null,
                'google_status' => 'MISSING_API_KEY',
                'google_reason' => 'MISSING_API_KEY',
                'message' => 'Google Places API key is not configured.',
                'recommendation' => 'Set GOOGLE_PLACES_API_KEY in .env or save a key under Settings → Google API Settings.',
                'response' => null,
            ]);
        }

        try {
            $response = Http::timeout((int) config('crm_research.timeout_seconds', 10))
                ->withHeaders($this->placesRequestHeaders($fieldMask))
                ->post($url, $requestBody);
        } catch (ConnectionException $exception) {
            return array_merge($base, [
                'success' => false,
                'http_status' => null,
                'google_status' => 'NETWORK_ERROR',
                'google_reason' => 'NETWORK_ERROR',
                'message' => 'Unable to reach Google Places API: '.$exception->getMessage(),
                'recommendation' => 'Check server outbound HTTPS access to places.googleapis.com.',
                'response' => null,
            ]);
        }

        $json = $response->json();
        if ($response->successful()) {
            $count = count($json['places'] ?? []);

            return array_merge($base, [
                'success' => true,
                'http_status' => $response->status(),
                'google_status' => 'OK',
                'google_reason' => null,
                'message' => 'Google Places API connection successful.',
                'recommendation' => null,
                'sample_results' => $count,
                'response' => $json,
            ]);
        }

        $mapped = $this->mapHttpFailure($response->status(), is_array($json) ? $json : null);

        return array_merge($base, [
            'success' => false,
            'http_status' => $response->status(),
            'google_status' => $mapped['status'],
            'google_reason' => $mapped['google_reason'] ?? null,
            'message' => $mapped['message'],
            'recommendation' => $mapped['recommendation'] ?? null,
            'sample_results' => 0,
            'response' => $json,
        ]);
    }

    public function apiKeySource(): string
    {
        $envKey = (string) config('services.google.places_api_key', '');
        if (filled($envKey)) {
            return 'environment';
        }

        $stored = \App\Models\CrmSetting::query()
            ->where('group', 'google_api')
            ->where('key', 'places_api_key')
            ->value('value');

        if (filled($stored)) {
            return 'database';
        }

        return 'none';
    }

    private function normalizePlaceResourceId(string $placeId): string
    {
        $placeId = trim($placeId);

        return str_starts_with($placeId, 'places/') ? $placeId : 'places/'.$placeId;
    }

    /**
     * @return array<string, string>
     */
    private function placesRequestHeaders(string $fieldMask): array
    {
        $fieldMask = trim($fieldMask);
        if ($fieldMask === '') {
            $fieldMask = (string) config('crm_research.places_new_search_field_mask');
        }

        return [
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => $this->apiKey(),
            'X-Goog-FieldMask' => $fieldMask,
        ];
    }

    private function formatBusinessStatus(?string $status): ?string
    {
        return match ($status) {
            'OPERATIONAL' => 'Open',
            'CLOSED_TEMPORARILY' => 'Temporarily closed',
            'CLOSED_PERMANENTLY' => 'Permanently closed',
            default => $status,
        };
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array{status: string, message: string|null, results: array<int, array<string, mixed>>, google_reason?: string|null, recommendation?: string|null, http_status?: int}
     */
    private function mapHttpFailure(int $httpStatus, ?array $payload): array
    {
        $errorMessage = (string) (data_get($payload, 'error.message')
            ?? data_get($payload, 'error.status')
            ?? 'Google Places API request failed.');

        $status = strtoupper((string) (data_get($payload, 'error.status') ?? ''));
        $reason = $this->extractErrorReason($payload);
        $guidance = $this->guidanceForReason($reason, $status, $errorMessage, $httpStatus);

        return [
            'status' => $guidance['status'],
            'message' => $guidance['message'],
            'results' => [],
            'google_reason' => $reason,
            'recommendation' => $guidance['recommendation'],
            'http_status' => $httpStatus,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function extractErrorReason(?array $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        $details = $payload['error']['details'] ?? [];
        if (! is_array($details)) {
            return null;
        }

        foreach ($details as $detail) {
            if (! is_array($detail)) {
                continue;
            }
            $reason = $detail['reason'] ?? null;
            if (is_string($reason) && $reason !== '') {
                return $reason;
            }
        }

        return null;
    }

    /**
     * @return array{status: string, message: string, recommendation: string|null}
     */
    private function guidanceForReason(?string $reason, string $status, string $errorMessage, int $httpStatus): array
    {
        $lower = strtolower($errorMessage);

        return match ($reason) {
            'API_KEY_ANDROID_APP_BLOCKED' => [
                'status' => 'PERMISSION_DENIED',
                'message' => 'Google blocked this request: the API key is restricted to Android apps, but CRM calls Places from the Laravel server.',
                'recommendation' => 'In Google Cloud Console → APIs & Services → Credentials, edit this key and set Application restrictions to None (dev) or IP addresses (your server IP). Enable Places API (New).',
            ],
            'API_KEY_IOS_APP_BLOCKED' => [
                'status' => 'PERMISSION_DENIED',
                'message' => 'Google blocked this request: the API key is restricted to iOS apps, but CRM calls Places from the Laravel server.',
                'recommendation' => 'Use a server-side API key with Application restrictions set to None or IP addresses — not iOS bundle restrictions.',
            ],
            'API_KEY_HTTP_REFERRER_BLOCKED' => [
                'status' => 'PERMISSION_DENIED',
                'message' => 'Google blocked this request: the API key is restricted to website referrers, but CRM calls Places from the Laravel server.',
                'recommendation' => 'Create or use a separate server key with Application restrictions = None or IP addresses. Referrer-restricted keys only work in browser JavaScript.',
            ],
            'API_KEY_IP_ADDRESS_BLOCKED' => [
                'status' => 'PERMISSION_DENIED',
                'message' => 'Google blocked this request: your server IP is not on the API key allow-list.',
                'recommendation' => 'Add your server public IP to the key\'s IP address restrictions in Google Cloud Console.',
            ],
            'SERVICE_DISABLED', 'API_DISABLED' => [
                'status' => 'API_NOT_ACTIVATED',
                'message' => 'Places API (New) is not enabled for this Google Cloud project.',
                'recommendation' => 'Enable "Places API (New)" in Google Cloud Console → APIs & Services → Library.',
            ],
            default => $this->guidanceFromMessage($httpStatus, $status, $lower, $errorMessage),
        };
    }

    /**
     * @return array{status: string, message: string, recommendation: string|null}
     */
    private function guidanceFromMessage(int $httpStatus, string $status, string $lower, string $errorMessage): array
    {
        if ($httpStatus === 403 || str_contains($lower, 'api key not valid') || str_contains($lower, 'api_key_invalid')) {
            return [
                'status' => 'API_KEY_INVALID',
                'message' => 'Google Places API key is invalid or not authorized for Places API (New).',
                'recommendation' => 'Verify the key value, enable Places API (New), and ensure billing is enabled on the Google Cloud project.',
            ];
        }

        if (str_contains($lower, 'billing') || str_contains($lower, 'billingnotenabled')) {
            return [
                'status' => 'BILLING_NOT_ENABLED',
                'message' => 'Google Cloud billing is not enabled for this project.',
                'recommendation' => 'Enable billing in Google Cloud Console. Places API requires an active billing account.',
            ];
        }

        if ($httpStatus === 429 || str_contains($lower, 'quota')) {
            return [
                'status' => 'OVER_QUERY_LIMIT',
                'message' => 'Google Places API quota exceeded. Try again later or contact your administrator.',
                'recommendation' => 'Review quota usage in Google Cloud Console or request a quota increase.',
            ];
        }

        if (str_contains($lower, 'referer') || str_contains($lower, 'referrer')) {
            return [
                'status' => 'REFERER_NOT_ALLOWED',
                'message' => 'API key HTTP referrer restriction blocked this server-side request.',
                'recommendation' => 'Use a server-side key without website referrer restrictions.',
            ];
        }

        if (str_contains($lower, 'android client application')) {
            return [
                'status' => 'PERMISSION_DENIED',
                'message' => 'Google blocked this request: the API key is restricted to Android apps, but CRM calls Places from the Laravel server.',
                'recommendation' => 'In Google Cloud Console → APIs & Services → Credentials, edit this key and set Application restrictions to None (dev) or IP addresses (your server IP). Enable Places API (New).',
            ];
        }

        if (str_contains($lower, 'ios client application')) {
            return [
                'status' => 'PERMISSION_DENIED',
                'message' => 'Google blocked this request: the API key is restricted to iOS apps, but CRM calls Places from the Laravel server.',
                'recommendation' => 'Use a server-side API key with Application restrictions set to None or IP addresses — not iOS bundle restrictions.',
            ];
        }

        return [
            'status' => $status !== '' ? $status : 'API_ERROR',
            'message' => $errorMessage,
            'recommendation' => $httpStatus === 403
                ? 'Check API key restrictions and ensure Places API (New) is enabled with billing active.'
                : null,
        ];
    }

    private function logApiFailure(string $operation, string $context, Response $response): void
    {
        $json = $response->json();
        Log::warning('Google Places API request failed', [
            'operation' => $operation,
            'context' => $context,
            'http_status' => $response->status(),
            'google_status' => data_get($json, 'error.status'),
            'google_reason' => $this->extractErrorReason(is_array($json) ? $json : null),
            'google_message' => data_get($json, 'error.message'),
            'endpoint' => config('crm_research.places_new_search_url'),
            'api_key_masked' => $this->maskApiKey($this->apiKey()),
        ]);
    }
}
