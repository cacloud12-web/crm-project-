<?php

namespace Tests\Feature;

use App\Services\Communication\CommunicationChannelTestReportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CommunicationChannelTestReportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_report_includes_all_three_channels(): void
    {
        $report = app(CommunicationChannelTestReportService::class)->generate(false);

        $this->assertArrayHasKey('whatsapp', $report);
        $this->assertArrayHasKey('email', $report);
        $this->assertArrayHasKey('sms', $report);
        $this->assertArrayHasKey('configuration_status', $report['whatsapp']);
        $this->assertArrayHasKey('delivery_status', $report['email']);
    }

    public function test_whatsapp_report_includes_company_registration_docs_payload_when_template_exists(): void
    {
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\WhatsAppCloudMappingSeeder']);

        $report = app(CommunicationChannelTestReportService::class)->generate(false);
        $wa = $report['whatsapp'];

        $this->assertTrue($wa['template']['exists_in_crm'] ?? false);
        if ($wa['api_request'] !== null) {
            $this->assertSame('company_registration_docs', $wa['api_request']['template']['name'] ?? null);
            $this->assertSame('en', $wa['api_request']['template']['language']['code'] ?? null);
        }
    }
}
