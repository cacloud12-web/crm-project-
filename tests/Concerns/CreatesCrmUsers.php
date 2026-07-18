<?php

namespace Tests\Concerns;

use App\Models\Employee;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

trait CreatesCrmUsers
{
    protected function testPassword(): string
    {
        return UserFactory::TEST_PASSWORD;
    }

    protected function uniqueTestEmail(string $prefix = 'user'): string
    {
        return strtolower($prefix).'.'.Str::lower(Str::random(6)).'.'.uniqid().'@example.test';
    }

    protected function createSuperAdmin(array $overrides = []): User
    {
        return User::factory()->superAdmin()->create(array_merge([
            'email' => $this->uniqueTestEmail('superadmin'),
            'password' => Hash::make($this->testPassword()),
        ], $overrides));
    }

    protected function createAdmin(array $overrides = []): User
    {
        return User::factory()->admin()->create(array_merge([
            'email' => $this->uniqueTestEmail('admin'),
            'password' => Hash::make($this->testPassword()),
        ], $overrides));
    }

    protected function createManager(array $overrides = []): User
    {
        return User::factory()->manager()->create(array_merge([
            'email' => $this->uniqueTestEmail('manager'),
            'password' => Hash::make($this->testPassword()),
        ], $overrides));
    }

    protected function createEmployeeUser(array $overrides = []): User
    {
        return User::factory()->employee()->create(array_merge([
            'email' => $this->uniqueTestEmail('employee'),
            'password' => Hash::make($this->testPassword()),
        ], $overrides));
    }

    protected function createEmployeeWithLogin(array $employeeOverrides = [], string $crmRole = 'employee'): Employee
    {
        $email = $employeeOverrides['email_id'] ?? $this->uniqueTestEmail('employee');
        $employee = Employee::factory()->create(array_merge([
            'email_id' => $email,
            'name' => 'Test Employee '.Str::random(6),
        ], $employeeOverrides));

        $userFactory = match ($crmRole) {
            'admin' => User::factory()->admin(),
            'manager' => User::factory()->manager(),
            default => User::factory()->employee(),
        };

        $user = $userFactory->create([
            'name' => $employee->name,
            'email' => $employee->email_id,
            'password' => Hash::make($this->testPassword()),
            'is_active' => ($employee->status ?? 'Active') === 'Active',
        ]);
        $employee->update(['user_id' => $user->id]);

        return $employee->fresh(['user']);
    }
}
