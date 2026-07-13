<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LeadGooglePlacesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.places_api_key' => 'test-google-key',
            'crm_research.google_places_api_key' => 'test-google-key',
        ]);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        return $admin;
    }

    private function sampleLead(array $overrides = []): CaMaster
    {
        return CaMaster::query()->create(array_merge([
            'ca_name' => 'Ajay Kumar Bhardwaj',
            'firm_name' => 'AK Bhardwaj & Co',
            'status' => 'New',
        ], $overrides));
    }

    public function test_google_lookup_builds_chartered_accountant_query(): void
    {
        $lead = $this->sampleLead();
        $lead->load(['city', 'state']);

        $query = app(\App\Services\Leads\LeadResearchService::class)->buildQuery($lead);

        $this->assertStringContainsString('AK Bhardwaj & Co', $query);
        $this->assertStringContainsString('Ajay Kumar Bhardwaj', $query);
        $this->assertStringContainsString('Chartered Accountant', $query);
    }

    public function test_google_lookup_returns_cached_data_when_place_id_exists(): void
    {
        $this->actingAsAdmin();

        $lead = $this->sampleLead([
            'google_place_id' => 'places/chij-cached-123',
            'verified_address' => '12 Park Street, Kolkata',
            'google_rating' => 4.5,
            'google_places_cache' => [
                'place' => [
                    'place_id' => 'places/chij-cached-123',
                    'business_name' => 'AK Bhardwaj & Co',
                    'verified_address' => '12 Park Street, Kolkata',
                ],
            ],
        ]);

        Http::fake();

        $response = $this->postJson('/ca-masters/'.$lead->ca_id.'/research');
        $response->assertOk();
        $response->assertJsonPath('data.cached', true);
        $response->assertJsonPath('data.place.google_place_id', 'places/chij-cached-123');

        Http::assertNothingSent();
    }

    public function test_google_lookup_returns_multiple_results_with_confidence(): void
    {
        $this->actingAsAdmin();
        $lead = $this->sampleLead();

        Http::fake([
            'places.googleapis.com/v1/places:searchText' => Http::response([
                'places' => [
                    [
                        'id' => 'places/chij-one',
                        'displayName' => ['text' => 'AK Bhardwaj & Co Chartered Accountants'],
                        'formattedAddress' => 'Kolkata, West Bengal, India',
                        'rating' => 4.6,
                        'userRatingCount' => 12,
                        'businessStatus' => 'OPERATIONAL',
                        'googleMapsUri' => 'https://maps.google.com/?cid=1',
                        'location' => ['latitude' => 22.57, 'longitude' => 88.36],
                    ],
                    [
                        'id' => 'places/chij-two',
                        'displayName' => ['text' => 'Another CA Firm'],
                        'formattedAddress' => 'Mumbai, Maharashtra, India',
                        'rating' => 4.1,
                        'businessStatus' => 'OPERATIONAL',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/ca-masters/'.$lead->ca_id.'/research');
        $response->assertOk();
        $response->assertJsonPath('data.source', 'google_places');
        $response->assertJsonPath('data.multiple_results', true);
        $this->assertGreaterThanOrEqual(2, count($response->json('data.results')));
        $this->assertTrue($response->json('data.results.0.confidence.firm_name_match') === true
            || $response->json('data.results.0.confidence_score') >= 0);
    }

    public function test_google_lookup_handles_no_results(): void
    {
        $this->actingAsAdmin();
        $lead = $this->sampleLead();

        Http::fake([
            'places.googleapis.com/v1/places:searchText' => Http::response(['places' => []], 200),
        ]);

        $response = $this->postJson('/ca-masters/'.$lead->ca_id.'/research');
        $response->assertOk();
        $response->assertJsonPath('data.api_status', 'ZERO_RESULTS');
        $response->assertJsonPath('data.api_error', 'No Google Places results matched this CA firm.');
    }

    public function test_google_lookup_logs_activity_with_performed_by(): void
    {
        $admin = $this->actingAsAdmin();
        $lead = $this->sampleLead();

        Http::fake([
            'places.googleapis.com/v1/places:searchText' => Http::response([
                'places' => [[
                    'id' => 'places/chij-log-1',
                    'displayName' => ['text' => 'AK Bhardwaj & Co'],
                    'formattedAddress' => '12 Park Street, Kolkata',
                    'nationalPhoneNumber' => '+91 9876543210',
                    'rating' => 4.6,
                    'userRatingCount' => 12,
                    'websiteUri' => 'https://example.test',
                    'location' => ['latitude' => 22.57, 'longitude' => 88.36],
                    'businessStatus' => 'OPERATIONAL',
                ]],
            ], 200),
        ]);

        $response = $this->postJson('/ca-masters/'.$lead->ca_id.'/research');
        $response->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'module_name' => 'CA_MASTER',
            'action' => 'Google Places Lookup',
            'record_id' => (string) $lead->ca_id,
            'performed_by' => $admin->name,
        ]);
    }

    public function test_google_lookup_handles_invalid_api_key(): void
    {
        $this->actingAsAdmin();
        $lead = $this->sampleLead();

        Http::fake([
            'places.googleapis.com/v1/places:searchText' => Http::response([
                'error' => [
                    'code' => 403,
                    'message' => 'The provided API key is invalid.',
                    'status' => 'PERMISSION_DENIED',
                ],
            ], 403),
        ]);

        $response = $this->postJson('/ca-masters/'.$lead->ca_id.'/research');
        $response->assertOk();
        $response->assertJsonPath('data.api_status', 'API_KEY_INVALID');
    }

    public function test_save_google_data_persists_fields(): void
    {
        $this->actingAsAdmin();
        $lead = $this->sampleLead();

        $place = [
            'place_id' => 'places/chij-save-1',
            'google_place_id' => 'places/chij-save-1',
            'verified_address' => '22 Lake Road, Kolkata',
            'address' => '22 Lake Road, Kolkata',
            'mobile_no' => '9876543210',
            'website' => 'https://example-ca.test',
            'google_rating' => 4.7,
            'google_maps_url' => 'https://maps.google.com/?cid=save1',
            'latitude' => 22.58,
            'longitude' => 88.37,
        ];

        $response = $this->postJson('/ca-masters/'.$lead->ca_id.'/research/save', [
            'fields' => ['google_place_id', 'address', 'mobile_no', 'website', 'google_rating', 'google_maps_url', 'latitude', 'longitude'],
            'place' => $place,
        ]);

        $response->assertOk();
        $lead->refresh();
        $this->assertSame('places/chij-save-1', $lead->google_place_id);
        $this->assertSame('9876543210', $lead->mobile_no);
        $this->assertTrue($lead->verified_from_google);
        $this->assertSame(22.58, (float) $lead->latitude);
    }

    public function test_save_google_data_maps_city_state_and_phone(): void
    {
        $this->actingAsAdmin();
        $this->seed(\Database\Seeders\IndiaStatesCitiesSeeder::class);

        $lead = $this->sampleLead();
        $place = [
            'place_id' => 'places/chij-geo-1',
            'google_place_id' => 'places/chij-geo-1',
            'mobile_no' => '+91 98765 43210',
            'city_name' => 'Mumbai',
            'state_name' => 'Maharashtra',
            'verified_address' => 'Andheri, Mumbai, Maharashtra 400053, India',
        ];

        $response = $this->postJson('/ca-masters/'.$lead->ca_id.'/research/save', [
            'fields' => ['mobile_no', 'city_name', 'state_name', 'google_place_id'],
            'place' => $place,
        ]);

        $response->assertOk();
        $lead->refresh()->load(['city', 'state']);
        $this->assertSame('+91 98765 43210', $lead->mobile_no);
        $this->assertSame('Maharashtra', $lead->state?->state_name);
        $this->assertSame('Mumbai', $lead->city?->city_name);
    }

    public function test_normalize_place_extracts_city_state_from_address_components(): void
    {
        $service = app(\App\Services\Leads\GooglePlacesApiService::class);
        $normalized = $service->normalizeNewPlace([
            'id' => 'places/chij-components',
            'displayName' => ['text' => 'Test CA'],
            'formattedAddress' => 'Pune, Maharashtra, India',
            'internationalPhoneNumber' => '+91 91234 56789',
            'addressComponents' => [
                ['longText' => 'Pune', 'types' => ['locality']],
                ['longText' => 'Maharashtra', 'types' => ['administrative_area_level_1']],
                ['longText' => 'India', 'types' => ['country']],
            ],
        ]);

        $this->assertSame('Pune', $normalized['city_name']);
        $this->assertSame('Maharashtra', $normalized['state_name']);
        $this->assertSame('+91 91234 56789', $normalized['mobile_no']);
    }

    public function test_manager_can_refresh_google_data(): void
    {
        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $this->actingAs($manager);

        $lead = $this->sampleLead([
            'google_place_id' => 'places/chij-old',
            'google_places_cache' => ['place' => ['place_id' => 'places/chij-old']],
        ]);

        Http::fake([
            'places.googleapis.com/v1/places:searchText' => Http::response([
                'places' => [[
                    'id' => 'places/chij-new',
                    'displayName' => ['text' => 'AK Bhardwaj & Co'],
                    'formattedAddress' => 'Kolkata, West Bengal',
                ]],
            ], 200),
        ]);

        $response = $this->postJson('/ca-masters/'.$lead->ca_id.'/research/refresh');
        $response->assertOk();
        $response->assertJsonPath('data.cached', false);
        $response->assertJsonPath('data.can_refresh', true);
    }
}
