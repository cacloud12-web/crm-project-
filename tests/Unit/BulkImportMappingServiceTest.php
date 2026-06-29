<?php

namespace Tests\Unit;

use App\Services\Bulk\BulkImportMappingService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BulkImportMappingServiceTest extends TestCase
{
    private BulkImportMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BulkImportMappingService::class);
    }

    #[Test]
    public function test_crm_fields_exclude_mobile_when_file_has_no_mobile_column(): void
    {
        $headers = ['CA Name', 'Firm Name', 'City', 'Email'];

        $fields = $this->service->crmFieldsForHeaders($headers);

        $this->assertFalse($this->service->fileHasMobileColumn($headers));
        $this->assertNotContains('mobile_no', array_column($fields, 'key'));
        $this->assertTrue(collect($fields)->firstWhere('key', 'ca_name')['required'] ?? false);
        $this->assertTrue(collect($fields)->firstWhere('key', 'firm_name')['required'] ?? false);
    }

    #[Test]
    public function test_crm_fields_include_mobile_when_file_has_mobile_column(): void
    {
        $headers = ['CA Name', 'Firm Name', 'Mobile No', 'Email'];

        $fields = $this->service->crmFieldsForHeaders($headers);

        $this->assertTrue($this->service->fileHasMobileColumn($headers));
        $this->assertContains('mobile_no', array_column($fields, 'key'));
        $this->assertFalse(collect($fields)->firstWhere('key', 'mobile_no')['required'] ?? true);
    }

    #[Test]
    public function test_mobile_mapping_is_inactive_when_column_exists_but_not_mapped(): void
    {
        $headers = ['CA Name', 'Firm Name', 'Phone', 'Email'];
        $mapping = [
            'ca_name' => 'CA Name',
            'firm_name' => 'Firm Name',
            'email_id' => 'Email',
        ];

        $this->assertTrue($this->service->fileHasMobileColumn($headers));
        $this->assertFalse($this->service->mobileMappingIsActive($headers, $mapping));
        $this->assertContains('mobile_no', array_column($this->service->crmFieldsForHeaders($headers), 'key'));
    }

    #[Test]
    public function test_mobile_mapping_is_active_when_column_exists_and_mapped(): void
    {
        $headers = ['CA Name', 'Firm Name', 'Phone', 'Email'];
        $mapping = [
            'ca_name' => 'CA Name',
            'firm_name' => 'Firm Name',
            'mobile_no' => 'Phone',
            'email_id' => 'Email',
        ];

        $this->assertTrue($this->service->mobileMappingIsActive($headers, $mapping));
    }
}
