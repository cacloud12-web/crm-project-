<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Models\CaMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleIntegrationSearchTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.places_api_key' => 'test-google-key',
            'services.google.maps_api_key' => 'test-google-key',
            'crm_research.google_places_api_key' => 'test-google-key',
        ]);
    }

    private function actingAsAdmin(): User
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        return $admin;
    }

    private function sampleLead(array $overrides = []): CaMaster
    {
        return CaMaster::query()->create(array_merge([
            'ca_name' => 'Example CA',
            'firm_name' => 'Example Firm & Co',
            'status' => 'New',
        ], $overrides));
    }

    public function test_google_places_search_endpoint_returns_formatted_results(): void
    {
        $this->actingAsAdmin();
        $lead = $this->sampleLead();

        Http::fake([
            'places.googleapis.com/v1/places:searchText' => Http::response([
                'places' => [
                    [
                        'id' => 'places/chij-one',
                        'displayName' => ['text' => 'Example Firm & Co Chartered Accountants'],
                        'formattedAddress' => 'Kolkata, West Bengal, India',
                        'rating' => 4.6,
                        'userRatingCount' => 12,
                        'businessStatus' => 'OPERATIONAL',
                        'googleMapsUri' => 'https://maps.google.com/?cid=1',
                        'location' => ['latitude' => 22.57, 'longitude' => 88.36],
                        'internationalPhoneNumber' => '+91 98765 43210',
                        'websiteUri' => 'https://example.com',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/google/places/search?ca_id='.$lead->ca_id);
        $response->assertOk();
        $response->assertJsonPath('data.status', 'OK');
        $response->assertJsonPath('data.results.0.place_id', 'places/chij-one');
        $response->assertJsonPath('data.results.0.business_name', 'Example Firm & Co Chartered Accountants');
        $response->assertJsonPath('data.results.0.phone', '+91 98765 43210');
        $response->assertJsonPath('data.results.0.website', 'https://example.com');
        $response->assertJsonPath('data.results.0.rating', 4.6);
        $response->assertJsonPath('data.results.0.latitude', 22.57);
        $response->assertJsonPath('data.results.0.longitude', 88.36);
        $this->assertStringContainsString('Chartered Accountant', (string) $response->json('data.query'));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://places.googleapis.com/v1/places:searchText'
                && $request->hasHeader('X-Goog-FieldMask')
                && ! $request->hasHeader('X-Goog-Api-FieldMask')
                && filled($request->header('X-Goog-FieldMask')[0] ?? '');
        });
    }

    public function test_google_places_search_handles_missing_api_key(): void
    {
        $this->actingAsAdmin();
        config(['services.google.places_api_key' => '']);

        $response = $this->getJson('/google/places/search?firm_name=Test&ca_name=CA');
        $response->assertOk();
        $response->assertJsonPath('data.status', 'MISSING_API_KEY');
        $response->assertJsonPath('data.api_error', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_google_places_search_handles_no_results(): void
    {
        $this->actingAsAdmin();

        Http::fake([
            'places.googleapis.com/v1/places:searchText' => Http::response(['places' => []], 200),
        ]);

        $response = $this->getJson('/google/places/search?firm_name=Unknown&ca_name=Firm&city=Mumbai&state=Maharashtra');
        $response->assertOk();
        $response->assertJsonPath('data.status', 'ZERO_RESULTS');
        $response->assertJsonPath('data.results', []);
    }

    public function test_google_places_search_handles_invalid_api_key(): void
    {
        $this->actingAsAdmin();

        Http::fake([
            'places.googleapis.com/v1/places:searchText' => Http::response([
                'error' => [
                    'code' => 403,
                    'message' => 'The provided API key is invalid.',
                    'status' => 'PERMISSION_DENIED',
                ],
            ], 403),
        ]);

        $response = $this->getJson('/google/places/search?firm_name=Test&ca_name=CA');
        $response->assertOk();
        $response->assertJsonPath('data.status', 'API_KEY_INVALID');
        $response->assertJsonPath('data.api_error', fn ($value) => is_string($value) && $value !== '');
    }
}
