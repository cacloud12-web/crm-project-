<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LeadTeamSizeColumnTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsAdmin(): User
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        return $admin;
    }

    private function stateId(): int
    {
        return (int) CaMaster::query()->whereNotNull('state_id')->value('state_id');
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

    public function test_team_size_filter_matches_not_specified(): void
    {
        $this->actingAsAdmin();

        $mobile = '9'.random_int(100000000, 999999999);
        $lead = CaMaster::query()->create([
            'ca_name' => 'Null Team CA',
            'firm_name' => 'Null Team Firm '.random_int(1000, 9999),
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
}
