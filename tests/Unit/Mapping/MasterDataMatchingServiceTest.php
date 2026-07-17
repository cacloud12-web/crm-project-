<?php

namespace Tests\Unit\Mapping;

use App\Models\CaMaster;
use App\Services\Mapping\MasterDataMatchingService;
use App\Services\Mapping\MatchResult;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MasterDataMatchingServiceTest extends TestCase
{
    use DatabaseTransactions;

    private MasterDataMatchingService $matching;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matching = app(MasterDataMatchingService::class);
    }

    public function test_normalize_payload_collapses_firm_name_variants(): void
    {
        $payload = $this->matching->normalizePayload([
            'firm_name' => 'Arvind and Company',
            'phone' => '+91 98765 43210',
            'email' => 'Info@Example.COM',
            'gst_no' => '27AAAAA0000A1Z5',
        ]);

        $this->assertSame('ARVIND & CO', mb_strtoupper((string) $payload['normalized_firm_name']));
        $this->assertSame('9876543210', $payload['normalized_mobile']);
        $this->assertSame('info@example.com', $payload['normalized_email']);
        $this->assertSame('27AAAAA0000A1Z5', $payload['gst_no']);
    }

    public function test_exact_gst_match_via_batch_index(): void
    {
        $lead = CaMaster::query()->create([
            'ca_name' => 'Existing Partner',
            'firm_name' => 'Existing Firm LLP',
            'normalized_firm_name' => 'EXISTING FIRM LLP',
            'gst_no' => '29BBBBB1111B1Z5',
            'mobile_no' => null,
            'status' => 'New',
            'rating' => 1,
        ]);

        $payload = $this->matching->normalizePayload([
            'firm_name' => 'Different Name',
            'gst_no' => '29BBBBB1111B1Z5',
        ]);
        $index = $this->matching->buildIndex([$payload]);
        $result = $this->matching->match($payload, $index);

        $this->assertTrue($result->isExact());
        $this->assertSame((int) $lead->ca_id, $result->caId);
        $this->assertSame('gst', $result->matchedOn);
        $this->assertSame(MatchResult::STATUS_EXACT, $result->status);
    }

    public function test_alternate_mobile_matches_existing_primary_phone(): void
    {
        $lead = CaMaster::query()->create([
            'ca_name' => 'Phone Partner',
            'firm_name' => 'Phone Match Firm',
            'normalized_firm_name' => 'PHONE MATCH FIRM',
            'mobile_no' => '9876500011',
            'normalized_mobile' => '9876500011',
            'status' => 'New',
            'rating' => 1,
        ]);

        $payload = $this->matching->normalizePayload([
            'firm_name' => 'Other Name Entirely',
            'alternate_mobile_no' => '98765 00011',
        ]);
        $index = $this->matching->buildIndex([$payload]);
        $result = $this->matching->match($payload, $index);

        $this->assertTrue($result->isExact());
        $this->assertSame((int) $lead->ca_id, $result->caId);
        $this->assertSame('alternate_mobile', $result->matchedOn);
    }

    public function test_conflict_when_two_exact_identifier_hits(): void
    {
        CaMaster::query()->create([
            'ca_name' => 'A',
            'firm_name' => 'Firm A',
            'gst_no' => '29CCCCC2222C1Z5',
            'frn' => 'FRN9001',
            'status' => 'New',
            'rating' => 1,
        ]);
        CaMaster::query()->create([
            'ca_name' => 'B',
            'firm_name' => 'Firm B',
            'gst_no' => '29DDDDD3333D1Z5',
            'frn' => 'FRN9001',
            'status' => 'New',
            'rating' => 1,
        ]);

        // Same FRN on two masters → conflict when matching by FRN
        $payload = $this->matching->normalizePayload([
            'firm_name' => 'Incoming',
            'frn' => 'FRN9001',
        ]);
        $index = $this->matching->buildIndex([$payload]);
        $result = $this->matching->match($payload, $index);

        $this->assertTrue($result->isConflict());
        $this->assertCount(2, $result->candidates);
    }

    public function test_unmatched_when_no_identifiers_or_firm_overlap(): void
    {
        $payload = $this->matching->normalizePayload([
            'firm_name' => 'Completely Unique New Firm XYZ 999',
        ]);
        $index = $this->matching->buildIndex([$payload]);
        $result = $this->matching->match($payload, $index);

        $this->assertTrue($result->isUnmatched());
    }
}
