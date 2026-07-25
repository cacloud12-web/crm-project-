<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Services\Bulk\BulkImportMappingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CrmTestAccounts;
use Tests\TestCase;

class BulkImportEmailAndSalesRemarksTest extends TestCase
{
    use DatabaseTransactions;

    private BulkImportMappingService $mapping;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapping = app(BulkImportMappingService::class);
        $this->actingAs(CrmTestAccounts::admin());
    }

    public function test_detects_dynamic_remark_columns_beyond_eight(): void
    {
        $headers = [
            'Firm Name', 'CA Name', 'Email ID',
            'Remarks 1', 'Remarks 2', 'Remarks 3', 'Remarks 9', 'Remarks 12',
        ];

        $detected = $this->mapping->detectRemarkHeaders($headers);
        $this->assertSame(
            ['Remarks 1', 'Remarks 2', 'Remarks 3', 'Remarks 9', 'Remarks 12'],
            $detected
        );

        $fields = $this->mapping->crmFieldsForHeaders($headers);
        $keys = array_column($fields, 'key');
        $this->assertContains('email_id', $keys);
        $this->assertContains('sales_remarks', $keys);
        $this->assertContains('sales_remark_1', $keys);
        $this->assertContains('sales_remark_5', $keys);
        $this->assertSame('Email ID', collect($fields)->firstWhere('key', 'email_id')['label']);
    }

    public function test_suggest_mapping_exposes_email_id_and_all_remarks(): void
    {
        $headers = ['Firm Name', 'Email ID', 'Remarks 1', 'Remarks 2', 'Remarks 3'];
        $mapping = $this->mapping->suggestMapping($headers);

        $this->assertSame('Email ID', $mapping['email_id']);
        $this->assertSame('Remarks 1', $mapping['sales_remark_1']);
        $this->assertSame('Remarks 2', $mapping['sales_remark_2']);
        $this->assertSame('Remarks 3', $mapping['sales_remark_3']);
    }

    public function test_apply_mapping_merges_multiple_remarks_preserving_line_breaks(): void
    {
        $headers = ['Firm Name', 'Remarks 1', 'Remarks 2', 'Remarks 3'];
        $mapping = $this->mapping->suggestMapping($headers);
        $rows = [[
            'Firm Name' => 'Acme CA',
            'Remarks 1' => "Called on 01-07-2026\nInterested",
            'Remarks 2' => '',
            'Remarks 3' => "Follow-up 10-07-2026\nDemo booked",
        ]];

        $mapped = $this->mapping->applyMapping($rows, $mapping, $headers);

        $this->assertSame(
            "Called on 01-07-2026\nInterested\n\nFollow-up 10-07-2026\nDemo booked",
            $mapped[0]['sales_remarks']
        );
        $this->assertSame('Acme CA', $mapped[0]['firm_name']);
    }

    public function test_empty_remark_columns_are_skipped_in_merge(): void
    {
        $headers = ['Firm Name', 'Remarks 1', 'Remarks 2', 'Remarks 3'];
        $mapping = $this->mapping->suggestMapping($headers);
        $rows = [[
            'Firm Name' => 'Empty Remarks Firm',
            'Remarks 1' => '   ',
            'Remarks 2' => null,
            'Remarks 3' => '',
        ]];

        $mapped = $this->mapping->applyMapping($rows, $mapping, $headers);
        $this->assertSame('', $mapped[0]['sales_remarks']);
    }

    public function test_bulk_import_populates_email_and_sales_remarks(): void
    {
        if (! Schema::hasColumn('ca_masters', 'sales_remarks')) {
            $this->markTestSkipped('sales_remarks column missing — run migrations');
        }

        $ts = (string) microtime(true);
        $csv = "Firm Name,CA Name,Email ID,Remarks 1,Remarks 2,Remarks 3\n";
        $csv .= '"Email Remarks Firm '.$ts.'","Email CA '.$ts.'","new.'.$ts.'@test.local",';
        $csv .= "\"First note ".$ts."\",,\"Third note ".$ts."\"\n";

        $file = UploadedFile::fake()->createWithContent('email-remarks.csv', $csv);
        $parse = $this->post('/ca-masters/bulk-import/parse', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        $parse->assertOk();

        $sessionId = $parse->json('data.session_id');
        $crmFields = collect($parse->json('data.crm_fields'));
        $this->assertNotNull($crmFields->firstWhere('key', 'email_id'));
        $this->assertNotNull($crmFields->firstWhere('key', 'sales_remark_1'));
        $this->assertNotNull($crmFields->firstWhere('key', 'sales_remark_3'));

        $mapping = $parse->json('data.suggested_mapping');
        $this->assertSame('Email ID', $mapping['email_id']);

        $validate = $this->postJson('/ca-masters/bulk-import/validate', [
            'session_id' => $sessionId,
            'mapping' => $mapping,
        ]);
        $validate->assertOk();
        $validate->assertJsonPath('data.valid_rows', 1);

        $import = $this->postJson('/ca-masters/bulk-import', [
            'session_id' => $sessionId,
            'mapping' => $mapping,
        ]);
        $import->assertOk();
        $import->assertJsonPath('data.inserted_rows', 1);

        $lead = CaMaster::query()->where('firm_name', 'Email Remarks Firm '.$ts)->first();
        $this->assertNotNull($lead);
        $this->assertSame('new.'.$ts.'@test.local', $lead->email_id);
        $this->assertStringContainsString('First note '.$ts, (string) $lead->sales_remarks);
        $this->assertStringContainsString('Third note '.$ts, (string) $lead->sales_remarks);
        $this->assertStringNotContainsString("\n\n\n", (string) $lead->sales_remarks);
    }

    public function test_existing_email_is_protected_on_merge_but_replaced_on_overwrite(): void
    {
        if (! Schema::hasColumn('ca_masters', 'sales_remarks')) {
            $this->markTestSkipped('sales_remarks column missing — run migrations');
        }

        $ts = (string) microtime(true);
        $mobile = '9'.substr(str_replace('.', '', $ts), -9);

        $existing = CaMaster::query()->create([
            'firm_name' => 'Protect Email Firm '.$ts,
            'ca_name' => 'Protect CA '.$ts,
            'mobile_no' => $mobile,
            'email_id' => 'keep.'.$ts.'@existing.local',
            'normalized_email' => 'keep.'.$ts.'@existing.local',
            'status' => 'New',
            'rating' => 1,
        ]);

        $service = app(\App\Services\Bulk\BulkCaMasterImportService::class);
        $ref = new \ReflectionClass($service);

        $merge = $ref->getMethod('mergeIntoExistingLead');
        $merge->setAccessible(true);
        $merge->invoke($service, (int) $existing->ca_id, [
            'firm_name' => 'Protect Email Firm '.$ts,
            'ca_name' => 'Protect CA '.$ts,
            'mobile_no' => $mobile,
            'email_id' => 'overwrite.'.$ts.'@new.local',
            'sales_remarks' => 'Merged remark '.$ts,
        ], 0);

        $existing->refresh();
        $this->assertSame('keep.'.$ts.'@existing.local', $existing->email_id);
        $this->assertStringContainsString('Merged remark '.$ts, (string) $existing->sales_remarks);

        $replace = $ref->getMethod('replaceExistingLead');
        $replace->setAccessible(true);
        $replace->invoke($service, (int) $existing->ca_id, [
            'firm_name' => 'Protect Email Firm '.$ts,
            'ca_name' => 'Protect CA '.$ts,
            'mobile_no' => $mobile,
            'email_id' => 'overwrite.'.$ts.'@new.local',
            'sales_remarks' => 'Replaced remark '.$ts,
        ], 0);

        $existing->refresh();
        $this->assertSame('overwrite.'.$ts.'@new.local', $existing->email_id);
        $this->assertSame('Replaced remark '.$ts, $existing->sales_remarks);
    }
}
