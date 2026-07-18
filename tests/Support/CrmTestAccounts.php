<?php

namespace Tests\Support;

use App\Models\Employee;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Shared factory-built CRM accounts for tests that need role fixtures.
 * Emails are unique @example.test addresses — never real staff identities.
 */
final class CrmTestAccounts
{
    public static ?User $superAdmin = null;

    public static ?User $admin = null;

    public static ?User $manager = null;

    public static ?User $employee = null;

    public static ?Employee $employeeProfile = null;

    public static function reset(): void
    {
        self::$superAdmin = null;
        self::$admin = null;
        self::$manager = null;
        self::$employee = null;
        self::$employeeProfile = null;
    }

    public static function plaintextPassword(): string
    {
        return UserFactory::TEST_PASSWORD;
    }

    public static function bootstrap(): void
    {
        if (self::$admin && User::query()->whereKey(self::$admin->id)->exists()) {
            return;
        }

        $suffix = Str::lower(Str::random(8));
        $password = Hash::make(self::plaintextPassword());

        self::$superAdmin = User::factory()->superAdmin()->create([
            'name' => 'Test Super Admin',
            'email' => "superadmin.{$suffix}@example.test",
            'password' => $password,
        ]);
        self::$admin = User::factory()->admin()->create([
            'name' => 'Test Admin',
            'email' => "admin.{$suffix}@example.test",
            'password' => $password,
        ]);
        self::$manager = User::factory()->manager()->create([
            'name' => 'Test Manager',
            'email' => "manager.{$suffix}@example.test",
            'password' => $password,
        ]);
        self::$employee = User::factory()->employee()->create([
            'name' => 'Test Employee',
            'email' => "employee.{$suffix}@example.test",
            'password' => $password,
        ]);
        self::$employeeProfile = Employee::factory()->create([
            'user_id' => self::$employee->id,
            'name' => 'Test Employee',
            'email_id' => self::$employee->email,
            'mobile_no' => '9'.substr(preg_replace('/\D/', '', $suffix).'000000000', 0, 9),
            'role' => 'Sales Executive',
            'status' => 'Active',
        ]);
    }

    public static function superAdmin(): User
    {
        self::bootstrap();

        return self::$superAdmin;
    }

    public static function admin(): User
    {
        self::bootstrap();

        return self::$admin;
    }

    public static function manager(): User
    {
        self::bootstrap();

        return self::$manager;
    }

    public static function employeeUser(): User
    {
        self::bootstrap();

        return self::$employee;
    }

    public static function employee(): Employee
    {
        self::bootstrap();

        return self::$employeeProfile->fresh(['user']);
    }
}
