<?php

namespace App\Console\Commands;

use App\Services\Rbac\RbacDatabaseService;
use App\Services\Rbac\RbacMatrixService;
use Illuminate\Console\Command;

class EnsureRbacDefaultsCommand extends Command
{
    protected $signature = 'crm:rbac-ensure-defaults';

    protected $description = 'Grant missing permissions from config/rbac.php matrix without revoking existing grants';

    public function handle(RbacDatabaseService $rbacDatabase, RbacMatrixService $matrixService): int
    {
        $inserted = $rbacDatabase->ensureConfigDefaultGrants();
        $matrixService->flushCache();

        $this->info('RBAC default grants ensured. Inserted '.$inserted.' missing permission(s).');

        return self::SUCCESS;
    }
}
