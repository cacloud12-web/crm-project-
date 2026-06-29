<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Activity\ActivityLogService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ActivityLogPerformerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_resolve_performer_uses_authenticated_user_name(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->first();
        $this->assertNotNull($admin);

        Auth::login($admin);

        $service = app(ActivityLogService::class);
        $this->assertSame($admin->name, $service->resolvePerformer());
    }

    public function test_resolve_performer_keeps_explicit_system(): void
    {
        $service = app(ActivityLogService::class);
        $this->assertSame('System', $service->resolvePerformer('System'));
    }

    public function test_log_writes_authenticated_user_as_performed_by(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->first();
        $this->assertNotNull($admin);
        Auth::login($admin);

        $log = app(ActivityLogService::class)->log(
            'CA_MASTER',
            'Add Lead',
            'TEST-1',
            'Hygiene test lead',
        );

        $this->assertSame($admin->name, $log->performed_by);
    }
}
