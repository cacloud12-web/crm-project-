<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CaMasterSecurityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_employee_cannot_delete_lead(): void
    {
        $user = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($user);

        $lead = CaMaster::query()->firstOrFail();

        $this->deleteJson('/ca-masters/'.$lead->ca_id)
            ->assertForbidden();
    }

    public function test_employee_cannot_create_lead_via_api(): void
    {
        $user = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($user);

        $stateId = CaMaster::query()->whereNotNull('state_id')->value('state_id');
        $this->assertNotNull($stateId);
        $ts = (string) microtime(true);

        $response = $this->postJson('/ca-masters', [
            'ca_name' => 'Auto Assign Test '.$ts,
            'firm_name' => 'Auto Assign Firm '.$ts,
            'mobile_no' => '9'.random_int(100000000, 999999999),
            'state_id' => $stateId,
            'status' => 'New',
        ]);

        $response->assertForbidden();
    }

    public function test_deactivated_user_session_is_invalidated_on_next_request(): void
    {
        $user = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($user);

        $user->update(['is_active' => false]);

        $this->getJson('/auth/me')
            ->assertUnauthorized();

        $user->update(['is_active' => true]);
    }
}
