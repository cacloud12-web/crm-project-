<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Optional env-driven Super Admin bootstrap.
 *
 * Production-safe: does nothing unless CRM_ROOT_SUPER_ADMIN_EMAIL and
 * CRM_ROOT_SUPER_ADMIN_PASSWORD are both set. Prefer:
 *   php artisan crm:create-super-admin
 */
class CrmBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower(trim((string) config('crm_bootstrap.root_super_admin_email', '')));
        $password = (string) config('crm_bootstrap.root_super_admin_password', '');
        $name = trim((string) config('crm_bootstrap.root_super_admin_name', 'Super Admin')) ?: 'Super Admin';

        if ($email === '' || $password === '') {
            if ($this->command) {
                $this->command->warn('CrmBootstrapSeeder skipped: set CRM_ROOT_SUPER_ADMIN_EMAIL and CRM_ROOT_SUPER_ADMIN_PASSWORD, or run php artisan crm:create-super-admin');
            }

            return;
        }

        if (User::query()->where('email', $email)->exists()) {
            return;
        }

        if (User::query()->where('crm_role', 'super_admin')->where('is_active', true)->exists()) {
            if ($this->command) {
                $this->command->info('CrmBootstrapSeeder skipped: an active Super Admin already exists.');
            }

            return;
        }

        User::query()->create([
            'name' => $name,
            'email' => $email,
            'crm_role' => 'super_admin',
            'password' => Hash::make($password),
            'is_active' => true,
        ]);
    }
}
