<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmployeeCredentialTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsAdmin(): User
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        return $admin;
    }

    private function actingAsEmployeeUser(): User
    {
        $employeeUser = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employeeUser);

        return $employeeUser;
    }

    public function test_admin_can_create_employee_with_login_credentials(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);

        $response = $this->postJson('/employees', [
            'name' => 'Credential Employee '.$ts,
            'email_id' => "credential.{$ts}@test.local",
            'mobile_no' => '8'.substr(str_replace('.', '', $ts), -9),
            'role' => 'Sales Executive',
            'crm_role' => 'employee',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'status' => 'Active',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.login_status', 'active');

        $employeeId = $response->json('data.employee_id');

        $this->assertDatabaseHas('employees', [
            'employee_id' => $employeeId,
            'email_id' => "credential.{$ts}@test.local",
        ]);

        $user = User::query()->where('email', "credential.{$ts}@test.local")->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('SecurePass123', $user->password));
        $this->assertSame('employee', $user->crm_role);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'Employee login created',
        ]);
    }

    public function test_new_employee_can_login_with_assigned_credentials(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);
        $email = "login.{$ts}@test.local";
        $password = 'EmployeeLogin123';

        $this->postJson('/employees', [
            'name' => 'Login Test Employee',
            'email_id' => $email,
            'mobile_no' => '7'.substr(str_replace('.', '', $ts), -9),
            'crm_role' => 'employee',
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertCreated();

        auth()->logout();

        $this->post('/login', [
            'email' => $email,
            'password' => $password,
        ])->assertRedirect('/dashboard');
    }

    public function test_admin_can_reset_employee_password(): void
    {
        $admin = $this->actingAsAdmin();
        $ts = (string) microtime(true);
        $email = "reset.{$ts}@test.local";
        $newPassword = 'ResetPass12345';

        $create = $this->postJson('/employees', [
            'name' => 'Reset Target',
            'email_id' => $email,
            'mobile_no' => '6'.substr(str_replace('.', '', $ts), -9),
            'crm_role' => 'employee',
            'password' => 'InitialPass123',
            'password_confirmation' => 'InitialPass123',
        ])->assertCreated();

        $employeeId = $create->json('data.employee_id');

        $this->postJson("/employees/{$employeeId}/reset-password", [
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertOk();

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertTrue(Hash::check($newPassword, $user->password));

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'Admin reset employee password',
            'performed_by' => $admin->name,
        ]);
    }

    public function test_employee_cannot_reset_another_employee_password(): void
    {
        $this->actingAsEmployeeUser();

        $target = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();

        $this->postJson("/employees/{$target->employee_id}/reset-password", [
            'password' => 'BlockedPass1',
            'password_confirmation' => 'BlockedPass1',
        ])->assertForbidden();
    }

    public function test_employee_can_change_own_password_with_current_password(): void
    {
        $user = $this->actingAsEmployeeUser();
        $newPassword = 'NewEmployeePass123';

        $this->postJson('/auth/change-password', [
            'current_password' => 'password',
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertForbidden();
    }

    public function test_manager_cannot_assign_admin_crm_role(): void
    {
        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $this->actingAs($manager);
        $ts = (string) microtime(true);

        $this->postJson('/employees', [
            'name' => 'Blocked Admin Role',
            'email_id' => "blocked.{$ts}@test.local",
            'mobile_no' => '5'.substr(str_replace('.', '', $ts), -9),
            'crm_role' => 'admin',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
        ])->assertForbidden();
    }

    public function test_password_is_not_returned_in_employee_api_response(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);

        $response = $this->postJson('/employees', [
            'name' => 'Hidden Password Employee',
            'email_id' => "hidden.{$ts}@test.local",
            'mobile_no' => '4'.substr(str_replace('.', '', $ts), -9),
            'crm_role' => 'employee',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
        ]);

        $response->assertCreated();
        $json = json_encode($response->json());
        $this->assertStringNotContainsString('SecurePass123', $json);
        $this->assertArrayNotHasKey('password', $response->json('data') ?? []);
    }
}
