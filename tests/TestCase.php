<?php

namespace Tests;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\PreparesCrmDatabase;
use Tests\Support\CrmTestAccounts;

abstract class TestCase extends BaseTestCase
{
    use PreparesCrmDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareCrmDatabaseForTesting();
        $this->flushCrmCachesForTesting();
        Auth::logout();
    }

    protected function crmSuperAdmin(): User
    {
        return CrmTestAccounts::superAdmin();
    }

    protected function crmAdmin(): User
    {
        return CrmTestAccounts::admin();
    }

    protected function crmManager(): User
    {
        return CrmTestAccounts::manager();
    }

    protected function crmEmployeeUser(): User
    {
        return CrmTestAccounts::employeeUser();
    }

    protected function crmEmployee(): Employee
    {
        return CrmTestAccounts::employee();
    }
}
