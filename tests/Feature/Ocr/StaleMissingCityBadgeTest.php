<?php

namespace Tests\Feature\Ocr;

use App\Models\CaMaster;
use App\Models\City;
use App\Models\State;
use App\Models\User;
use App\Support\Ocr\CaMasterCityQuality;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StaleMissingCityBadgeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasColumn('ca_masters', 'data_quality_issue')) {
            $this->markTestSkipped('data_quality_issue column missing');
        }
    }

    public function test_resource_hides_missing_city_when_city_is_linked(): void
    {
        $user = User::query()->where('email', 'superadmin@ca.local')->first()
            ?? User::factory()->create(['crm_role' => 'super_admin', 'is_active' => true]);

        $stateId = State::query()->value('state_id') ?? State::query()->insertGetId([
            'state_name' => 'Test State '.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'state_id');

        $cityId = City::query()->insertGetId([
            'city_name' => 'Abohar Test '.uniqid(),
            'state_id' => $stateId,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'city_id');

        $master = CaMaster::query()->create([
            'firm_name' => 'SETIA TEST FIRM',
            'ca_name' => 'SUNIL SETIA',
            'city_id' => $cityId,
            'verification_status' => 'needs_verification',
            'data_quality_issue' => 'missing_city',
            'source_type' => 'ocr',
            'is_verified' => false,
            'status' => 'New',
        ]);

        $this->actingAs($user);
        $response = $this->getJson('/ca-masters/'.$master->ca_id);
        if ($response->status() === 404) {
            $response = $this->getJson('/api/ca-masters/'.$master->ca_id);
        }

        $response->assertOk();
        $issue = $response->json('data.data_quality_issue') ?? $response->json('data_quality_issue');
        $this->assertNull($issue);
        $city = $response->json('data.city') ?? $response->json('city');
        $this->assertNotEmpty($city);
    }

    public function test_helper_keeps_missing_city_for_placeholder(): void
    {
        $master = new CaMaster([
            'city_id' => 999999,
            'ca_name' => 'Someone',
            'data_quality_issue' => 'missing_city',
        ]);
        $master->setRelation('city', new City([
            'city_id' => 999999,
            'city_name' => CaMasterCityQuality::PLACEHOLDER_CITY_NAME,
        ]));

        $this->assertSame(
            'missing_city',
            CaMasterCityQuality::effectiveDataQualityIssue($master, CaMasterCityQuality::PLACEHOLDER_CITY_NAME)
        );
    }

    public function test_reconcile_command_clears_stale_flags(): void
    {
        $stateId = State::query()->value('state_id');
        if (! $stateId) {
            $this->markTestSkipped('No states');
        }

        $cityId = City::query()->insertGetId([
            'city_name' => 'Jaipur Recon '.uniqid(),
            'state_id' => $stateId,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'city_id');

        $master = CaMaster::query()->create([
            'firm_name' => 'RECON FIRM',
            'ca_name' => 'RECON CA',
            'city_id' => $cityId,
            'verification_status' => 'needs_verification',
            'data_quality_issue' => 'missing_city',
            'source_type' => 'ocr',
            'is_verified' => false,
            'status' => 'New',
        ]);

        $this->artisan('ocr:reconcile-stale-missing-city-flags')->assertSuccessful();

        $master->refresh();
        $this->assertNull($master->data_quality_issue);
    }
}
