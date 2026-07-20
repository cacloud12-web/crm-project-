<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\CaMasterPartner;
use App\Models\User;
use App\Services\Leads\CaMasterPartnerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CrmTestAccounts;
use Tests\TestCase;

class LeadTeamSizeColumnTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsAdmin(): User
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        return $admin;
    }

    private function stateId(): int
    {
        return (int) CaMaster::query()->whereNotNull('state_id')->value('state_id');
    }

    public function test_new_lead_defaults_team_size_to_zero(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/ca-masters', [
            'ca_name' => 'Default Team CA',
            'firm_name' => 'Default Team Firm '.random_int(1000, 9999),
            'mobile_no' => '9'.random_int(100000000, 999999999),
            'state_id' => $this->stateId(),
            'status' => 'New',
        ]);

        $response->assertCreated()->assertJsonPath('data.team_size', 0);
        $this->assertSame(0, (int) CaMaster::query()->find($response->json('data.ca_id'))->team_size);
    }

    public function test_listing_returns_team_size_field(): void
    {
        $this->actingAsAdmin();

        $lead = CaMaster::query()->create([
            'ca_name' => 'Team Size CA',
            'firm_name' => 'Team Size Firm',
            'mobile_no' => '9'.random_int(100000000, 999999999),
            'state_id' => $this->stateId(),
            'status' => 'New',
            'team_size' => 8,
        ]);

        $this->getJson('/ca-masters/'.$lead->ca_id)
            ->assertOk()
            ->assertJsonPath('data.team_size', 8);
    }

    public function test_null_team_size_in_api_returns_zero(): void
    {
        $this->actingAsAdmin();

        $lead = CaMaster::query()->create([
            'ca_name' => 'Null Team CA',
            'firm_name' => 'Null Team Firm '.random_int(1000, 9999),
            'mobile_no' => '9'.random_int(100000000, 999999999),
            'state_id' => $this->stateId(),
            'status' => 'New',
            'team_size' => null,
        ]);

        $this->getJson('/ca-masters/'.$lead->ca_id)
            ->assertOk()
            ->assertJsonPath('data.team_size', 0);
    }

    public function test_team_size_filter_matches_exact_value(): void
    {
        $this->actingAsAdmin();

        $mobile = '9'.random_int(100000000, 999999999);
        CaMaster::query()->create([
            'ca_name' => 'Filter CA',
            'firm_name' => 'Filter Firm 50',
            'mobile_no' => $mobile,
            'state_id' => $this->stateId(),
            'status' => 'New',
            'team_size' => 50,
        ]);

        $response = $this->getJson('/ca-masters?team_size=50&firm_name=Filter Firm 50');

        $response->assertOk();
        $items = $response->json('data.items') ?? $response->json('data') ?? [];
        $this->assertGreaterThanOrEqual(1, count($items));
        $this->assertSame(50, (int) ($items[0]['team_size'] ?? 0));
    }

    public function test_team_size_filter_matches_zero_records(): void
    {
        $this->actingAsAdmin();

        $mobile = '9'.random_int(100000000, 999999999);
        $lead = CaMaster::query()->create([
            'ca_name' => 'Zero Team CA',
            'firm_name' => 'Zero Team Firm '.random_int(1000, 9999),
            'mobile_no' => $mobile,
            'state_id' => $this->stateId(),
            'status' => 'New',
            'team_size' => null,
        ]);

        $response = $this->getJson('/ca-masters?team_size=not+specified&firm_name='.urlencode((string) $lead->firm_name));

        $response->assertOk();
        $ids = collect($response->json('data.items') ?? $response->json('data') ?? [])->pluck('ca_id')->map(fn ($id) => (int) $id);
        $this->assertTrue($ids->contains((int) $lead->ca_id));
    }

    public function test_team_size_sorts_ascending_and_descending(): void
    {
        $this->actingAsAdmin();

        $prefix = 'SortTS'.random_int(10000, 99999);
        CaMaster::query()->create([
            'ca_name' => $prefix.' Small',
            'firm_name' => $prefix.' Small Firm',
            'mobile_no' => '9'.random_int(100000000, 999999999),
            'state_id' => $this->stateId(),
            'status' => 'New',
            'team_size' => 3,
        ]);
        CaMaster::query()->create([
            'ca_name' => $prefix.' Large',
            'firm_name' => $prefix.' Large Firm',
            'mobile_no' => '9'.random_int(100000000, 999999999),
            'state_id' => $this->stateId(),
            'status' => 'New',
            'team_size' => 120,
        ]);

        $asc = $this->getJson('/ca-masters?sort_by=team_size&sort_dir=asc&firm_name='.$prefix);
        $asc->assertOk();
        $ascSizes = collect($asc->json('data.items') ?? $asc->json('data') ?? [])->pluck('team_size')->map(fn ($v) => (int) $v)->values()->all();
        $this->assertSame([3, 120], $ascSizes);

        $desc = $this->getJson('/ca-masters?sort_by=team_size&sort_dir=desc&firm_name='.$prefix);
        $desc->assertOk();
        $descSizes = collect($desc->json('data.items') ?? $desc->json('data') ?? [])->pluck('team_size')->map(fn ($v) => (int) $v)->values()->all();
        $this->assertSame([120, 3], $descSizes);
    }

    public function test_bulk_export_includes_team_size_and_not_assigned_employees(): void
    {
        $this->actingAsAdmin();

        $mobile = '9'.random_int(100000000, 999999999);
        CaMaster::query()->create([
            'ca_name' => 'Export Team CA',
            'firm_name' => 'Export Team Firm '.random_int(1000, 9999),
            'mobile_no' => $mobile,
            'state_id' => $this->stateId(),
            'status' => 'New',
            'team_size' => 25,
        ]);

        $response = $this->postJson('/ca-masters/bulk-export', [
            'scope' => 'filtered',
            'format' => 'csv',
            'columns' => ['firm_name', 'team_size'],
            'filters' => ['mobile_no' => $mobile],
        ]);

        $response->assertOk();
        $bulkActionId = $response->json('data.bulk_action_id');
        $this->assertNotNull($bulkActionId);

        $path = app(\App\Services\Bulk\BulkCaMasterExportService::class)->downloadPath($bulkActionId)['path'];
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertStringContainsString('Team Size', $contents);
        $this->assertStringContainsString('25', $contents);
        $this->assertStringNotContainsString('Team Members', $contents);
    }

    public function test_manual_team_size_update_persists_stored_value(): void
    {
        $this->actingAsAdmin();

        $lead = CaMaster::query()->create([
            'ca_name' => 'Manual Team CA',
            'firm_name' => 'Manual Team Firm',
            'mobile_no' => '9'.random_int(100000000, 999999999),
            'state_id' => $this->stateId(),
            'status' => 'New',
            'team_size' => 0,
        ]);

        $this->putJson('/ca-masters/'.$lead->ca_id, [
            'ca_name' => $lead->ca_name,
            'firm_name' => $lead->firm_name,
            'state_id' => $lead->state_id,
            'team_size' => 5,
        ])->assertOk()->assertJsonPath('data.team_size', 5);

        $this->assertSame(5, (int) $lead->fresh()->team_size);

        $this->putJson('/ca-masters/'.$lead->ca_id, [
            'ca_name' => $lead->ca_name,
            'firm_name' => $lead->firm_name,
            'state_id' => $lead->state_id,
            'team_size' => 0,
        ])->assertOk()->assertJsonPath('data.team_size', 0);

        $this->assertSame(0, (int) $lead->fresh()->team_size);
    }

    public function test_patch_firm_team_size_updates_only_firm(): void
    {
        $this->actingAsAdmin();

        if (! Schema::hasTable('ca_master_partners')) {
            $this->markTestSkipped('ca_master_partners table missing');
        }

        $lead = CaMaster::query()->create([
            'ca_name' => 'Patch Firm CA',
            'firm_name' => 'Patch Firm',
            'mobile_no' => '9'.random_int(100000000, 999999999),
            'state_id' => $this->stateId(),
            'status' => 'New',
            'team_size' => 10,
        ]);

        $partner = CaMasterPartner::query()->create([
            'ca_id' => $lead->ca_id,
            'ca_name' => 'Partner A',
            'is_primary' => false,
            'sequence_no' => 1,
            'team_size' => 3,
        ]);

        $this->patchJson('/ca-masters/'.$lead->ca_id.'/team-size', ['team_size' => 8])
            ->assertOk()
            ->assertJsonPath('data.team_size', 8);

        $this->assertSame(8, (int) $lead->fresh()->team_size);
        $this->assertSame(3, (int) $partner->fresh()->team_size);
    }

    public function test_patch_partner_team_size_updates_only_partner(): void
    {
        $this->actingAsAdmin();

        if (! Schema::hasTable('ca_master_partners')) {
            $this->markTestSkipped('ca_master_partners table missing');
        }

        $lead = CaMaster::query()->create([
            'ca_name' => 'Patch Partner Firm CA',
            'firm_name' => 'Patch Partner Firm',
            'mobile_no' => '9'.random_int(100000000, 999999999),
            'state_id' => $this->stateId(),
            'status' => 'New',
            'team_size' => 10,
        ]);

        $partner = CaMasterPartner::query()->create([
            'ca_id' => $lead->ca_id,
            'ca_name' => 'Partner B',
            'is_primary' => false,
            'sequence_no' => 1,
            'team_size' => 0,
        ]);

        $this->patchJson('/ca-masters/'.$lead->ca_id.'/partners/'.$partner->id.'/team-size', ['team_size' => 5])
            ->assertOk()
            ->assertJsonPath('data.team_size', 5);

        $this->assertSame(10, (int) $lead->fresh()->team_size);
        $this->assertSame(5, (int) $partner->fresh()->team_size);
    }

    public function test_new_partner_defaults_team_size_to_zero(): void
    {
        $this->actingAsAdmin();

        if (! Schema::hasTable('ca_master_partners')) {
            $this->markTestSkipped('ca_master_partners table missing');
        }

        $lead = CaMaster::query()->create([
            'ca_name' => 'Partner Default CA',
            'firm_name' => 'Partner Default Firm',
            'mobile_no' => '9'.random_int(100000000, 999999999),
            'state_id' => $this->stateId(),
            'status' => 'New',
            'team_size' => 4,
        ]);

        $partner = app(CaMasterPartnerService::class)->create($lead, [
            'ca_name' => 'New Partner',
            'mobile' => '9'.random_int(100000000, 999999999),
        ]);

        $this->assertSame(0, (int) $partner->team_size);
        $this->assertSame(4, (int) $lead->fresh()->team_size);
    }

    public function test_adding_partners_does_not_change_firm_team_size(): void
    {
        $this->actingAsAdmin();

        if (! Schema::hasTable('ca_master_partners')) {
            $this->markTestSkipped('ca_master_partners table missing');
        }

        $lead = CaMaster::query()->create([
            'ca_name' => 'Stable Firm CA',
            'firm_name' => 'Stable Firm',
            'mobile_no' => '9'.random_int(100000000, 999999999),
            'state_id' => $this->stateId(),
            'status' => 'New',
            'team_size' => 6,
        ]);

        app(CaMasterPartnerService::class)->create($lead, [
            'ca_name' => 'Added Partner',
            'mobile' => '9'.random_int(100000000, 999999999),
            'team_size' => 2,
        ]);

        $this->assertSame(6, (int) $lead->fresh()->team_size);
    }

    public function test_partner_count_does_not_drive_team_size_in_api(): void
    {
        $this->actingAsAdmin();

        $lead = CaMaster::query()->create([
            'ca_name' => 'Partner Team CA',
            'firm_name' => 'Partner Team Firm',
            'mobile_no' => '9'.random_int(100000000, 999999999),
            'state_id' => $this->stateId(),
            'status' => 'New',
            'team_size' => 7,
        ]);

        if (! Schema::hasTable('ca_master_partners')) {
            $this->markTestSkipped('ca_master_partners table missing');
        }

        CaMasterPartner::query()->create([
            'ca_id' => $lead->ca_id,
            'ca_name' => 'Partner A',
            'is_primary' => true,
            'sequence_no' => 1,
        ]);
        CaMasterPartner::query()->create([
            'ca_id' => $lead->ca_id,
            'ca_name' => 'Partner B',
            'is_primary' => false,
            'sequence_no' => 2,
        ]);
        CaMasterPartner::query()->create([
            'ca_id' => $lead->ca_id,
            'ca_name' => 'Partner C',
            'is_primary' => false,
            'sequence_no' => 3,
        ]);

        $response = $this->getJson('/ca-masters/'.$lead->ca_id)->assertOk();
        $this->assertSame(7, (int) $response->json('data.team_size'));
        $this->assertSame(3, (int) $response->json('data.partner_count'));
        $this->assertSame(7, (int) $lead->fresh()->team_size);
    }

    public function test_listing_includes_independent_partner_team_sizes(): void
    {
        $this->actingAsAdmin();

        if (! Schema::hasTable('ca_master_partners')) {
            $this->markTestSkipped('ca_master_partners table missing');
        }

        $lead = CaMaster::query()->create([
            'ca_name' => 'Partner List CA',
            'firm_name' => 'Partner List Firm '.random_int(1000, 9999),
            'mobile_no' => '9'.random_int(100000000, 999999999),
            'state_id' => $this->stateId(),
            'status' => 'New',
            'team_size' => 10,
        ]);

        $partner = CaMasterPartner::query()->create([
            'ca_id' => $lead->ca_id,
            'ca_name' => 'Partner X',
            'is_primary' => false,
            'sequence_no' => 1,
            'team_size' => 3,
        ]);

        $response = $this->getJson('/ca-masters/'.$lead->ca_id)->assertOk();
        $partners = $response->json('data.partners') ?? [];
        $found = collect($partners)->firstWhere('id', $partner->id);
        $this->assertNotNull($found);
        $this->assertSame(3, (int) ($found['team_size'] ?? -1));
        $this->assertSame(10, (int) $response->json('data.team_size'));
    }

    public function test_partner_modal_update_persists_team_size(): void
    {
        $this->actingAsAdmin();

        if (! Schema::hasTable('ca_master_partners')) {
            $this->markTestSkipped('ca_master_partners table missing');
        }

        $lead = CaMaster::query()->create([
            'ca_name' => 'Modal Partner CA',
            'firm_name' => 'Modal Partner Firm',
            'mobile_no' => '9'.random_int(100000000, 999999999),
            'state_id' => $this->stateId(),
            'status' => 'New',
            'team_size' => 10,
        ]);

        $partner = CaMasterPartner::query()->create([
            'ca_id' => $lead->ca_id,
            'ca_name' => 'Modal Partner',
            'is_primary' => false,
            'sequence_no' => 1,
            'team_size' => 0,
        ]);

        $this->patchJson('/ca-masters/'.$lead->ca_id.'/partners/'.$partner->id, [
            'ca_name' => 'Modal Partner',
            'team_size' => 3,
        ])->assertOk()->assertJsonPath('data.team_size', 3);

        $this->assertSame(10, (int) $lead->fresh()->team_size);
        $this->assertSame(3, (int) $partner->fresh()->team_size);
    }

    public function test_mapping_attributes_omit_team_size(): void
    {
        $attrs = app(\App\Services\Mapping\MasterDataMappingService::class)->toCaMasterAttributes([
            'ca_name' => 'Mapped CA',
            'firm_name' => 'Mapped Firm',
            'state' => 'Maharashtra',
            'partner_count' => 9,
            'team_size' => 9,
            'members' => [
                ['ca_name' => 'A'],
                ['ca_name' => 'B'],
            ],
        ]);

        $this->assertArrayNotHasKey('team_size', $attrs);
    }
}
