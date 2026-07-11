<?php

namespace App\Services\Leads;

use App\Models\CaMaster;
use App\Models\LeadResearchLog;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use App\Services\Leads\LeadOwnershipService;
use App\Services\Master\LookupResolverService;
use App\Services\Rbac\RbacService;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class LeadResearchService
{
    public function __construct(
        private readonly LeadOwnershipService $leadOwnership,
        private readonly ActivityLogService $activityLogService,
        private readonly GooglePlacesApiService $googlePlacesApi,
        private readonly RbacService $rbacService,
        private readonly LookupResolverService $lookupResolver,
    ) {}

    public function isApiConfigured(): bool
    {
        return filled($this->googlePlacesApi->apiKey());
    }

    public function buildQuery(CaMaster $lead): string
    {
        $parts = array_filter([
            trim((string) $lead->firm_name),
            trim((string) $lead->ca_name),
            trim((string) ($lead->city?->city_name ?? '')),
            trim((string) ($lead->state?->state_name ?? '')),
            'Chartered Accountant',
        ], fn ($value) => $value !== '');

        return trim(implode(' ', $parts));
    }

    public function canRefreshGoogleData(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return in_array($this->rbacService->roleKey($user), ['super_admin', 'manager'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function research(CaMaster $lead, ?User $user = null, ?string $ipAddress = null, bool $forceRefresh = false): array
    {
        $this->assertCanResearch($user, $lead);

        $lead->loadMissing(['city', 'state']);
        $query = $this->buildQuery($lead);
        $encoded = rawurlencode($query);

        $payload = [
            'api_configured' => $this->isApiConfigured(),
            'cached' => false,
            'can_refresh' => $this->canRefreshGoogleData($user),
            'can_save' => $this->canSaveGoogleData($user, $lead),
            'query' => $query,
            'google_search_url' => 'https://www.google.com/search?q='.$encoded,
            'google_search_embed_url' => 'https://www.google.com/search?q='.$encoded.'&igu=1',
            'google_maps_url' => 'https://www.google.com/maps/search/?api=1&query='.$encoded,
            'google_maps_embed_url' => 'https://www.google.com/maps?q='.$encoded.'&output=embed',
            'results' => [],
            'place' => null,
            'source' => 'fallback',
            'api_status' => null,
            'api_error' => null,
            'current' => $this->currentLeadSnapshot($lead),
        ];

        if ($query === '') {
            $payload['api_error'] = 'Firm Name and CA Name are required before running a Google lookup.';

            return $payload;
        }

        if (! $forceRefresh && filled($lead->google_place_id)) {
            return $this->buildCachedResponse($lead, $query, $user);
        }

        if (! $this->isApiConfigured()) {
            $payload['api_error'] = 'Google Places API key is not configured. Add GOOGLE_PLACES_API_KEY to .env.';

            return $payload;
        }

        $search = $this->googlePlacesApi->searchText($query);
        $payload['api_status'] = $search['status'];

        if ($search['status'] !== 'OK') {
            $payload['api_error'] = $search['message'] ?? 'Google Places search failed.';
            $payload['api_recommendation'] = $search['recommendation'] ?? null;
            $payload['api_google_reason'] = $search['google_reason'] ?? null;
            $this->logResearch($lead, $user, $query, $payload, 'search', null, $ipAddress);

            return $payload;
        }

        $results = $this->scoreResults($search['results'], $lead);
        $payload['results'] = $results;
        $payload['source'] = 'google_places';
        $payload['place'] = $results[0] ?? null;

        if (count($results) > 1) {
            $payload['multiple_results'] = true;
        }

        $lead->google_places_cache = [
            'query' => $query,
            'results' => $results,
            'place' => $payload['place'],
            'fetched_at' => now()->toIso8601String(),
        ];
        $lead->save();

        $this->logResearch($lead, $user, $query, $payload, 'search', null, $ipAddress);
        $this->logActivity($lead, $user, $query, $payload, $ipAddress);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function refresh(CaMaster $lead, ?User $user = null, ?string $ipAddress = null): array
    {
        if (! $this->canRefreshGoogleData($user)) {
            throw new AuthorizationException('Only Manager or Super Admin can refresh Google data.');
        }

        return $this->research($lead, $user, $ipAddress, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function selectPlace(CaMaster $lead, string $placeId, ?User $user = null, ?string $ipAddress = null): array
    {
        $this->assertCanResearch($user, $lead);

        if (! $this->isApiConfigured()) {
            throw new InvalidArgumentException('Google Places API key is not configured.');
        }

        $details = $this->googlePlacesApi->fetchPlaceDetails($placeId);
        if ($details['status'] !== 'OK' || empty($details['place'])) {
            throw new InvalidArgumentException($details['message'] ?? 'Unable to load Google place details.');
        }

        $lead->loadMissing(['city', 'state']);
        $scored = $this->scoreResults([$details['place']], $lead);
        $place = $scored[0] ?? $details['place'];

        $cache = is_array($lead->google_places_cache) ? $lead->google_places_cache : [];
        $cache['place'] = $place;
        $cache['selected_place_id'] = $place['place_id'] ?? $placeId;
        $cache['fetched_at'] = now()->toIso8601String();
        $lead->google_places_cache = $cache;
        $lead->save();

        $payload = [
            'api_configured' => true,
            'cached' => false,
            'can_refresh' => $this->canRefreshGoogleData($user),
            'can_save' => $this->canSaveGoogleData($user, $lead),
            'query' => $this->buildQuery($lead),
            'place' => $place,
            'results' => $scored,
            'source' => 'google_places',
            'api_status' => 'OK',
            'current' => $this->currentLeadSnapshot($lead->fresh(['city', 'state'])),
        ];

        $this->logResearch($lead, $user, $payload['query'], $payload, 'select', null, $ipAddress);

        return $payload;
    }

    /**
     * @param  array<int, string>  $fields
     * @param  array<string, mixed>  $place
     */
    public function saveFoundDetails(
        CaMaster $lead,
        array $fields,
        array $place,
        ?User $user = null,
        ?string $ipAddress = null,
    ): CaMaster {
        $this->assertCanSaveGoogleData($user, $lead, true);

        $allowed = [
            'google_place_id',
            'website',
            'address',
            'verified_address',
            'google_rating',
            'google_review_count',
            'google_business_status',
            'google_maps_url',
            'mobile_no',
            'latitude',
            'longitude',
            'state_id',
            'city_id',
            'state_name',
            'city_name',
        ];

        $fields = array_values(array_intersect($fields, $allowed));
        if ($fields === []) {
            throw new InvalidArgumentException('Select at least one field to save.');
        }

        $canOverwrite = $this->canRefreshGoogleData($user);
        $update = [
            'researched_at' => now(),
            'research_status' => 'Research Complete',
            'verified_from_google' => true,
        ];

        $wantsState = in_array('state_id', $fields, true) || in_array('state_name', $fields, true);
        $wantsCity = in_array('city_id', $fields, true) || in_array('city_name', $fields, true);
        $fields = array_values(array_diff($fields, ['state_name', 'city_name']));

        foreach ($fields as $field) {
            if (in_array($field, ['state_id', 'city_id'], true)) {
                continue;
            }
            $value = $place[$field] ?? null;
            if ($field === 'google_place_id') {
                $value = $place['google_place_id'] ?? $place['place_id'] ?? null;
            }
            if ($value === null || $value === '') {
                continue;
            }

            $current = $lead->{$field} ?? null;
            if (! $canOverwrite && filled($current)) {
                continue;
            }

            $update[$field] = $value;
        }

        if ($wantsState || $wantsCity) {
            $stateName = $place['state_name'] ?? $place['state'] ?? null;
            $cityName = $place['city_name'] ?? $place['city'] ?? null;
            $stateId = $this->lookupResolver->resolveStateId($stateName);
            $cityId = $this->lookupResolver->resolveCityId($cityName, $stateId);

            if ($wantsState && $stateId) {
                $currentState = $lead->state_id;
                if ($canOverwrite || blank($currentState)) {
                    $update['state_id'] = $stateId;
                }
            }

            if ($wantsCity && $cityId) {
                $currentCity = $lead->city_id;
                if ($canOverwrite || blank($currentCity)) {
                    $update['city_id'] = $cityId;
                    if (! isset($update['state_id']) && $stateId && ($canOverwrite || blank($lead->state_id))) {
                        $update['state_id'] = $stateId;
                    }
                }
            }
        }

        if (isset($update['verified_address']) && ! isset($update['address'])) {
            $update['address'] = $update['verified_address'];
        }

        $cache = is_array($lead->google_places_cache) ? $lead->google_places_cache : [];
        $cache['place'] = array_merge($place, [
            'google_place_id' => $update['google_place_id'] ?? ($place['google_place_id'] ?? $place['place_id'] ?? null),
        ]);
        $cache['saved_at'] = now()->toIso8601String();
        $update['google_places_cache'] = $cache;

        $savedKeys = array_values(array_diff(array_keys($update), ['researched_at', 'research_status', 'google_places_cache']));

        $lead->fill($update);
        $lead->save();
        $lead = $lead->fresh(['city', 'state', 'sourceLead']);

        $this->logResearch(
            $lead,
            $user,
            $this->buildQuery($lead),
            ['place' => $place],
            'save',
            $savedKeys,
            $ipAddress,
        );

        $this->activityLogService->log(
            'CA_MASTER',
            'Save Google Places Details',
            (string) $lead->ca_id,
            ($lead->firm_name ?: $lead->ca_name).' · saved: '.implode(', ', $savedKeys ?: ['metadata only']),
            performedBy: $user?->name,
            afterValue: ['saved_fields' => $savedKeys],
            ipAddress: $ipAddress,
        );

        return $lead;
    }

    public function assertCanResearch(?User $user, CaMaster $lead): void
    {
        if (! $user) {
            throw new AuthorizationException('You must be signed in to use Google lookup.');
        }

        $role = $this->rbacService->roleKey($user);
        if (in_array($role, ['super_admin', 'manager', 'admin'], true)) {
            return;
        }

        if ($role === 'employee') {
            if ($this->leadOwnership->canEdit($user, $lead)) {
                return;
            }

            throw new AuthorizationException('You can only look up Google data for leads assigned to you.');
        }

        throw new AuthorizationException('You do not have permission to use Google lookup.');
    }

    public function canSaveGoogleData(?User $user, CaMaster $lead): bool
    {
        try {
            $this->assertCanResearch($user, $lead);
        } catch (AuthorizationException) {
            return false;
        }

        if ($lead->verified_from_google && $this->rbacService->roleKey($user) === 'employee') {
            return false;
        }

        return true;
    }

    public function assertCanSaveGoogleData(?User $user, CaMaster $lead, bool $throw = true): void
    {
        $this->assertCanResearch($user, $lead);

        if ($lead->verified_from_google && $this->rbacService->roleKey($user) === 'employee') {
            if ($throw) {
                throw new AuthorizationException('Verified Google data can only be updated by a Manager or Super Admin.');
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, array<string, mixed>>
     */
    public function scoreResults(array $results, CaMaster $lead): array
    {
        $firmKey = $this->normalizeMatchKey($lead->firm_name);
        $cityKey = $this->normalizeMatchKey($lead->city?->city_name);
        $stateKey = $this->normalizeMatchKey($lead->state?->state_name);

        $scored = array_map(function (array $place) use ($firmKey, $cityKey, $stateKey) {
            $haystack = strtolower(implode(' ', array_filter([
                (string) ($place['business_name'] ?? ''),
                (string) ($place['verified_address'] ?? $place['address'] ?? ''),
            ])));

            $confidence = [
                'firm_name_match' => $firmKey !== '' && str_contains($haystack, $firmKey),
                'city_match' => $cityKey !== '' && str_contains($haystack, $cityKey),
                'state_match' => $stateKey !== '' && str_contains($haystack, $stateKey),
                'ca_keyword_match' => str_contains($haystack, 'chartered accountant')
                    || str_contains($haystack, 'ca ')
                    || str_contains($haystack, ' c.a'),
            ];

            $score = 0;
            if ($confidence['firm_name_match']) {
                $score += 40;
            }
            if ($confidence['city_match']) {
                $score += 25;
            }
            if ($confidence['state_match']) {
                $score += 20;
            }
            if ($confidence['ca_keyword_match']) {
                $score += 15;
            }

            $place['confidence'] = $confidence;
            $place['confidence_score'] = $score;

            return $place;
        }, $results);

        usort($scored, fn (array $a, array $b) => ($b['confidence_score'] ?? 0) <=> ($a['confidence_score'] ?? 0));

        return array_values($scored);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCachedResponse(CaMaster $lead, string $query, ?User $user): array
    {
        $cache = is_array($lead->google_places_cache) ? $lead->google_places_cache : [];
        $place = $cache['place'] ?? $this->placeFromLeadRecord($lead);
        $results = $cache['results'] ?? ($place ? [$place] : []);

        if ($place && empty($results)) {
            $results = [$place];
        }

        if ($place && empty($place['google_place_id'])) {
            $place['google_place_id'] = $place['place_id'] ?? $lead->google_place_id;
        }

        $encoded = rawurlencode($query);
        $mapsEmbed = ($lead->latitude !== null && $lead->longitude !== null)
            ? 'https://www.google.com/maps?q='.$lead->latitude.','.$lead->longitude.'&output=embed'
            : 'https://www.google.com/maps?q='.$encoded.'&output=embed';

        return [
            'api_configured' => $this->isApiConfigured(),
            'cached' => true,
            'can_refresh' => $this->canRefreshGoogleData($user),
            'can_save' => $this->canSaveGoogleData($user, $lead),
            'query' => $query,
            'google_search_url' => 'https://www.google.com/search?q='.$encoded,
            'google_search_embed_url' => 'https://www.google.com/search?q='.$encoded.'&igu=1',
            'google_maps_url' => $lead->google_maps_url ?: 'https://www.google.com/maps/search/?api=1&query='.$encoded,
            'google_maps_embed_url' => $mapsEmbed,
            'results' => $results,
            'place' => $place,
            'source' => 'cache',
            'api_status' => 'CACHED',
            'api_error' => null,
            'current' => $this->currentLeadSnapshot($lead),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function placeFromLeadRecord(CaMaster $lead): array
    {
        return [
            'place_id' => $lead->google_place_id,
            'google_place_id' => $lead->google_place_id,
            'business_name' => $lead->firm_name,
            'verified_address' => $lead->verified_address ?: $lead->address,
            'address' => $lead->address ?: $lead->verified_address,
            'mobile_no' => $lead->mobile_no,
            'website' => $lead->website,
            'google_rating' => $lead->google_rating,
            'google_review_count' => $lead->google_review_count,
            'google_business_status' => $lead->google_business_status,
            'google_maps_url' => $lead->google_maps_url,
            'latitude' => $lead->latitude !== null ? (float) $lead->latitude : null,
            'longitude' => $lead->longitude !== null ? (float) $lead->longitude : null,
            'open_status' => $this->formatBusinessStatus($lead->google_business_status),
        ];
    }

    private function normalizeMatchKey(?string $value): string
    {
        $normalized = strtolower(trim((string) $value));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';

        return $normalized;
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
     * @return array<string, mixed>
     */
    private function currentLeadSnapshot(CaMaster $lead): array
    {
        return [
            'firm_name' => $lead->firm_name,
            'ca_name' => $lead->ca_name,
            'mobile_no' => $lead->mobile_no,
            'address' => $lead->address,
            'website' => $lead->website,
            'google_place_id' => $lead->google_place_id,
            'verified_address' => $lead->verified_address,
            'google_rating' => $lead->google_rating,
            'google_review_count' => $lead->google_review_count,
            'google_business_status' => $lead->google_business_status,
            'google_maps_url' => $lead->google_maps_url,
            'latitude' => $lead->latitude,
            'longitude' => $lead->longitude,
            'verified_from_google' => (bool) $lead->verified_from_google,
            'researched_at' => $lead->researched_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function logActivity(CaMaster $lead, ?User $user, string $query, array $payload, ?string $ipAddress): void
    {
        $this->activityLogService->log(
            'CA_MASTER',
            'Google Places Lookup',
            (string) $lead->ca_id,
            ($lead->firm_name ?: $lead->ca_name).' · '.$query,
            performedBy: $user?->name,
            afterValue: [
                'source' => $payload['source'] ?? 'fallback',
                'place_id' => $payload['place']['place_id'] ?? null,
                'result_count' => count($payload['results'] ?? []),
            ],
            ipAddress: $ipAddress,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>|null  $savedFields
     */
    private function logResearch(
        CaMaster $lead,
        ?User $user,
        string $query,
        array $payload,
        string $action,
        ?array $savedFields,
        ?string $ipAddress,
    ): void {
        LeadResearchLog::query()->create([
            'ca_id' => $lead->ca_id,
            'user_id' => $user?->id,
            'query' => $query,
            'source' => $payload['source'] ?? 'fallback',
            'place_id' => $payload['place']['place_id'] ?? $payload['place']['google_place_id'] ?? null,
            'result_payload' => [
                'results' => $payload['results'] ?? [],
                'place' => $payload['place'] ?? null,
                'api_status' => $payload['api_status'] ?? null,
            ],
            'saved_fields' => $savedFields,
            'action' => $action,
            'ip_address' => $ipAddress,
        ]);
    }
}
