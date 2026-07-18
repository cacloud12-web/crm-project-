<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateSuperAdminCommand extends Command
{
    protected $signature = 'crm:create-super-admin
                            {--name= : Display name}
                            {--email= : Unique login email}
                            {--password= : Login password (prefer interactive prompt)}';

    protected $description = 'Securely create the first Super Admin (no default credentials)';

    public function handle(): int
    {
        $activeSuperAdmins = User::query()
            ->where('crm_role', 'super_admin')
            ->where('is_active', true)
            ->count();

        if ($activeSuperAdmins > 0 && ! $this->confirm('An active Super Admin already exists. Create another?', false)) {
            $this->warn('Aborted. Existing Super Admin accounts were left unchanged.');

            return self::SUCCESS;
        }

        $name = (string) ($this->option('name') ?: $this->ask('Name'));
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Email'))));
        $password = (string) ($this->option('password') ?: $this->secret('Password'));
        $confirm = (string) ($this->option('password') ?: $this->secret('Confirm password'));

        if ($password !== $confirm) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'crm_role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->info('Super Admin created successfully.');
        $this->line('ID: '.$user->id);
        $this->line('Email: '.$user->email);
        $this->comment('Password was hashed and is not displayed.');

        return self::SUCCESS;
    }
}
