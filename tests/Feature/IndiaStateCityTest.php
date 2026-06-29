<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\State;
use App\Models\User;
use Database\Seeders\IndiaStatesCitiesSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class IndiaStateCityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IndiaStatesCitiesSeeder::class);
    }

    public function test_all_states_and_union_territories_are_seeded(): void
    {
        $dataset = require database_path('data/india_states_cities.php');
        $this->assertCount(36, $dataset);

        foreach (array_keys($dataset) as $stateName) {
            $this->assertTrue(
                State::query()->where('state_name', $stateName)->exists(),
                'Missing state: '.$stateName,
            );
        }
    }

    public function test_states_api_returns_all_records_sorted(): void
    {
        $response = $this->actingAs($this->admin())->getJson('/states?all=1&sort_by=state_name&sort_dir=asc');
        $response->assertOk();

        $items = $response->json('data.items') ?? $response->json('data') ?? [];
        $this->assertGreaterThanOrEqual(36, count($items));

        $names = collect($items)->pluck('state_name')->all();
        $sorted = $names;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $names);
    }

    public function test_lookup_states_api_returns_flat_array_for_manager(): void
    {
        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();

        $response = $this->actingAs($manager)->getJson('/lookups/states', [
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'application/json',
        ]);
        $response->assertOk()
            ->assertJsonPath('success', true);

        $items = $response->json('data');
        $this->assertIsArray($items);
        $this->assertGreaterThanOrEqual(36, count($items));
        $this->assertArrayHasKey('state_id', $items[0]);
        $this->assertArrayHasKey('state_name', $items[0]);
    }

    public function test_lookup_cities_api_filters_by_state(): void
    {
        $maharashtra = State::query()->where('state_name', 'Maharashtra')->firstOrFail();

        $response = $this->actingAs($this->admin())->getJson('/lookups/cities?state_id='.$maharashtra->state_id);
        $response->assertOk()
            ->assertJsonPath('success', true);

        $names = collect($response->json('data'))->pluck('city_name')->all();
        $this->assertContains('Mumbai', $names);
        $this->assertContains('Pune', $names);
        $this->assertContains('Nagpur', $names);
    }

    public function test_cities_api_filters_by_state_and_includes_major_cities(): void
    {
        $maharashtra = State::query()->where('state_name', 'Maharashtra')->firstOrFail();
        $karnataka = State::query()->where('state_name', 'Karnataka')->firstOrFail();
        $uttarPradesh = State::query()->where('state_name', 'Uttar Pradesh')->firstOrFail();
        $tamilNadu = State::query()->where('state_name', 'Tamil Nadu')->firstOrFail();
        $delhi = State::query()->where('state_name', 'Delhi')->firstOrFail();

        $this->assertCitiesForState($maharashtra->state_id, ['Mumbai', 'Pune', 'Nagpur']);
        $this->assertCitiesForState($karnataka->state_id, ['Bengaluru', 'Mysuru']);
        $this->assertCitiesForState($uttarPradesh->state_id, ['Lucknow', 'Noida', 'Ghaziabad']);
        $this->assertCitiesForState($tamilNadu->state_id, ['Chennai', 'Coimbatore']);
        $this->assertCitiesForState($delhi->state_id, ['New Delhi']);
    }

    public function test_cities_are_unique_within_state(): void
    {
        $duplicates = City::query()
            ->selectRaw('state_id, city_name, COUNT(*) as total')
            ->groupBy('state_id', 'city_name')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $this->assertSame(0, $duplicates);
    }

    public function test_ca_master_rejects_city_state_mismatch(): void
    {
        $maharashtra = State::query()->where('state_name', 'Maharashtra')->firstOrFail();
        $karnataka = State::query()->where('state_name', 'Karnataka')->firstOrFail();
        $bengaluru = City::query()->where('state_id', $karnataka->state_id)->where('city_name', 'Bengaluru')->firstOrFail();

        $response = $this->actingAs($this->admin())->postJson('/ca-masters', [
            'firm_name' => 'Mismatch Test Firm',
            'ca_name' => 'Mismatch CA',
            'mobile_no' => '9999900001',
            'email_id' => 'mismatch.test@ca.local',
            'state_id' => $maharashtra->state_id,
            'city_id' => $bengaluru->city_id,
            'status' => 'New',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['city_id']);
    }

    public function test_employee_rejects_city_state_mismatch(): void
    {
        $maharashtra = State::query()->where('state_name', 'Maharashtra')->firstOrFail();
        $karnataka = State::query()->where('state_name', 'Karnataka')->firstOrFail();
        $bengaluru = City::query()->where('state_id', $karnataka->state_id)->where('city_name', 'Bengaluru')->firstOrFail();

        $response = $this->actingAs($this->admin())->postJson('/employees', [
            'name' => 'Mismatch Employee',
            'email_id' => 'mismatch.employee@ca.local',
            'mobile_no' => '9999900002',
            'state_id' => $maharashtra->state_id,
            'city_id' => $bengaluru->city_id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['city_id']);
    }

    private function assertCitiesForState(int $stateId, array $expectedNames): void
    {
        $response = $this->actingAs($this->admin())->getJson('/cities?all=1&state_id='.$stateId);
        $response->assertOk();

        $payload = $response->json('data');
        $items = is_array($payload) && array_is_list($payload) ? $payload : ($payload['items'] ?? []);
        $names = collect($items)->map(function ($row) {
            return is_array($row) ? ($row['city_name'] ?? null) : ($row['city_name'] ?? null);
        })->filter()->all();

        foreach ($expectedNames as $expected) {
            $this->assertContains($expected, $names, 'Missing city: '.$expected);
        }
    }

    private function admin()
    {
        return User::query()->where('email', 'admin@ca.local')->firstOrFail();
    }
}
