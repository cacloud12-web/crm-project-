<?php

namespace Tests\Feature;

use App\Models\CaFirm;
use App\Models\CaMaster;
use App\Models\CaPartner;
use App\Models\LeadPhoneNumber;
use App\Models\MasterMappingDecision;
use App\Models\State;
use App\Services\Mapping\MasterDataMappingService;
use App\Services\Mapping\MasterDataMatchingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesTeamMasterMappingTest extends TestCase
{
    use DatabaseTransactions;

    private ?int $stateId = null;

    private ?int $otherStateId = null;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'crm_mapping.profiles.state_firm_ca.auto_create_unmatched' => false,
            'crm_mapping.profiles.state_firm_ca.auto_update_min' => 0.90,
            'crm_mapping.profiles.state_firm_ca.review_min' => 0.70,
        ]);
        $this->stateId = State::query()->value('state_id');
        $this->otherStateId = State::query()->where('state_id', '!=', $this->stateId)->value('state_id')
            ?: $this->stateId;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function createMaster(array $attrs): CaMaster
    {
        $base = [
            'status' => 'New',
            'rating' => 1,
        ];
        if (Schema::hasColumn('ca_masters', 'normalized_ca_name') && isset($attrs['ca_name']) && ! array_key_exists('normalized_ca_name', $attrs)) {
            $attrs['normalized_ca_name'] = mb_strtoupper((string) app(\App\Services\Mapping\DataNormalizationService::class)->caName($attrs['ca_name']));
        }
        if (Schema::hasColumn('ca_masters', 'normalized_firm_name') && isset($attrs['firm_name']) && ! array_key_exists('normalized_firm_name', $attrs)) {
            $attrs['normalized_firm_name'] = mb_strtoupper((string) app(\App\Services\Mapping\DataNormalizationService::class)->firmName($attrs['firm_name']));
        }
        foreach (['normalized_ca_name', 'normalized_state', 'normalized_firm_name', 'normalized_mobile', 'field_confidence'] as $col) {
            if (array_key_exists($col, $attrs) && ! Schema::hasColumn('ca_masters', $col)) {
                unset($attrs[$col]);
            }
        }

        return CaMaster::query()->create(array_merge($base, $attrs));
    }

    public function test_same_state_equivalent_firm_and_ca_maps_and_adds_mobile(): void
    {
        $master = $this->createMaster([
            'ca_name' => 'Arvind Kumar',
            'firm_name' => 'Arvind and Company',
            'normalized_firm_name' => 'ARVIND & CO',
            'state_id' => $this->stateId,
            'mobile_no' => null,
        ]);

        $stats = app(MasterDataMappingService::class)->processBatch(
            'sales_team',
            'sales-test-1',
            [[
                'state_id' => $this->stateId,
                'firm_name' => 'M/s Arvind & Co.',
                'ca_name' => 'ARVIND KUMAR',
                'mobile_no' => '98765 43210',
            ]],
            null,
            ['matchingProfile' => 'state_firm_ca'],
        );

        $this->assertSame(1, $stats['auto_updated']);
        $this->assertSame(MasterDataMatchingService::PROFILE_STATE_FIRM_CA, $stats['matching_profile']);
        $master->refresh();
        $this->assertSame((int) $master->ca_id, (int) ($stats['decisions'][0]['ca_id'] ?? 0));
        $this->assertSame('9876543210', preg_replace('/\D+/', '', (string) $master->mobile_no));
        $this->assertSame('Arvind and Company', $master->firm_name, 'Official firm name must be preserved');
    }

    public function test_reimport_is_idempotent_for_firm_and_mobile(): void
    {
        $master = $this->createMaster([
            'ca_name' => 'Neha Shah',
            'firm_name' => 'Neha Shah & Associates',
            'normalized_firm_name' => 'NEHA SHAH & ASSOCIATES',
            'state_id' => $this->stateId,
            'mobile_no' => '9123456789',
            'normalized_mobile' => '9123456789',
        ]);
        $beforeCount = CaMaster::query()->count();

        $service = app(MasterDataMappingService::class);
        $row = [
            'state_id' => $this->stateId,
            'firm_name' => 'Neha Shah and Associates',
            'ca_name' => 'Neha Shah',
            'mobile_no' => '9123456789',
        ];
        $first = $service->processSalesTeamBatch([$row], 'sales-idem-1');
        $second = $service->processSalesTeamBatch([$row], 'sales-idem-2');

        $this->assertSame(1, $first['auto_updated']);
        $this->assertSame(1, $second['auto_updated']);
        $this->assertSame($beforeCount, CaMaster::query()->count());
        $master->refresh();
        $this->assertSame('9123456789', $master->normalized_mobile ?: preg_replace('/\D+/', '', (string) $master->mobile_no));
        $this->assertSame('already_present', $second['decisions'][0]['mobile_action'] ?? null);
    }

    public function test_same_firm_name_different_state_does_not_auto_map(): void
    {
        if ($this->otherStateId === $this->stateId) {
            $this->markTestSkipped('Need two states in lookup table.');
        }

        $this->createMaster([
            'ca_name' => 'Ravi Mehta',
            'firm_name' => 'Mehta & Co',
            'normalized_firm_name' => 'MEHTA & CO',
            'state_id' => $this->stateId,
        ]);

        $stats = app(MasterDataMappingService::class)->processSalesTeamBatch([[
            'state_id' => $this->otherStateId,
            'firm_name' => 'Mehta & Co',
            'ca_name' => 'Ravi Mehta',
            'mobile_no' => '9000011122',
        ]], 'sales-cross-state');

        $this->assertSame(0, $stats['auto_updated']);
        $this->assertGreaterThanOrEqual(1, $stats['needs_review'] + $stats['skipped']);
    }

    public function test_common_ca_name_alone_does_not_auto_map(): void
    {
        $this->createMaster([
            'ca_name' => 'Amit Sharma',
            'firm_name' => 'Alpha Tax Advisors',
            'normalized_firm_name' => 'ALPHA TAX ADVISORS',
            'state_id' => $this->stateId,
        ]);
        $this->createMaster([
            'ca_name' => 'Amit Sharma',
            'firm_name' => 'Beta Consultants',
            'normalized_firm_name' => 'BETA CONSULTANTS',
            'state_id' => $this->stateId,
        ]);

        $stats = app(MasterDataMappingService::class)->processSalesTeamBatch([[
            'state_id' => $this->stateId,
            'firm_name' => null,
            'ca_name' => 'Amit Sharma',
            'mobile_no' => '9888877766',
        ]], 'sales-ca-only');

        $this->assertSame(0, $stats['auto_updated']);
        $this->assertSame(0, $stats['auto_created']);
        $this->assertGreaterThanOrEqual(1, $stats['needs_review']);
    }

    public function test_multiple_similar_candidates_become_conflict(): void
    {
        $this->createMaster([
            'ca_name' => 'Pooja Jain',
            'firm_name' => 'Jain Associates LLP',
            'normalized_firm_name' => 'JAIN ASSOCIATES LLP',
            'state_id' => $this->stateId,
        ]);
        $this->createMaster([
            'ca_name' => 'Pooja Jain',
            'firm_name' => 'Jain Associates LLP',
            'normalized_firm_name' => 'JAIN ASSOCIATES LLP',
            'state_id' => $this->stateId,
        ]);

        $stats = app(MasterDataMappingService::class)->processSalesTeamBatch([[
            'state_id' => $this->stateId,
            'firm_name' => 'Jain Associates LLP',
            'ca_name' => 'Pooja Jain',
            'mobile_no' => '9777766655',
        ]], 'sales-conflict');

        $this->assertSame(1, $stats['conflicts']);
        $this->assertSame(0, $stats['auto_updated']);
    }

    public function test_official_values_not_overwritten_by_weaker_sales_data(): void
    {
        $master = $this->createMaster([
            'ca_name' => 'Official Partner Name',
            'firm_name' => 'Official Firm Name LLP',
            'normalized_firm_name' => 'OFFICIAL FIRM NAME LLP',
            'state_id' => $this->stateId,
            'address' => '12 Official Road',
            'mobile_no' => null,
        ]);

        app(MasterDataMappingService::class)->processSalesTeamBatch([[
            'state_id' => $this->stateId,
            'firm_name' => 'Official Firm Name LLP',
            'ca_name' => 'Official Partner Name',
            'mobile_no' => '9666655544',
            'address' => 'Wrong OCR Street',
        ]], 'sales-no-overwrite');

        $master->refresh();
        $this->assertSame('Official Firm Name LLP', $master->firm_name);
        $this->assertSame('Official Partner Name', $master->ca_name);
        $this->assertSame('12 Official Road', $master->address);
        $this->assertNotNull($master->mobile_no);
    }

    public function test_mobile_owned_by_another_master_is_conflict(): void
    {
        $mobile = '9'.str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT);

        $owner = $this->createMaster([
            'ca_name' => 'Owner CA',
            'firm_name' => 'Owner Firm',
            'normalized_firm_name' => 'OWNER FIRM',
            'state_id' => $this->stateId,
            'mobile_no' => $mobile,
            'normalized_mobile' => $mobile,
        ]);
        if (Schema::hasTable('lead_phone_numbers')) {
            LeadPhoneNumber::query()->updateOrCreate(
                ['normalized_number' => $mobile],
                ['ca_id' => $owner->ca_id, 'phone_type' => 'mobile_no'],
            );
        }

        $target = $this->createMaster([
            'ca_name' => 'Target CA',
            'firm_name' => 'Target Firm & Co',
            'normalized_firm_name' => 'TARGET FIRM & CO',
            'state_id' => $this->stateId,
        ]);

        $stats = app(MasterDataMappingService::class)->processSalesTeamBatch([[
            'state_id' => $this->stateId,
            'firm_name' => 'Target Firm & Co',
            'ca_name' => 'Target CA',
            'mobile_no' => $mobile,
        ]], 'sales-mobile-conflict');

        $this->assertSame(1, $stats['conflicts']);
        $target->refresh();
        $this->assertNull($target->mobile_no);
        $this->assertSame($mobile, $owner->fresh()->normalized_mobile);
    }

    public function test_ca_name_matches_existing_partners(): void
    {
        try {
            if (! Schema::connection('ca_reference')->hasTable('ca_firms')
                || ! Schema::connection('ca_reference')->hasTable('ca_partners')) {
                $this->markTestSkipped('ca_reference partners unavailable');
            }
        } catch (\Throwable) {
            $this->markTestSkipped('ca_reference connection unavailable');
        }

        $frn = 'STFRN'.random_int(100000, 999999);
        $refFirm = CaFirm::query()->create([
            'firm_name' => 'Partner Match Firm LLP',
            'frn' => $frn,
            'status' => 'active',
        ]);
        CaPartner::query()->create([
            'firm_id' => $refFirm->id,
            'partner_name' => 'Secondary Partner Rao',
            'status' => 'active',
        ]);

        $master = $this->createMaster([
            'ca_name' => 'Primary Partner Rao',
            'firm_name' => 'Partner Match Firm LLP',
            'normalized_firm_name' => 'PARTNER MATCH FIRM LLP',
            'state_id' => $this->stateId,
            'frn' => $frn,
            'mobile_no' => null,
        ]);

        $stats = app(MasterDataMappingService::class)->processSalesTeamBatch([[
            'state_id' => $this->stateId,
            'firm_name' => 'Partner Match Firm LLP',
            'ca_name' => 'Secondary Partner Rao',
            'mobile_no' => '9333322211',
        ]], 'sales-partner-match');

        $this->assertSame(1, $stats['auto_updated']);
        $master->refresh();
        $this->assertSame('Primary Partner Rao', $master->ca_name, 'Official primary CA name preserved');
        $this->assertSame('9333322211', preg_replace('/\D+/', '', (string) $master->mobile_no));
    }

    public function test_audit_decision_stores_sales_meta(): void
    {
        if (! Schema::hasTable('master_mapping_decisions')) {
            $this->markTestSkipped('master_mapping_decisions missing');
        }

        $this->createMaster([
            'ca_name' => 'Audit CA',
            'firm_name' => 'Audit Firm & Co',
            'normalized_firm_name' => 'AUDIT FIRM & CO',
            'state_id' => $this->stateId,
        ]);

        app(MasterDataMappingService::class)->processSalesTeamBatch([[
            'state' => State::query()->where('state_id', $this->stateId)->value('state_name'),
            'state_id' => $this->stateId,
            'firm_name' => 'Audit Firm and Company',
            'ca_name' => 'Audit CA',
            'mobile_no' => '9444433322',
        ]], 'sales-audit');

        $log = MasterMappingDecision::query()->where('source_ref', 'sales-audit')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(MasterMappingDecision::DECISION_AUTO_UPDATE, $log->decision);
        if (Schema::hasColumn('master_mapping_decisions', 'decision_meta')) {
            $this->assertSame('state_firm_ca', $log->decision_meta['matching_profile'] ?? null);
            $this->assertArrayHasKey('imported', $log->decision_meta ?? []);
            $this->assertArrayHasKey('mobile_action', $log->decision_meta ?? []);
        }
    }
}
