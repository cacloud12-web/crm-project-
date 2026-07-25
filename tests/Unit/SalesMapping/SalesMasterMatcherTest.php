<?php

namespace Tests\Unit\SalesMapping;

use App\Models\CaMaster;
use App\Models\City;
use App\Services\Mapping\DataNormalizationService;
use App\Services\Master\LookupResolverService;
use App\Services\SalesMapping\SalesMasterMatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesMasterMatcherTest extends TestCase
{
    use DatabaseTransactions;

    private function matcher(): SalesMasterMatcher
    {
        return new SalesMasterMatcher(
            new DataNormalizationService,
            app(LookupResolverService::class),
        );
    }

    private function skipUnlessReady(): void
    {
        if (! Schema::hasTable('ca_masters')) {
            $this->markTestSkipped('ca_masters missing');
        }
    }

    public function test_perfect_firm_ca_city_match(): void
    {
        $this->skipUnlessReady();
        $cityId = City::query()->value('city_id');
        $cityName = City::query()->where('city_id', $cityId)->value('city_name') ?: 'Jaipur';

        $master = CaMaster::query()->create([
            'ca_name' => 'Perfect Match CA',
            'firm_name' => 'Perfect Match Firm LLP',
            'normalized_ca_name' => 'PERFECT MATCH CA',
            'normalized_firm_name' => 'PERFECT MATCH FIRM LLP',
            'city_id' => $cityId,
            'mobile_no' => '9811111111',
            'status' => 'New',
            'rating' => 1,
        ]);

        $result = $this->matcher()->match([
            'ca_name' => 'Perfect Match CA',
            'firm_name' => 'Perfect Match Firm LLP',
            'city_name' => $cityName,
            'mobile_no' => '9811111111',
        ]);

        $this->assertSame('matched', $result['status']);
        $this->assertSame((int) $master->ca_id, (int) $result['ca_id']);
        $this->assertSame(SalesMasterMatcher::TIER_FIRM_CA_CITY, $result['match_tier']);
        $this->assertGreaterThanOrEqual(0.90, (float) $result['score']);
    }

    public function test_multiple_candidates_need_review(): void
    {
        $this->skipUnlessReady();
        $cityId = City::query()->value('city_id');
        $cityName = City::query()->where('city_id', $cityId)->value('city_name') ?: 'Jaipur';
        $suffix = uniqid();

        CaMaster::query()->create([
            'ca_name' => 'Twin CA '.$suffix,
            'firm_name' => 'Twin Firm '.$suffix,
            'normalized_ca_name' => 'TWIN CA '.$suffix,
            'normalized_firm_name' => 'TWIN FIRM '.$suffix,
            'city_id' => $cityId,
            'status' => 'New',
            'rating' => 1,
        ]);
        CaMaster::query()->create([
            'ca_name' => 'Twin CA '.$suffix,
            'firm_name' => 'Twin Firm '.$suffix,
            'normalized_ca_name' => 'TWIN CA '.$suffix,
            'normalized_firm_name' => 'TWIN FIRM '.$suffix,
            'city_id' => $cityId,
            'status' => 'New',
            'rating' => 1,
        ]);

        $result = $this->matcher()->match([
            'ca_name' => 'Twin CA '.$suffix,
            'firm_name' => 'Twin Firm '.$suffix,
            'city_name' => $cityName,
        ]);

        $this->assertSame('needs_review', $result['status']);
        $this->assertNull($result['ca_id']);
        $this->assertGreaterThanOrEqual(2, count($result['candidates']));
    }

    public function test_no_match_returns_unmatched(): void
    {
        $this->skipUnlessReady();

        $result = $this->matcher()->match([
            'ca_name' => 'Nobody '.uniqid(),
            'firm_name' => 'No Firm '.uniqid(),
            'city_name' => 'Nowhereville',
            'mobile_no' => '9000000000',
            'email' => 'nobody-'.uniqid().'@example.test',
        ]);

        $this->assertSame('unmatched', $result['status']);
        $this->assertNull($result['ca_id']);
        $this->assertSame([], $result['candidates']);
    }

    public function test_email_tier_match(): void
    {
        $this->skipUnlessReady();
        $email = 'sales-map-'.uniqid().'@example.test';

        $master = CaMaster::query()->create([
            'ca_name' => 'Email CA',
            'firm_name' => 'Email Firm',
            'email_id' => $email,
            'normalized_email' => $email,
            'status' => 'New',
            'rating' => 1,
        ]);

        $result = $this->matcher()->match([
            'ca_name' => 'Different Name',
            'firm_name' => 'Different Firm',
            'city_name' => null,
            'email' => $email,
        ]);

        $this->assertSame('matched', $result['status']);
        $this->assertSame((int) $master->ca_id, (int) $result['ca_id']);
        $this->assertSame(SalesMasterMatcher::TIER_EMAIL, $result['match_tier']);
    }
}
