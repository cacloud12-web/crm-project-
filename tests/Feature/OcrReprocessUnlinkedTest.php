<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Models\OcrStagingCorrectionAudit;
use App\Models\User;
use App\Services\Ocr\OcrReprocessUnlinkedService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CrmTestAccounts;
use Tests\TestCase;

class OcrReprocessUnlinkedTest extends TestCase
{
    use DatabaseTransactions;

    private OcrReprocessUnlinkedService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->service = app(OcrReprocessUnlinkedService::class);
        if (! Schema::hasTable('ocr_staging_correction_audits')) {
            $migration = require database_path('migrations/2026_07_23_003800_create_ocr_staging_correction_audits_table.php');
            $migration->up();
        }
    }

    public function test_dry_run_changes_no_rows(): void
    {
        $firm = $this->seedAddressMisclassified();
        $before = $firm->toArray();
        $mastersBefore = $this->caMastersCount();

        $report = $this->service->reprocess([
            'document' => (int) $firm->ocr_document_id,
            'category' => 'numeric_prefix_address',
            'dry_run' => true,
            'apply_safe_only' => false,
        ]);

        $firm->refresh();
        $this->assertGreaterThan(0, $report['totals']['would_change']);
        $this->assertSame(0, $report['totals']['applied']);
        $this->assertEquals($before['source_data'], $firm->source_data);
        $this->assertSame($before['match_status'], $firm->match_status);
        $this->assertSame($mastersBefore, $this->caMastersCount());
        $this->assertSame(0, OcrStagingCorrectionAudit::query()->where('ocr_parsed_firm_id', $firm->id)->count());
    }

    public function test_apply_safe_only_changes_only_unlinked_and_preserves_raw(): void
    {
        $unlinked = $this->seedAddressMisclassified();
        $linked = $this->seedAddressMisclassified(['firm_name' => 'LINKED BUILDING FIRM']);
        DB::table('ocr_parsed_firms')->where('id', $linked->id)->update(['crm_ca_id' => 1]);
        $linked->refresh();
        $linkedBefore = json_encode($linked->getAttributes());
        $rawBefore = $unlinked->source_data['raw'] ?? [];
        $mastersBefore = $this->caMastersCount();

        $report = $this->service->reprocess([
            'document' => (int) $unlinked->ocr_document_id,
            'category' => 'numeric_prefix_address',
            'dry_run' => false,
            'apply_safe_only' => true,
            'actor' => 1,
        ]);

        $unlinked->refresh();
        $linked->refresh();
        $this->assertGreaterThan(0, $report['totals']['applied']);
        $this->assertNull($unlinked->crm_ca_id);
        $this->assertSame($rawBefore, $unlinked->source_data['raw'] ?? null);
        $this->assertNull($unlinked->source_data['parsed']['ca_name'] ?? null);
        $this->assertStringContainsString('NIRMAN', (string) $unlinked->address);
        $this->assertSame($linkedBefore, json_encode($linked->getAttributes()));
        $this->assertSame($mastersBefore, $this->caMastersCount());
        $this->assertSame(1, OcrStagingCorrectionAudit::query()
            ->where('ocr_parsed_firm_id', $unlinked->id)
            ->where('dry_run', false)
            ->count());
    }

    public function test_section_heading_city_carry_forward_and_boundary(): void
    {
        $docA = $this->makeDocument('city-a.pdf');
        $docB = $this->makeDocument('city-b.pdf');

        // Linked row with section city — must seed context without being mutated.
        $linkedCity = $this->seedFirm([
            'ocr_document_id' => $docA->id,
            'firm_name' => 'HAS CITY FIRM',
            'city' => 'AHMEDABAD',
            'page_number' => 1,
            'column_number' => 1,
            'field_meta' => ['city' => ['reason' => 'section_heading', 'value' => 'AHMEDABAD']],
            'source_data' => [
                'raw' => ['ca_name' => 'RAM SHAH', 'city' => 'AHMEDABAD', 'firm_name' => 'HAS CITY FIRM'],
                'parsed' => ['ca_name' => 'RAM SHAH', 'city' => 'AHMEDABAD', 'firm_name' => 'HAS CITY FIRM'],
            ],
            'match_status' => 'imported',
        ]);
        DB::table('ocr_parsed_firms')->where('id', $linkedCity->id)->update(['crm_ca_id' => 1]);

        $missing = $this->seedFirm([
            'ocr_document_id' => $docA->id,
            'firm_name' => 'MISSING CITY FIRM',
            'city' => null,
            'page_number' => 1,
            'column_number' => 1,
            'source_data' => [
                'raw' => ['ca_name' => 'SITA SHAH', 'city' => '', 'firm_name' => 'MISSING CITY FIRM'],
                'parsed' => ['ca_name' => 'SITA SHAH', 'city' => '', 'firm_name' => 'MISSING CITY FIRM'],
            ],
            'match_reason' => 'city: City is required.',
        ]);
        $otherDoc = $this->seedFirm([
            'ocr_document_id' => $docB->id,
            'firm_name' => 'OTHER DOC FIRM',
            'city' => null,
            'page_number' => 1,
            'column_number' => 1,
            'source_data' => [
                'raw' => ['ca_name' => 'OTHER CA', 'city' => '', 'firm_name' => 'OTHER DOC FIRM'],
                'parsed' => ['ca_name' => 'OTHER CA', 'city' => '', 'firm_name' => 'OTHER DOC FIRM'],
            ],
            'match_reason' => 'city: City is required.',
        ]);

        $payload = $this->service->suggestionPayload($missing);
        $this->assertSame('missing_city', $payload['issue_category']);

        $report = $this->service->reprocess([
            'document' => (int) $docA->id,
            'category' => 'missing_city',
            'dry_run' => true,
        ]);
        $this->assertGreaterThan(0, $report['categories']['missing_city']['would_recover_city']);

        $reportB = $this->service->reprocess([
            'document' => (int) $docB->id,
            'category' => 'missing_city',
            'dry_run' => true,
        ]);
        // No heading in doc B → no city invented across documents.
        $this->assertSame(0, $reportB['categories']['missing_city']['would_recover_city']);
        $otherDoc->refresh();
        $this->assertTrue($otherDoc->city === null || $otherDoc->city === '');
        $linkedCity->refresh();
        $this->assertSame(1, (int) $linkedCity->crm_ca_id);
        $this->assertSame('AHMEDABAD', $linkedCity->city);
    }

    public function test_ambiguous_city_stays_review(): void
    {
        $doc = $this->makeDocument('ambig-city.pdf');
        $this->seedFirm([
            'ocr_document_id' => $doc->id,
            'firm_name' => 'HEADING CITY',
            'city' => 'SURAT',
            'page_number' => 1,
            'column_number' => 1,
            'field_meta' => ['city' => ['reason' => 'section_heading', 'value' => 'SURAT']],
            'source_data' => [
                'raw' => ['ca_name' => 'A B', 'city' => 'SURAT'],
                'parsed' => ['ca_name' => 'A B', 'city' => 'SURAT'],
            ],
        ]);
        $this->seedFirm([
            'ocr_document_id' => $doc->id,
            'firm_name' => 'PAGE CITY',
            'city' => 'AHMEDABAD',
            'page_number' => 1,
            'column_number' => 1,
            'source_data' => [
                'raw' => ['ca_name' => 'C D', 'city' => 'AHMEDABAD'],
                'parsed' => ['ca_name' => 'C D', 'city' => 'AHMEDABAD'],
            ],
        ]);
        $missing = $this->seedFirm([
            'ocr_document_id' => $doc->id,
            'firm_name' => 'AMBIG FIRM',
            'city' => null,
            'page_number' => 1,
            'column_number' => 1,
            'source_data' => [
                'raw' => ['ca_name' => 'E F', 'city' => ''],
                'parsed' => ['ca_name' => 'E F', 'city' => ''],
            ],
            'match_reason' => 'city: City is required.',
        ]);

        $report = $this->service->reprocess([
            'document' => (int) $doc->id,
            'category' => 'missing_city',
            'dry_run' => true,
        ]);
        $this->assertSame(0, $report['categories']['missing_city']['would_recover_city']);
        $missing->refresh();
        $this->assertTrue($missing->city === null || $missing->city === '');
    }

    public function test_reprocess_scope_by_category_and_chunk(): void
    {
        $doc = $this->makeDocument('scope.pdf');
        $this->seedAddressMisclassified(['ocr_document_id' => $doc->id, 'firm_name' => 'ADDR 1']);
        $this->seedFirm([
            'ocr_document_id' => $doc->id,
            'firm_name' => 'NO CA FIRM & ASSOCIATES',
            'city' => 'AHMEDABAD',
            'source_data' => [
                'raw' => ['firm_name' => 'NO CA FIRM & ASSOCIATES', 'ca_name' => '', 'city' => 'AHMEDABAD'],
                'parsed' => ['firm_name' => 'NO CA FIRM & ASSOCIATES', 'ca_name' => '', 'city' => 'AHMEDABAD'],
            ],
            'match_reason' => 'ca_name: CA name is required.',
        ]);

        $byCat = $this->service->reprocess([
            'document' => (int) $doc->id,
            'category' => 'numeric_prefix_address',
            'dry_run' => true,
            'chunk' => 50,
        ]);
        $this->assertGreaterThan(0, $byCat['categories']['numeric_prefix_address']['rows_analyzed']);
        $this->assertSame(0, $byCat['categories']['missing_ca_name']['rows_analyzed']);
    }

    public function test_firm_conflict_suggests_derived_ca_keeps_review(): void
    {
        $firm = $this->seedFirm([
            'firm_name' => 'ABHISHEK P JAIN & ASSOCIATES',
            'city' => 'AHMEDABAD',
            'source_data' => [
                'raw' => [
                    'firm_name' => 'ABHISHEK P JAIN & ASSOCIATES',
                    'ca_name' => 'ABHISHEK JAIN',
                    'city' => 'AHMEDABAD',
                ],
                'parsed' => [
                    'firm_name' => 'ABHISHEK P JAIN & ASSOCIATES',
                    'ca_name' => 'ABHISHEK P JAIN',
                    'city' => 'AHMEDABAD',
                ],
            ],
            'match_reason' => 'Parsed value differs from preserved raw OCR value — silent correction blocked.',
            'match_status' => 'needs_review',
        ]);

        $report = $this->service->reprocess([
            'document' => (int) $firm->ocr_document_id,
            'category' => 'firm_name_person_extraction_conflict',
            'dry_run' => true,
        ]);
        $bucket = $report['categories']['firm_name_person_extraction_conflict'];
        $this->assertGreaterThan(0, $bucket['would_suggest_derived_ca']);
        $this->assertGreaterThan(0, $bucket['would_remain_needs_review']);
        $payload = $this->service->suggestionPayload($firm);
        $this->assertSame('ABHISHEK P JAIN', $payload['suggested_ca']);
        $this->assertTrue($payload['manual_review_required']);
    }

    public function test_artisan_dry_run_command(): void
    {
        $firm = $this->seedAddressMisclassified();
        $this->artisan('ocr:reprocess-unlinked', [
            '--document' => $firm->ocr_document_id,
            '--category' => 'numeric_prefix_address',
            '--dry-run' => true,
        ])->assertSuccessful();
        $firm->refresh();
        $this->assertSame('14 NIRMAN SQUARE TENAMENT', $firm->source_data['raw']['ca_name'] ?? null);
    }

    /** @param  array<string, mixed>  $overrides */
    private function seedAddressMisclassified(array $overrides = []): OcrParsedFirm
    {
        return $this->seedFirm(array_merge([
            'firm_name' => 'A P SHAH & ASSOCIATES',
            'city' => 'AHMEDABAD',
            'source_data' => [
                'raw' => [
                    'ca_name' => '14 NIRMAN SQUARE TENAMENT',
                    'firm_name' => 'A P SHAH & ASSOCIATES',
                    'city' => 'AHMEDABAD',
                ],
                'parsed' => [
                    'ca_name' => 'NIRMAN SQUARE TENAMENT',
                    'firm_name' => 'A P SHAH & ASSOCIATES',
                    'city' => 'AHMEDABAD',
                ],
            ],
            'match_reason' => 'Parsed value differs from preserved raw OCR value — silent correction blocked.',
            'match_status' => 'needs_review',
        ], $overrides));
    }

    /** @param  array<string, mixed>  $attrs */
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

    private function makeDocument(string $name = 'reprocess.pdf'): OcrDocument
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

    private function caMastersCount(): int
    {
        if (! class_exists(CaMaster::class) || ! Schema::hasTable('ca_masters')) {
            return (int) DB::table('ca_masters')->count();
        }

        return (int) CaMaster::query()->count();
    }
}
