<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** Plaintext used only in tests when login assertions need a known password. */
    public const TEST_PASSWORD = 'TestPass-9f3a2b7c';

    protected static ?string $password;

    public function definition(): array
    {
        $token = Str::lower(Str::random(8));

        return [
            'name' => 'Test User '.$token,
            'email' => 'user.'.$token.'.'.uniqid().'@example.test',
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make(self::TEST_PASSWORD),
            'remember_token' => Str::random(10),
            'crm_role' => 'employee',
            'is_active' => true,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function superAdmin(): static
    {
        return $this->state(fn () => [
            'name' => 'Test Super Admin '.Str::random(6),
            'crm_role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn () => [
            'name' => 'Test Admin '.Str::random(6),
            'crm_role' => 'admin',
            'is_active' => true,
        ]);
    }

    public function manager(): static
    {
        return $this->state(fn () => [
            'name' => 'Test Manager '.Str::random(6),
            'crm_role' => 'manager',
            'is_active' => true,
        ]);
    }

    public function employee(): static
    {
        return $this->state(fn () => [
            'name' => 'Test Employee '.Str::random(6),
            'crm_role' => 'employee',
            'is_active' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
