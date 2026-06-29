<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CrmUserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['name' => 'Super Admin', 'email' => 'superadmin@ca.local', 'crm_role' => 'super_admin'],
            ['name' => 'System Admin', 'email' => 'admin@ca.local', 'crm_role' => 'admin'],
            ['name' => 'Rahul Verma', 'email' => 'manager@ca.local', 'crm_role' => 'manager'],
            ['name' => 'Priya Sharma', 'email' => 'employee@ca.local', 'crm_role' => 'employee'],
        ];

        foreach ($users as $user) {
            User::query()->updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'crm_role' => $user['crm_role'],
                    'password' => Hash::make('password'),
                    'is_active' => true,
                ],
            );
        }

        $employeeUser = User::query()->where('email', 'employee@ca.local')->first();

        Employee::query()->updateOrCreate(
            ['email_id' => 'employee@ca.local'],
            [
                'user_id' => $employeeUser?->id,
                'name' => 'Priya Sharma',
                'mobile_no' => '9000000001',
                'role' => 'Sales Executive',
                'status' => 'Active',
            ],
        );
    }
}
