<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\SourceLead;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MasterDataCrudTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsAdmin(): User
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        return $admin;
    }

    public function test_state_crud_with_activity_log(): void
    {
        $admin = $this->actingAsAdmin();
        $ts = (string) microtime(true);

        $create = $this->postJson('/states', [
            'state_name' => 'Test State '.$ts,
        ]);
        $create->assertCreated();
        $stateId = $create->json('data.state_id');
        $this->assertNotNull($stateId);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'Add State',
            'performed_by' => $admin->name,
        ]);

        $this->putJson("/states/{$stateId}", [
            'state_name' => 'Updated State '.$ts,
        ])->assertOk();

        $this->assertDatabaseHas('states', [
            'state_id' => $stateId,
            'state_name' => 'Updated State '.$ts,
        ]);

        $this->deleteJson("/states/{$stateId}")
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_state_delete_blocked_when_used_by_ca_master(): void
    {
        $this->actingAsAdmin();
        $ca = CaMaster::query()->first();
        if (! $ca) {
            $this->markTestSkipped('No CA Master records in seed data.');
        }

        $this->deleteJson('/states/'.$ca->state_id)
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_city_crud_requires_valid_state(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);
        $state = State::query()->firstOrFail();
        $otherState = State::query()->where('state_id', '!=', $state->state_id)->firstOrFail();

        $create = $this->postJson('/cities', [
            'city_name' => 'Test City '.$ts,
            'state_id' => $state->state_id,
        ]);
        $create->assertCreated();
        $cityId = $create->json('data.city_id');

        $this->putJson("/cities/{$cityId}", [
            'city_name' => 'Updated City '.$ts,
            'state_id' => $otherState->state_id,
        ])->assertOk();

        $this->assertDatabaseHas('cities', [
            'city_id' => $cityId,
            'state_id' => $otherState->state_id,
        ]);

        $this->deleteJson("/cities/{$cityId}")->assertOk();
    }

    public function test_city_delete_blocked_when_used_by_ca_master(): void
    {
        $this->actingAsAdmin();
        $ca = CaMaster::query()->first();
        if (! $ca) {
            $this->markTestSkipped('No CA Master records in seed data.');
        }

        $this->deleteJson('/cities/'.$ca->city_id)
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_source_lead_crud(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);

        $create = $this->postJson('/source-leads', [
            'source_name' => 'Test Source '.$ts,
        ]);
        $create->assertCreated();
        $sourceId = $create->json('data.source_id');

        $this->putJson("/source-leads/{$sourceId}", [
            'source_name' => 'Updated Source '.$ts,
        ])->assertOk();

        $this->deleteJson("/source-leads/{$sourceId}")->assertOk();
    }

    public function test_team_size_crud(): void
    {
        $this->actingAsAdmin();
        $ts = substr(str_replace('.', '', (string) microtime(true)), -4);

        $create = $this->postJson('/team-sizes', [
            'team_size_min' => 9000 + (int) $ts,
            'team_size_max' => 9000 + (int) $ts + 5,
            'team_size_label' => 'Test Range '.$ts,
        ]);
        $create->assertCreated();
        $id = $create->json('data.id') ?? $create->json('data.team_size_id');

        $this->putJson("/team-sizes/{$id}", [
            'team_size_min' => 9000 + (int) $ts,
            'team_size_max' => 9000 + (int) $ts + 5,
            'team_size_label' => 'Updated Range '.$ts,
        ])->assertOk();

        $this->deleteJson("/team-sizes/{$id}")->assertOk();
    }

    public function test_role_master_crud(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);

        $create = $this->postJson('/role-masters', [
            'role_name' => 'Test Role '.$ts,
            'description' => 'Feature test role',
        ]);
        $create->assertCreated();
        $id = $create->json('data.id');

        $this->putJson("/role-masters/{$id}", [
            'role_name' => 'Updated Role '.$ts,
            'description' => 'Updated description',
        ])->assertOk();

        $this->deleteJson("/role-masters/{$id}")->assertOk();
    }

    public function test_manager_cannot_delete_master_records(): void
    {
        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $this->actingAs($manager);

        $source = SourceLead::query()->firstOrFail();

        $this->deleteJson('/source-leads/'.$source->source_id)
            ->assertForbidden();
    }
}
