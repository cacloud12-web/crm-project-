<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReportsFeatureTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return CrmTestAccounts::admin();
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

        $slugs = [
            'lead_conversion',
            'employee_performance',
            'followup_performance',
            'duplicate_productivity',
            'monthly_trends',
            'city_analysis',
            'lost_lead_analysis',
            'assignment_statistics',
            'campaign_analytics',
        ];

        foreach ($slugs as $slug) {
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

    public function test_admin_can_export_report_summary_csv(): void
    {
        $this->actingAs($this->admin());

        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->get('/reports/export/summary?from='.now()->subDays(30)->toDateString().'&to='.now()->toDateString());

        $response->assertOk();
        $contentType = (string) $response->headers->get('content-type');
        $this->assertTrue(
            str_contains($contentType, 'text/csv') || str_contains($contentType, 'application/octet-stream'),
            'Expected CSV export content type, got: '.$contentType,
        );
    }

    public function test_admin_can_export_report_pdf(): void
    {
        $this->actingAs($this->admin());

        $response = $this->withHeaders([
            'Accept' => 'application/pdf, application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->get('/reports/lead_conversion/export?format=pdf');

        $response->assertOk();
        $contentType = (string) $response->headers->get('content-type');
        $this->assertStringContainsString('application/pdf', $contentType);
        $this->assertNotSame('', trim((string) $response->getContent()));
    }

    public function test_admin_can_export_report_summary_pdf(): void
    {
        $this->actingAs($this->admin());

        $response = $this->withHeaders([
            'Accept' => 'application/pdf, application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->get('/reports/export/summary?format=pdf&from='.now()->subDays(30)->toDateString().'&to='.now()->toDateString());

        $response->assertOk();
        $contentType = (string) $response->headers->get('content-type');
        $this->assertStringContainsString('application/pdf', $contentType);
    }

    public function test_employee_cannot_access_reports(): void
    {
        $employee = CrmTestAccounts::employeeUser();
        $this->actingAs($employee);

        $this->getJson('/reports')->assertForbidden();
        $this->getJson('/reports/lead_conversion')->assertForbidden();
    }
}
