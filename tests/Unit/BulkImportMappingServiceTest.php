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
    public function test_crm_fields_always_include_mobile_number_mapping_row(): void
    {
        $headers = ['CA Name', 'Firm Name', 'City', 'Email'];

        $fields = $this->service->crmFieldsForHeaders($headers);

        $this->assertFalse($this->service->fileHasMobileColumn($headers));
        $this->assertContains('mobile_no', array_column($fields, 'key'));
        $this->assertSame('Mobile Number', collect($fields)->firstWhere('key', 'mobile_no')['label']);
        $this->assertFalse(collect($fields)->firstWhere('key', 'ca_name')['required'] ?? true);
        $this->assertTrue(collect($fields)->firstWhere('key', 'firm_name')['required'] ?? false);
    }

    #[Test]
    public function test_crm_fields_detect_number_column_as_mobile_header(): void
    {
        $headers = ['ca name', 'firm name', 'number', 'City'];

        $this->assertTrue($this->service->fileHasMobileColumn($headers));
        $this->assertContains('mobile_no', array_column($this->service->crmFieldsForHeaders($headers), 'key'));
    }

    #[Test]
    public function test_suggest_mapping_maps_number_column_to_mobile_no(): void
    {
        $headers = ['ca name', 'firm name', 'number', 'Alternate Mobile No', 'City'];

        $mapping = $this->service->suggestMapping($headers);

        $this->assertSame('ca name', $mapping['ca_name']);
        $this->assertSame('firm name', $mapping['firm_name']);
        $this->assertSame('number', $mapping['mobile_no']);
        $this->assertSame('Alternate Mobile No', $mapping['alternate_mobile_no']);
        $this->assertSame('City', $mapping['city_id']);
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

    #[Test]
    public function test_apply_mapping_preserves_phone_values_as_strings(): void
    {
        $rows = [
            ['number' => 9876543210],
        ];
        $mapping = ['mobile_no' => 'number'];

        $mapped = $this->service->applyMapping($rows, array_merge(
            array_fill_keys(array_column(BulkImportMappingService::CRM_FIELDS, 'key'), null),
            $mapping,
        ));

        $this->assertSame('9876543210', $mapped[0]['mobile_no']);
    }
}
