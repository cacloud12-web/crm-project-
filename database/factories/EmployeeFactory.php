<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        $token = Str::lower(Str::random(8));

        return [
            'name' => 'Test Employee '.$token,
            'email_id' => 'employee.'.$token.'.'.uniqid().'@example.test',
            'mobile_no' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'Sales Executive',
            'status' => 'Active',
            'work_type' => 'calling',
            'active_for_demo' => false,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'Inactive']);
    }

    public function withLogin(string $crmRole = 'employee', ?string $password = null): static
    {
        return $this->afterCreating(function (Employee $employee) use ($crmRole, $password) {
            $factory = User::factory();
            $factory = match ($crmRole) {
                'super_admin' => $factory->superAdmin(),
                'admin' => $factory->admin(),
                'manager' => $factory->manager(),
                default => $factory->employee(),
            };

            $user = $factory->create([
                'name' => $employee->name,
                'email' => $employee->email_id,
                'password' => $password ?? UserFactory::TEST_PASSWORD,
                'is_active' => ($employee->status ?? 'Active') === 'Active',
            ]);
            $employee->update(['user_id' => $user->id]);
        });
    }
}
