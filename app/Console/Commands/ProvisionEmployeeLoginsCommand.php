<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Employee\EmployeeCredentialService;
use Illuminate\Console\Command;

class ProvisionEmployeeLoginsCommand extends Command
{
    protected $signature = 'employees:provision-logins {--password= : Default password for newly created logins (min 8 chars)}';

    protected $description = 'Create or link CRM login accounts for employees without user records';

    public function handle(EmployeeCredentialService $credentialService): int
    {
        $password = $this->option('password');

        if ($password !== null && strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        $actor = User::query()->where('crm_role', 'super_admin')->first()
            ?? User::query()->where('crm_role', 'admin')->first();

        if (! $actor) {
            $this->error('No admin user found to authorize provisioning.');

            return self::FAILURE;
        }

        $stats = $credentialService->provisionMissingLogins($actor, $password);

        $this->info('Linked existing users: '.$stats['linked']);
        $this->info('Provisioned new logins: '.$stats['provisioned']);
        $this->info('Skipped: '.$stats['skipped']);

        return self::SUCCESS;
    }
}
