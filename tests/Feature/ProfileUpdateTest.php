<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use DatabaseTransactions;

    public function test_super_admin_can_update_own_profile_name_but_not_login_email(): void
    {
        $user = User::query()->where('email', 'superadmin@ca.local')->firstOrFail();
        $this->actingAs($user);

        $response = $this->putJson('/auth/profile', [
            'name' => 'Super Admin Updated',
            'email' => 'superadmin.updated@ca.local',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Super Admin Updated')
            ->assertJsonPath('data.email', 'superadmin@ca.local');

        User::query()->whereKey($user->id)->update([
            'name' => 'Super Admin',
            'email' => 'superadmin@ca.local',
        ]);
    }

    public function test_employee_user_can_update_profile_and_linked_employee_record(): void
    {
        $user = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($user);

        $response = $this->putJson('/auth/profile', [
            'name' => 'Employee Updated',
            'email' => 'employee@ca.local',
            'designation' => 'Senior Sales Executive',
            'mobile_no' => '9876543210',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Employee Updated');
    }

    public function test_profile_email_must_be_unique(): void
    {
        $user = User::query()->where('email', 'superadmin@ca.local')->firstOrFail();
        $this->actingAs($user);

        $response = $this->putJson('/auth/profile', [
            'name' => 'Super Admin',
            'email' => 'admin@ca.local',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }
}
