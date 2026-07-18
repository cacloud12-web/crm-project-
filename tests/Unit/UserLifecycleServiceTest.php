<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\User\UserLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesCrmUsers;
use Tests\TestCase;

class UserLifecycleServiceTest extends TestCase
{
    use CreatesCrmUsers;
    use DatabaseTransactions;

    public function test_blocks_deletion_of_any_super_admin(): void
    {
        $service = app(UserLifecycleService::class);
        $sa = $this->createSuperAdmin();

        $this->expectException(ValidationException::class);
        $service->assertCanDeleteUser($sa);
    }

    public function test_blocks_demotion_of_last_active_super_admin(): void
    {
        $service = app(UserLifecycleService::class);
        $only = $this->createSuperAdmin(['email' => 'lifecycle.sa.'.microtime(true).'@test.local']);

        User::query()
            ->where('crm_role', 'super_admin')
            ->where('id', '!=', $only->id)
            ->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        $service->assertCanChangeRole($only->fresh(), 'admin');
    }

    public function test_allows_demotion_when_another_super_admin_is_active(): void
    {
        $service = app(UserLifecycleService::class);
        $this->createSuperAdmin(['email' => 'lifecycle.sa.a.'.microtime(true).'@test.local']);
        $second = $this->createSuperAdmin(['email' => 'lifecycle.sa.b.'.microtime(true).'@test.local']);

        // Still blocked by general Super Admin demotion rule from user management.
        $this->expectException(ValidationException::class);
        $service->assertCanChangeRole($second, 'admin');
    }
}
