<?php

namespace Tests\Unit;

use App\Models\CaMaster;
use App\Models\User;
use App\Services\Leads\CaMasterService;
use App\Support\Listing\ListingQueryApplier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class CaMasterListingFiltersTest extends TestCase
{
    use DatabaseTransactions;

    private array $seededIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        Auth::login($admin);

        $ts = (string) microtime(true);
        foreach ([
            ['firm_name' => 'FilterTest Low '.$ts, 'rating' => 2, 'team_size' => 3],
            ['firm_name' => 'FilterTest Mid '.$ts, 'rating' => 4, 'team_size' => 8],
            ['firm_name' => 'FilterTest High '.$ts, 'rating' => 5, 'team_size' => 15],
        ] as $i => $row) {
            $lead = CaMaster::query()->create([
                'firm_name' => $row['firm_name'],
                'ca_name' => 'Filter CA '.$i,
                'mobile_no' => '5'.substr(str_replace('.', '', $ts), -9).$i,
                'email_id' => "filter{$i}_{$ts}@test.local",
                'rating' => $row['rating'],
                'team_size' => $row['team_size'],
                'status' => $i === 2 ? 'Hot' : 'New',
            ]);
            $this->seededIds[] = $lead->ca_id;
        }
    }

    public function test_rating_min_filter_maps_to_rating_column(): void
    {
        $result = $this->search(['rating_min' => 4, 'search' => 'FilterTest']);

        $this->assertGreaterThanOrEqual(2, $result['pagination']['total']);
        foreach ($result['items'] as $item) {
            $this->assertGreaterThanOrEqual(4, (int) $item->rating);
        }
    }

    public function test_rating_max_filter_maps_to_rating_column(): void
    {
        $result = $this->search(['rating_max' => 4, 'search' => 'FilterTest']);

        $this->assertGreaterThanOrEqual(2, $result['pagination']['total']);
        foreach ($result['items'] as $item) {
            $this->assertLessThanOrEqual(4, (int) $item->rating);
        }
    }

    public function test_rating_min_and_max_combined(): void
    {
        $result = $this->search([
            'rating_min' => 4,
            'rating_max' => 4,
            'search' => 'FilterTest Mid',
        ]);

        $this->assertSame(1, $result['pagination']['total']);
        $this->assertSame(4, (int) $result['items'][0]->rating);
    }

    public function test_team_size_filters_still_work(): void
    {
        $result = $this->search([
            'team_size_min' => 5,
            'team_size_max' => 10,
            'search' => 'FilterTest Mid',
        ]);

        $this->assertSame(1, $result['pagination']['total']);
        $this->assertSame(8, (int) $result['items'][0]->team_size);
    }

    public function test_rating_and_team_size_filters_together(): void
    {
        $result = $this->search([
            'rating_min' => 4,
            'team_size_min' => 10,
            'search' => 'FilterTest High',
        ]);

        $this->assertSame(1, $result['pagination']['total']);
        $this->assertSame(5, (int) $result['items'][0]->rating);
        $this->assertSame(15, (int) $result['items'][0]->team_size);
    }

    public function test_status_exact_filter_still_works(): void
    {
        $result = $this->search(['status' => 'Hot', 'search' => 'FilterTest High']);

        $this->assertSame(1, $result['pagination']['total']);
        $this->assertSame('Hot', $result['items'][0]->status);
    }

    public function test_listing_applier_does_not_throw_for_rating_filters(): void
    {
        $config = ListingQueryApplier::config('ca_masters');
        $query = CaMaster::query()->where('firm_name', 'like', 'FilterTest%');

        $result = ListingQueryApplier::apply($query, [
            'rating_min' => 1,
            'rating_max' => 5,
            'team_size_min' => 1,
            'team_size_max' => 50,
            'per_page' => 25,
        ], $config);

        $this->assertGreaterThanOrEqual(3, $result['pagination']['total']);
    }

    private function search(array $params): array
    {
        return app(CaMasterService::class)->search(array_merge(['per_page' => 25], $params));
    }
}
