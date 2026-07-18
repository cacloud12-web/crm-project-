<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\CreatesCrmUsers;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use CreatesCrmUsers;
    use DatabaseTransactions;

    private function actingAsAdmin(): User
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        return $admin;
    }

    public function test_admin_can_create_user_via_employee_api(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);

        $response = $this->postJson('/employees', [
            'name' => 'Created User '.$ts,
            'email_id' => "created.user.{$ts}@test.local",
            'mobile_no' => '8'.substr(str_replace('.', '', $ts), -9),
            'crm_role' => 'manager',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'status' => 'Active',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.crm_role', 'manager');

        $this->assertDatabaseHas('users', [
            'email' => "created.user.{$ts}@test.local",
            'crm_role' => 'manager',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_update_user_status_and_role(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);

        $create = $this->postJson('/employees', [
            'name' => 'Update Target '.$ts,
            'email_id' => "update.user.{$ts}@test.local",
            'mobile_no' => '7'.substr(str_replace('.', '', $ts), -9),
            'crm_role' => 'employee',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'status' => 'Active',
        ])->assertCreated();

        $employeeId = $create->json('data.employee_id');

        $this->putJson("/employees/{$employeeId}", [
            'status' => 'Inactive',
            'crm_role' => 'manager',
        ])->assertOk()
            ->assertJsonPath('data.status', 'Inactive')
            ->assertJsonPath('data.crm_role', 'manager')
            ->assertJsonPath('data.login_status', 'inactive');

        $user = User::query()->where('email', "update.user.{$ts}@test.local")->firstOrFail();
        $this->assertFalse($user->is_active);
        $this->assertSame('manager', $user->crm_role);
    }

    public function test_admin_can_delete_user_and_user_is_hidden_from_lists(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);
        $email = "delete.user.{$ts}@test.local";

        $create = $this->postJson('/employees', [
            'name' => 'Delete Target',
            'email_id' => $email,
            'mobile_no' => '6'.substr(str_replace('.', '', $ts), -9),
            'crm_role' => 'employee',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
        ])->assertCreated();

        $employeeId = $create->json('data.employee_id');
        $userId = User::query()->where('email', $email)->value('id');

        $this->deleteJson("/employees/{$employeeId}")
            ->assertOk()
            ->assertJsonPath('message', 'Employee deleted successfully');

        $this->assertSoftDeleted('employees', ['employee_id' => $employeeId]);
        $this->assertSoftDeleted('users', ['id' => $userId]);

        $listEmails = collect($this->getJson('/employees')->json('data.items'))->pluck('email_id');
        $this->assertFalse($listEmails->contains($email));

        $lookupNames = collect($this->getJson('/lookups/executives')->json('data'))->pluck('name');
        $this->assertFalse($lookupNames->contains('Delete Target'));
    }

    public function test_root_super_admin_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();
        $rootUser = User::query()->where('crm_role', 'super_admin')->where('is_active', true)->first()
            ?? $this->createSuperAdmin();

        $employee = Employee::query()->updateOrCreate(
            ['email_id' => $rootUser->email],
            [
                'user_id' => $rootUser->id,
                'name' => $rootUser->name,
                'mobile_no' => '9000000099',
                'role' => 'Super Admin',
                'status' => 'Active',
            ],
        );

        $this->deleteJson('/employees/'.$employee->employee_id)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user']);

        $this->assertDatabaseHas('users', ['id' => $rootUser->id, 'deleted_at' => null]);
    }

    public function test_last_active_super_admin_cannot_be_deactivated(): void
    {
        $service = app(\App\Services\User\UserLifecycleService::class);
        $only = $this->createSuperAdmin(['email' => 'only.sa.'.microtime(true).'@test.local']);

        User::query()
            ->where('crm_role', 'super_admin')
            ->where('id', '!=', $only->id)
            ->update(['is_active' => false]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->assertCanDeactivateUser($only->fresh());
    }

    public function test_deleted_user_cannot_login(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);
        $email = "login.blocked.{$ts}@test.local";
        $password = 'SecurePass123';

        $create = $this->postJson('/employees', [
            'name' => 'Login Block Target',
            'email_id' => $email,
            'mobile_no' => '5'.substr(str_replace('.', '', $ts), -9),
            'crm_role' => 'employee',
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertCreated();

        $employeeId = $create->json('data.employee_id');

        $this->deleteJson("/employees/{$employeeId}")->assertOk();

        auth()->logout();

        $this->post('/login', [
            'email' => $email,
            'password' => $password,
        ])->assertSessionHasErrors();
    }

    public function test_inactive_user_cannot_login(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);
        $email = "inactive.user.{$ts}@test.local";
        $password = 'SecurePass123';

        $create = $this->postJson('/employees', [
            'name' => 'Inactive Target',
            'email_id' => $email,
            'mobile_no' => '4'.substr(str_replace('.', '', $ts), -9),
            'crm_role' => 'employee',
            'password' => $password,
            'password_confirmation' => $password,
            'status' => 'Active',
        ])->assertCreated();

        $employeeId = $create->json('data.employee_id');

        $this->putJson("/employees/{$employeeId}", ['status' => 'Inactive'])->assertOk();

        auth()->logout();

        $this->post('/login', [
            'email' => $email,
            'password' => $password,
        ])->assertSessionHasErrors();
    }
}
