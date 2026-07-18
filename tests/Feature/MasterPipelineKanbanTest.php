<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Models\CaMaster;
use App\Models\City;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MasterPipelineKanbanTest extends TestCase
{
    use DatabaseTransactions;

    private function createLead(string $status = 'New'): CaMaster
    {
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $suffix = (string) random_int(1000, 9999);

        return CaMaster::query()->create([
            'firm_name' => 'Pipeline Firm '.$suffix,
            'ca_name' => 'Pipeline CA '.$suffix,
            'mobile_no' => '9'.substr(str_pad((string) random_int(100000000, 999999999), 9, '0'), -9),
            'email_id' => 'pipeline.'.$suffix.'@test.local',
            'city_id' => $city->city_id,
            'state_id' => $state->state_id,
            'status' => $status,
        ]);
    }

    public function test_master_kanban_returns_four_sales_stages(): void
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        $this->createLead('New');
        $this->createLead('Contacted');
        $this->createLead('Interested');
        $this->createLead('Converted');

        $response = $this->getJson('/ca-masters/kanban?pipeline=master&per_stage=50');

        $response->assertOk()
            ->assertJsonPath('data.pipeline', 'master');

        $stageCounts = $response->json('data.stage_counts');
        $this->assertArrayHasKey('New Lead', $stageCounts);
        $this->assertArrayHasKey('Contacted', $stageCounts);
        $this->assertArrayHasKey('Interested', $stageCounts);
        $this->assertArrayHasKey('Converted', $stageCounts);
        $this->assertArrayNotHasKey('Demo Scheduled', $stageCounts);
        $this->assertArrayNotHasKey('Documents Pending', $stageCounts);

        $this->assertGreaterThanOrEqual(1, $stageCounts['New Lead']);
        $this->assertGreaterThanOrEqual(1, $stageCounts['Contacted']);
        $this->assertGreaterThanOrEqual(1, $stageCounts['Interested']);
        $this->assertGreaterThanOrEqual(1, $stageCounts['Converted']);
    }

    public function test_legacy_statuses_map_to_master_pipeline_stages(): void
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        $demoScheduled = $this->createLead('Demo Scheduled');
        $active = $this->createLead('Active');

        $response = $this->getJson('/ca-masters/kanban?pipeline=master&per_stage=50');
        $response->assertOk();

        $items = collect($response->json('data.items'));
        $demoItem = $items->firstWhere('ca_id', $demoScheduled->ca_id);
        $activeItem = $items->firstWhere('ca_id', $active->ca_id);

        $this->assertNotNull($demoItem);
        $this->assertSame('Contacted', $demoItem['master_pipeline_stage']);
        $this->assertNotNull($activeItem);
        $this->assertSame('Converted', $activeItem['master_pipeline_stage']);
    }

    public function test_status_patch_accepts_master_pipeline_statuses(): void
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        $lead = $this->createLead('New');

        $this->patchJson('/ca-masters/'.$lead->ca_id.'/status', ['status' => 'Contacted'])
            ->assertOk()
            ->assertJsonPath('data.status', 'Contacted')
            ->assertJsonPath('data.master_pipeline_stage', 'Contacted');

        $this->assertDatabaseHas('ca_masters', [
            'ca_id' => $lead->ca_id,
            'status' => 'Contacted',
        ]);

        $this->patchJson('/ca-masters/'.$lead->ca_id.'/status', ['status' => 'Interested'])
            ->assertOk()
            ->assertJsonPath('data.master_pipeline_stage', 'Interested');

        $this->patchJson('/ca-masters/'.$lead->ca_id.'/status', ['status' => 'Converted'])
            ->assertOk()
            ->assertJsonPath('data.master_pipeline_stage', 'Converted');
    }

    public function test_sales_pipeline_still_uses_seven_stages_without_master_param(): void
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        $response = $this->getJson('/ca-masters/kanban?per_stage=10');
        $response->assertOk()
            ->assertJsonPath('data.pipeline', 'sales');

        $stageCounts = $response->json('data.stage_counts');
        $this->assertArrayHasKey('Demo Scheduled', $stageCounts);
        $this->assertArrayHasKey('Won', $stageCounts);
        $this->assertArrayNotHasKey('Converted', $stageCounts);
    }

    public function test_listing_filters_by_master_pipeline_stage(): void
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        $newLead = $this->createLead('New');
        $contacted = $this->createLead('Contacted');
        $interested = $this->createLead('Interested');
        $converted = $this->createLead('Converted');

        $extractIds = function ($response) {
            $payload = $response->json('data');
            $items = is_array($payload) && array_key_exists('items', $payload)
                ? ($payload['items'] ?? [])
                : ($payload ?? []);

            return collect($items)->pluck('ca_id')->map(fn ($id) => (int) $id);
        };

        $newIds = $extractIds($this->getJson('/ca-masters?master_pipeline_stage=New+Lead&per_page=100')->assertOk());
        $this->assertTrue($newIds->contains((int) $newLead->ca_id));
        $this->assertFalse($newIds->contains((int) $contacted->ca_id));

        $contactedIds = $extractIds($this->getJson('/ca-masters?master_pipeline_stage=Contacted&per_page=100')->assertOk());
        $this->assertTrue($contactedIds->contains((int) $contacted->ca_id));
        $this->assertFalse($contactedIds->contains((int) $newLead->ca_id));

        $interestedIds = $extractIds($this->getJson('/ca-masters?master_pipeline_stage=Interested&per_page=100')->assertOk());
        $this->assertTrue($interestedIds->contains((int) $interested->ca_id));
        $this->assertFalse($interestedIds->contains((int) $newLead->ca_id));

        $convertedIds = $extractIds($this->getJson('/ca-masters?master_pipeline_stage=Converted&per_page=100')->assertOk());
        $this->assertTrue($convertedIds->contains((int) $converted->ca_id));
        $this->assertFalse($convertedIds->contains((int) $newLead->ca_id));

        $invalidIds = $extractIds($this->getJson('/ca-masters?master_pipeline_stage=InvalidStage&per_page=100')->assertOk());
        $this->assertTrue($invalidIds->isEmpty());
    }

    public function test_segment_counts_support_master_pipeline_stages(): void
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        $response = $this->getJson('/ca-masters/segment-counts?pipeline=master')->assertOk();
        $stages = $response->json('data.pipeline_stages') ?? [];

        $this->assertArrayHasKey('New Lead', $stages);
        $this->assertArrayHasKey('Contacted', $stages);
        $this->assertArrayHasKey('Interested', $stages);
        $this->assertArrayHasKey('Converted', $stages);
        $this->assertArrayNotHasKey('Demo Scheduled', $stages);
    }
}
