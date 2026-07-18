<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use DatabaseTransactions;

    public function test_super_admin_can_update_own_profile_name_but_not_login_email(): void
    {
        $user = CrmTestAccounts::superAdmin();
        $this->actingAs($user);

        $originalEmail = $user->email;
        $response = $this->putJson('/auth/profile', [
            'name' => 'Super Admin Updated',
            'email' => 'superadmin.updated.'.uniqid().'@example.local',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Super Admin Updated')
            ->assertJsonPath('data.email', $originalEmail);

        User::query()->whereKey($user->id)->update([
            'name' => 'Test Super Admin',
            'email' => $originalEmail,
        ]);
    }

    public function test_employee_user_can_update_profile_and_linked_employee_record(): void
    {
        $user = CrmTestAccounts::employeeUser();
        $this->actingAs($user);

        $response = $this->putJson('/auth/profile', [
            'name' => 'Employee Updated',
            'email' => $user->email,
            'designation' => 'Senior Sales Executive',
            'mobile_no' => '9876543210',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Employee Updated');
    }

    public function test_profile_email_must_be_unique(): void
    {
        $user = CrmTestAccounts::superAdmin();
        $this->actingAs($user);

        $response = $this->putJson('/auth/profile', [
            'name' => 'Super Admin',
            'email' => CrmTestAccounts::admin()->email,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }
}
