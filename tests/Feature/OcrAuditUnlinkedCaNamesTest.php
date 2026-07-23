<?php

namespace Tests\Feature;

use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Models\User;
use App\Services\Ocr\OcrUnlinkedCaNameAuditService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CrmTestAccounts;
use Tests\TestCase;

class OcrAuditUnlinkedCaNamesTest extends TestCase
{
    use DatabaseTransactions;

    private OcrUnlinkedCaNameAuditService $audit;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->audit = app(OcrUnlinkedCaNameAuditService::class);
    }

    public function test_missing_ca_name_classification(): void
    {
        $firm = $this->seedFirm([
            'firm_name' => 'SOME FIRM & ASSOCIATES',
            'city' => 'AHMEDABAD',
            'source_data' => [
                'raw' => ['firm_name' => 'SOME FIRM & ASSOCIATES', 'ca_name' => '', 'city' => 'AHMEDABAD'],
                'parsed' => ['firm_name' => 'SOME FIRM & ASSOCIATES', 'ca_name' => '', 'city' => 'AHMEDABAD'],
            ],
            'match_reason' => 'ca_name: CA name is required.',
        ]);

        $row = $this->audit->classifyRow($firm);
        $this->assertSame('missing_ca_name', $row['primary_category']);
        $this->assertContains('missing_ca_name', $row['issue_codes']);
    }

    public function test_missing_city_classification(): void
    {
        $firm = $this->seedFirm([
            'firm_name' => 'ALPHA & CO',
            'city' => null,
            'source_data' => [
                'raw' => ['firm_name' => 'ALPHA & CO', 'ca_name' => 'ALPHA SHAH', 'city' => ''],
                'parsed' => ['firm_name' => 'ALPHA & CO', 'ca_name' => 'ALPHA SHAH', 'city' => ''],
            ],
            'match_reason' => 'city: City is required.',
        ]);

        $row = $this->audit->classifyRow($firm);
        $this->assertSame('missing_city', $row['primary_category']);
    }

    public function test_numeric_prefix_address_classification(): void
    {
        $firm = $this->seedFirm([
            'firm_name' => 'A P SHAH & ASSOCIATES',
            'city' => 'AHMEDABAD',
            'source_data' => [
                'raw' => ['ca_name' => '14 NIRMAN SQUARE TENAMENT', 'firm_name' => 'A P SHAH & ASSOCIATES', 'city' => 'AHMEDABAD'],
                'parsed' => ['ca_name' => 'NIRMAN SQUARE TENAMENT', 'firm_name' => 'A P SHAH & ASSOCIATES', 'city' => 'AHMEDABAD'],
            ],
            'match_reason' => 'Parsed value differs from preserved raw OCR value — silent correction blocked.',
        ]);

        $row = $this->audit->classifyRow($firm);
        $this->assertSame('numeric_prefix_address', $row['primary_category']);
        $this->assertContains('building_name_detected_as_ca_name', $row['issue_codes']);
        $this->assertTrue($row['safe_repair_candidate']);
    }

    public function test_building_name_detected_as_ca_name(): void
    {
        $firm = $this->seedFirm([
            'firm_name' => 'AKSHAY N PATEL & ASSOCIATES',
            'city' => 'AHMEDABAD',
            'source_data' => [
                'raw' => ['ca_name' => '206 ANMOL BUSINESS CENTRE', 'city' => 'AHMEDABAD'],
                'parsed' => ['ca_name' => 'ANMOL BUSINESS CENTRE', 'city' => 'AHMEDABAD'],
            ],
            'match_reason' => 'Parsed value differs from preserved raw OCR value — silent correction blocked.',
        ]);

        $row = $this->audit->classifyRow($firm);
        $this->assertContains($row['primary_category'], [
            'numeric_prefix_address',
            'building_name_detected_as_ca_name',
            'address_detected_as_ca_name',
        ]);
        $this->assertContains('building_name_detected_as_ca_name', $row['issue_codes']);
    }

    public function test_firm_name_person_extraction_conflict(): void
    {
        $firm = $this->seedFirm([
            'firm_name' => 'ABHISHEK P JAIN & ASSOCIATES',
            'city' => 'AHMEDABAD',
            'source_data' => [
                'raw' => ['ca_name' => 'ABHISHEK JAIN', 'firm_name' => 'ABHISHEK P JAIN & ASSOCIATES', 'city' => 'AHMEDABAD'],
                'parsed' => ['ca_name' => 'ABHISHEK P JAIN', 'firm_name' => 'ABHISHEK P JAIN & ASSOCIATES', 'city' => 'AHMEDABAD'],
            ],
            'match_reason' => 'Parsed value differs from preserved raw OCR value — silent correction blocked.',
        ]);

        $row = $this->audit->classifyRow($firm);
        $this->assertSame('firm_name_person_extraction_conflict', $row['primary_category']);
        $this->assertContains('initials_expansion', $row['issue_codes']);
        $this->assertSame(['P'], $row['token_report']['added']);
        $this->assertTrue($row['token_report']['compatible']);
    }

    public function test_parser_changed_raw_value_classification(): void
    {
        $firm = $this->seedFirm([
            'firm_name' => 'TEST FIRM LLP',
            'city' => 'SURAT',
            'source_data' => [
                'raw' => ['ca_name' => 'RAJESH KUMAR SHAH', 'city' => 'SURAT'],
                'parsed' => ['ca_name' => 'RAJESH SHAH', 'city' => 'SURAT'],
            ],
            'match_reason' => 'Parsed value differs from preserved raw OCR value — silent correction blocked.',
        ]);

        $row = $this->audit->classifyRow($firm);
        $this->assertSame('parser_changed_raw_value', $row['primary_category']);
    }

    public function test_invalid_json_and_missing_source_data_do_not_crash(): void
    {
        $firm = $this->seedFirm([
            'firm_name' => 'JSON BAD FIRM',
            'city' => 'PUNE',
            'source_data' => null,
            'match_reason' => 'ca_name: CA name is required.',
        ]);
        $row = $this->audit->classifyRow($firm);
        $this->assertTrue($row['missing_source_data']);
        $this->assertSame('missing_ca_name', $row['primary_category']);

        DB::table('ocr_parsed_firms')->where('id', $firm->id)->update([
            'source_data' => '{not-json',
        ]);
        $reloaded = OcrParsedFirm::query()->findOrFail($firm->id);
        $row2 = $this->audit->classifyRow($reloaded);
        $this->assertTrue($row2['invalid_json'] || $row2['missing_source_data']);
        $this->assertIsArray($row2['issue_codes']);
    }

    public function test_document_and_category_filters_and_csv_export(): void
    {
        $docA = $this->makeDocument('audit-a.pdf');
        $docB = $this->makeDocument('audit-b.pdf');
        $this->seedFirm([
            'ocr_document_id' => $docA->id,
            'firm_name' => 'DOC A FIRM',
            'city' => '',
            'source_data' => [
                'raw' => ['ca_name' => 'PERSON A', 'city' => ''],
                'parsed' => ['ca_name' => 'PERSON A', 'city' => ''],
            ],
            'match_reason' => 'city: City is required.',
        ]);
        $this->seedFirm([
            'ocr_document_id' => $docB->id,
            'firm_name' => 'DOC B FIRM',
            'city' => 'MUMBAI',
            'source_data' => [
                'raw' => ['ca_name' => '308 TODAY SQUARE', 'city' => 'MUMBAI'],
                'parsed' => ['ca_name' => 'TODAY SQUARE', 'city' => 'MUMBAI'],
            ],
            'match_reason' => 'Parsed value differs from preserved raw OCR value — silent correction blocked.',
        ]);

        $export = storage_path('app/ocr-audits/test-unlinked-ca-audit.csv');
        @unlink($export);

        $report = $this->audit->audit([
            'document' => (int) $docB->id,
            'category' => 'numeric_prefix_address',
            'export' => $export,
            'sample_limit' => 10,
        ]);

        $this->assertGreaterThanOrEqual(1, $report['totals']['emitted']);
        $this->assertSame(0, $report['counts']['missing_city']);
        $this->assertFileExists($export);
        $csv = file_get_contents($export);
        $this->assertIsString($csv);
        $this->assertStringContainsString('primary_category', $csv);
        $this->assertStringContainsString('numeric_prefix_address', $csv);

        $before = OcrParsedFirm::query()->whereNull('crm_ca_id')->count();
        $this->artisan('ocr:audit-unlinked-ca-names', [
            '--document' => $docA->id,
            '--summary-only' => true,
        ])->assertSuccessful();
        $after = OcrParsedFirm::query()->whereNull('crm_ca_id')->count();
        $this->assertSame($before, $after);
    }

    public function test_linked_rows_are_excluded_and_raw_unchanged(): void
    {
        $linked = $this->seedFirm([
            'firm_name' => 'LINKED FIRM',
            'city' => 'DELHI',
            'source_data' => [
                'raw' => ['ca_name' => 'LINKED CA', 'city' => 'DELHI'],
                'parsed' => ['ca_name' => 'LINKED CA', 'city' => 'DELHI'],
            ],
            'match_status' => 'imported',
        ]);
        // Mark linked without requiring a real ca_masters FK row when SQLite allows.
        DB::table('ocr_parsed_firms')->where('id', $linked->id)->update(['crm_ca_id' => 1]);
        $linked->refresh();
        $rawBefore = $linked->source_data;

        $report = $this->audit->audit(['document' => (int) $linked->ocr_document_id]);
        $ids = collect($report['samples'])->pluck('id')->all();
        $this->assertNotContains((int) $linked->id, $ids);
        $this->assertSame(0, $report['totals']['total_unlinked']);

        $linked->refresh();
        $this->assertEquals($rawBefore, $linked->source_data);
    }

    public function test_summary_counts_are_correct(): void
    {
        $doc = $this->makeDocument('summary.pdf');
        $this->seedFirm([
            'ocr_document_id' => $doc->id,
            'firm_name' => 'F1',
            'city' => 'X',
            'source_data' => ['raw' => ['ca_name' => ''], 'parsed' => ['ca_name' => '']],
            'match_reason' => 'ca_name: CA name is required.',
        ]);
        $this->seedFirm([
            'ocr_document_id' => $doc->id,
            'firm_name' => 'F2',
            'city' => '',
            'source_data' => [
                'raw' => ['ca_name' => 'NAME TWO', 'city' => ''],
                'parsed' => ['ca_name' => 'NAME TWO', 'city' => ''],
            ],
            'match_reason' => 'city: City is required.',
        ]);

        $report = $this->audit->audit(['document' => (int) $doc->id]);
        $this->assertSame(2, $report['totals']['total_unlinked']);
        $this->assertSame(2, $report['totals']['categorized']);
        $this->assertSame(1, $report['counts']['missing_ca_name']);
        $this->assertSame(1, $report['counts']['missing_city']);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function seedFirm(array $attrs): OcrParsedFirm
    {
        $documentId = $attrs['ocr_document_id'] ?? $this->makeDocument()->id;
        unset($attrs['ocr_document_id']);

        return OcrParsedFirm::query()->create(array_merge([
            'ocr_document_id' => $documentId,
            'sequence_no' => random_int(1, 999999),
            'firm_name' => 'DEFAULT FIRM',
            'review_status' => OcrParsedFirm::REVIEW_PENDING,
            'match_status' => 'needs_review',
            'overall_confidence' => 0.8,
            'crm_ca_id' => null,
        ], $attrs));
    }

    private function makeDocument(string $name = 'audit.pdf'): OcrDocument
    {
        $admin = User::query()->first() ?: CrmTestAccounts::ensureAdmin();

        return OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => $admin->id,
            'original_filename' => $name,
            'stored_filename' => $name,
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/'.$name,
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'status' => OcrDocument::STATUS_COMPLETED,
            'import_type' => OcrDocument::IMPORT_MASTER_CA,
            'parse_status' => 'completed',
            'processing_progress' => 'Completed',
            'checksum' => hash('sha256', $name.uniqid('', true)),
            'processed_at' => now(),
        ]);
    }
}
