<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\User;
use App\Services\Rbac\RbacService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MasterDataLoadTest extends TestCase
{
    use DatabaseTransactions;

    private function superAdmin(): User
    {
        $user = User::query()->where('email', 'superadmin@ca.local')->first()
            ?? User::query()->where('email', 'admin@ca.local')->firstOrFail();

        return $user;
    }

    public function test_super_admin_can_open_master_data_spa_route(): void
    {
        $user = $this->superAdmin();
        $this->actingAs($user);

        $response = $this->get('/ca-masters');

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('crm.js?v=', $html);
        $this->assertStringContainsString('pages.js?v=', $html);
        $this->assertStringContainsString('app.js?v=', $html);
        $this->assertStringContainsString('__CRM_INITIAL_PAGE__', $html);
        $this->assertMatchesRegularExpression('/__CRM_INITIAL_PAGE__\s*=\s*"ca-master"/', $html);
    }

    public function test_master_data_listing_api_returns_rows_and_pagination(): void
    {
        $user = $this->superAdmin();
        $this->actingAs($user);

        if (CaMaster::query()->count() === 0) {
            CaMaster::query()->create([
                'firm_name' => 'Master Load Test Firm',
                'ca_name' => 'Master Load Test CA',
                'mobile_no' => '9'.random_int(100000000, 999999999),
                'email_id' => 'master-load-'.microtime(true).'@test.local',
                'status' => 'New',
            ]);
        }

        $response = $this->getJson('/ca-masters?page=1&per_page=10');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'items',
                    'pagination' => [
                        'current_page',
                        'per_page',
                        'total',
                        'last_page',
                    ],
                    'meta',
                ],
            ]);

        $items = $response->json('data.items');
        $this->assertIsArray($items);
        $this->assertNotEmpty($items);
        $this->assertGreaterThan(0, (int) $response->json('data.pagination.total'));
    }

    public function test_default_filters_do_not_exclude_all_records_for_super_admin(): void
    {
        $user = $this->superAdmin();
        $this->actingAs($user);

        $payload = app(RbacService::class)->userPayload($user);
        $this->assertContains($payload['role'] ?? '', ['super_admin', 'admin']);

        $scoped = $this->getJson('/ca-masters?page=1&per_page=10')->assertOk();
        $unscoped = $this->getJson('/ca-masters?page=1&per_page=10&segment=')->assertOk();

        $this->assertSame(
            (int) $scoped->json('data.pagination.total'),
            (int) $unscoped->json('data.pagination.total')
        );
        $this->assertGreaterThan(0, (int) $scoped->json('data.pagination.total'));
    }

    public function test_listing_search_works_on_sqlite_or_mysql_driver(): void
    {
        $user = $this->superAdmin();
        $this->actingAs($user);

        $driver = DB::connection()->getDriverName();
        $this->assertContains($driver, ['sqlite', 'mysql', 'pgsql', 'mariadb']);

        $marker = 'IlikeCompatFirm'.str_replace('.', '', (string) microtime(true));
        CaMaster::query()->create([
            'firm_name' => $marker,
            'ca_name' => 'Ilike CA',
            'mobile_no' => '9'.random_int(100000000, 999999999),
            'email_id' => strtolower($marker).'@test.local',
            'status' => 'New',
        ]);

        $response = $this->getJson('/ca-masters?page=1&per_page=10&search='.urlencode(strtolower(substr($marker, 0, 12))))
            ->assertOk();

        $firmNames = collect($response->json('data.items'))
            ->map(fn ($row) => $row['firm_name'] ?? ($row['data']['firm_name'] ?? null))
            ->filter()
            ->all();

        $this->assertTrue(
            collect($firmNames)->contains(fn ($name) => str_contains((string) $name, 'IlikeCompatFirm')),
            'Expected case-insensitive firm search to return the seeded firm on '.$driver
        );
    }

    public function test_employee_role_scope_does_not_apply_to_super_admin_listing(): void
    {
        $user = $this->superAdmin();
        $this->actingAs($user);

        $total = (int) $this->getJson('/ca-masters?page=1&per_page=1')
            ->assertOk()
            ->json('data.pagination.total');

        $this->assertSame(CaMaster::query()->count(), $total);
    }
}
