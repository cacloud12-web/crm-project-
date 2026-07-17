<?php

namespace Tests\Unit\Mapping;

use App\Models\CaMaster;
use App\Models\State;
use App\Services\Mapping\MasterDataMatchingService;
use App\Services\Mapping\MatchResult;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StateFirmCaMatchingProfileTest extends TestCase
{
    use DatabaseTransactions;

    public function test_exact_state_firm_ca_match(): void
    {
        $stateId = State::query()->value('state_id');
        $this->assertNotNull($stateId);

        $lead = CaMaster::query()->create([
            'ca_name' => 'Suresh Patel',
            'firm_name' => 'Patel & Co',
            'normalized_firm_name' => 'PATEL & CO',
            'normalized_ca_name' => Schema::hasColumn('ca_masters', 'normalized_ca_name') ? 'SURESH PATEL' : null,
            'state_id' => $stateId,
            'status' => 'New',
            'rating' => 1,
        ]);

        $matching = app(MasterDataMatchingService::class);
        $payload = $matching->normalizePayload([
            'state_id' => $stateId,
            'firm_name' => 'Patel and Company',
            'ca_name' => 'Suresh Patel',
            'mobile_no' => '9999900001',
        ]);
        $index = $matching->buildIndex([$payload], MasterDataMatchingService::PROFILE_STATE_FIRM_CA);
        $result = $matching->match($payload, $index, MasterDataMatchingService::PROFILE_STATE_FIRM_CA);

        $this->assertTrue($result->isExact());
        $this->assertSame((int) $lead->ca_id, $result->caId);
        $this->assertSame('state_firm_ca_exact', $result->matchedOn);
    }

    public function test_ca_name_order_variant_still_matches(): void
    {
        $stateId = State::query()->value('state_id');
        $lead = CaMaster::query()->create([
            'ca_name' => 'Kumar Raj',
            'firm_name' => 'Raj Tax Bureau',
            'normalized_firm_name' => 'RAJ TAX BUREAU',
            'normalized_ca_name' => Schema::hasColumn('ca_masters', 'normalized_ca_name') ? 'KUMAR RAJ' : null,
            'state_id' => $stateId,
            'status' => 'New',
            'rating' => 1,
        ]);

        $matching = app(MasterDataMatchingService::class);
        $payload = $matching->normalizePayload([
            'state_id' => $stateId,
            'firm_name' => 'Raj Tax Bureau',
            'ca_name' => 'Raj Kumar',
        ]);
        $index = $matching->buildIndex([$payload], MasterDataMatchingService::PROFILE_STATE_FIRM_CA);
        $result = $matching->match($payload, $index, MasterDataMatchingService::PROFILE_STATE_FIRM_CA);

        $this->assertContains($result->status, [MatchResult::STATUS_EXACT, MatchResult::STATUS_POSSIBLE]);
        $this->assertSame((int) $lead->ca_id, $result->caId);
    }
}
