<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReportsFeatureTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::query()->where('email', 'admin@ca.local')->firstOrFail();
    }

    public function test_admin_can_load_reports_summary(): void
    {
        $this->actingAs($this->admin());

        $this->getJson('/reports')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'reports',
                ],
            ]);
    }

    public function test_admin_can_load_analytics(): void
    {
        $this->actingAs($this->admin());

        $this->getJson('/reports/analytics')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'filters',
                    'charts',
                    'conversion_summary',
                ],
            ]);
    }

    public function test_known_report_slugs_return_data(): void
    {
        $this->actingAs($this->admin());

        foreach (['lead_conversion', 'employee_performance', 'followup_performance', 'duplicate_productivity'] as $slug) {
            $this->getJson('/reports/'.$slug)
                ->assertOk()
                ->assertJsonPath('data.slug', $slug)
                ->assertJsonStructure([
                    'data' => [
                        'slug',
                        'columns',
                        'rows',
                    ],
                ]);
        }
    }

    public function test_unknown_report_slug_returns_404(): void
    {
        $this->actingAs($this->admin());

        $this->getJson('/reports/not-a-real-report')
            ->assertNotFound();
    }

    public function test_admin_can_export_report_csv(): void
    {
        $this->actingAs($this->admin());

        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->get('/reports/lead_conversion/export?format=csv');

        $response->assertOk();
        $contentType = (string) $response->headers->get('content-type');
        $this->assertTrue(
            str_contains($contentType, 'text/csv') || str_contains($contentType, 'application/octet-stream'),
            'Expected CSV export content type, got: '.$contentType,
        );
    }

    public function test_employee_cannot_access_reports(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employee);

        $this->getJson('/reports')->assertForbidden();
        $this->getJson('/reports/lead_conversion')->assertForbidden();
    }
}
