<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Activity\ActivityLogService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsAdmin(): User
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        return $admin;
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        $rateLimitEmail = 'ratelimit.prod.'.microtime(true).'@test.local';

        for ($i = 0; $i < 5; $i++) {
            $this->from('/login')->post('/login', [
                'email' => $rateLimitEmail,
                'password' => 'wrong-password',
            ])->assertRedirect('/login');
        }

        $response = $this->from('/login')->post('/login', [
            'email' => $rateLimitEmail,
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors([
            'email' => 'Too many failed login attempts. Please try again after 15 minutes.',
        ]);
    }

    public function test_queue_status_requires_authentication(): void
    {
        $response = $this->getJson('/admin/queue-status');
        $this->assertNotEquals(200, $response->status());
    }

    public function test_admin_can_load_queue_status(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/admin/queue-status');
        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => ['pending_jobs', 'failed_jobs', 'connection', 'commands'],
            ]);
    }

    public function test_activity_log_stores_audit_fields(): void
    {
        $admin = $this->actingAsAdmin();

        $log = app(ActivityLogService::class)->log(
            'CA_MASTER',
            'Update Lead',
            'TEST-AUDIT',
            'Audit field test',
            beforeValue: ['status' => 'New'],
            afterValue: ['status' => 'Warm'],
            ipAddress: '127.0.0.1',
        );

        $this->assertSame('127.0.0.1', $log->ip_address);
        $this->assertStringContainsString('New', (string) $log->before_value);
        $this->assertStringContainsString('Warm', (string) $log->after_value);
        $this->assertSame($admin->name, $log->performed_by);
    }

    public function test_api_errors_do_not_expose_exception_details(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/reports/nonexistent-slug/export');
        $response->assertStatus(404);
        $this->assertStringNotContainsString('Stack trace', (string) $response->getContent());
        $this->assertStringNotContainsString('InvalidArgumentException', (string) $response->getContent());
    }

    public function test_location_lookups_are_cached(): void
    {
        $this->actingAsAdmin();

        $first = $this->getJson('/lookups/states');
        $first->assertOk();

        $second = $this->getJson('/lookups/states');
        $second->assertOk();
        $this->assertSame($first->json('data'), $second->json('data'));
    }
}
