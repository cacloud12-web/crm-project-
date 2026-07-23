<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\OcrDocument;
use App\Models\OcrForcedReviewCandidate;
use App\Models\OcrParsedFirm;
use App\Models\OcrQuarantineImportBatch;
use App\Models\User;
use App\Services\Ocr\OcrQuarantinedBulkImportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CrmTestAccounts;
use Tests\TestCase;

class OcrQuarantineBulkImportTest extends TestCase
{
    use DatabaseTransactions;

    private OcrQuarantinedBulkImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->service = app(OcrQuarantinedBulkImportService::class);
        $migration = require database_path('migrations/2026_07_23_084500_create_ocr_quarantine_bulk_import_tables.php');
        if (! Schema::hasTable('ocr_forced_review_candidates')) {
            $migration->up();
        }
    }

    public function test_dry_run_writes_no_masters_and_no_candidates(): void
    {
        $mastersBefore = CaMaster::query()->count();
        $this->seedNeedsReview([
            'firm_name' => 'VALID FIRM & CO',
            'city' => 'AHMEDABAD',
            'source_data' => [
                'raw' => ['firm_name' => 'VALID FIRM & CO', 'ca_name' => 'VALID SHAH', 'city' => 'AHMEDABAD'],
                'parsed' => ['firm_name' => 'VALID FIRM & CO', 'ca_name' => 'VALID SHAH', 'city' => 'AHMEDABAD'],
            ],
        ]);
        $this->seedNeedsReview([
            'firm_name' => 'NO CA FIRM',
            'city' => 'SURAT',
            'source_data' => [
                'raw' => ['firm_name' => 'NO CA FIRM', 'ca_name' => '', 'city' => 'SURAT'],
                'parsed' => ['firm_name' => 'NO CA FIRM', 'ca_name' => '', 'city' => 'SURAT'],
            ],
            'match_reason' => 'ca_name: CA name is required.',
        ]);

        $report = $this->service->run([
            'dry_run' => true,
            'apply' => false,
            'batch_id' => 'test_dry_'.uniqid(),
            'document' => null,
            'limit' => 50,
        ]);

        $this->assertGreaterThan(0, $report['scanned']);
        $this->assertSame(0, OcrForcedReviewCandidate::query()->where('batch_id', $report['batch_id'])->count());
        $this->assertSame($mastersBefore, CaMaster::query()->count());
        $this->assertArrayHasKey('missing_ca', $report['flags']);
    }

    public function test_decide_blocks_address_as_ca_and_missing_ca(): void
    {
        $addr = $this->seedNeedsReview([
            'firm_name' => 'A P SHAH & ASSOCIATES',
            'city' => 'AHMEDABAD',
            'source_data' => [
                'raw' => ['ca_name' => '14 NIRMAN SQUARE TENAMENT', 'firm_name' => 'A P SHAH & ASSOCIATES', 'city' => 'AHMEDABAD'],
                'parsed' => ['ca_name' => '14 NIRMAN SQUARE TENAMENT', 'firm_name' => 'A P SHAH & ASSOCIATES', 'city' => 'AHMEDABAD'],
            ],
            'match_reason' => 'Parsed value differs from preserved raw OCR value — silent correction blocked.',
        ]);
        $missing = $this->seedNeedsReview([
            'firm_name' => 'EMPTY CA FIRM',
            'city' => 'PUNE',
            'source_data' => [
                'raw' => ['ca_name' => '', 'firm_name' => 'EMPTY CA FIRM', 'city' => 'PUNE'],
                'parsed' => ['ca_name' => '', 'firm_name' => 'EMPTY CA FIRM', 'city' => 'PUNE'],
            ],
            'match_reason' => 'ca_name: CA name is required.',
        ]);

        $d1 = $this->service->decide($addr);
        $this->assertSame('address_used_as_ca', $d1['category']);
        $this->assertFalse($d1['eligible_for_master']);

        $d2 = $this->service->decide($missing);
        $this->assertSame('missing_ca_name', $d2['category']);
        $this->assertFalse($d2['eligible_for_master']);
    }

    public function test_apply_quarantines_unsafe_and_never_overwrites_master(): void
    {
        $existing = CaMaster::query()->create([
            'firm_name' => 'EXISTING FIRM LLP',
            'ca_name' => 'EXISTING CA',
            'city' => 'DELHI',
            'normalized_firm_name' => 'EXISTING FIRM LLP',
            'normalized_ca_name' => 'EXISTING CA',
        ]);
        $mastersBefore = CaMaster::query()->count();
        $doc = $this->makeDocument();

        $dup = $this->seedNeedsReview([
            'ocr_document_id' => $doc->id,
            'firm_name' => 'EXISTING FIRM LLP',
            'city' => 'DELHI',
            'source_data' => [
                'raw' => ['firm_name' => 'EXISTING FIRM LLP', 'ca_name' => 'EXISTING CA', 'city' => 'DELHI'],
                'parsed' => ['firm_name' => 'EXISTING FIRM LLP', 'ca_name' => 'EXISTING CA', 'city' => 'DELHI'],
            ],
        ]);
        $unsafe = $this->seedNeedsReview([
            'ocr_document_id' => $doc->id,
            'firm_name' => 'MISSING CA ROW',
            'city' => 'DELHI',
            'source_data' => [
                'raw' => ['firm_name' => 'MISSING CA ROW', 'ca_name' => '', 'city' => 'DELHI'],
                'parsed' => ['firm_name' => 'MISSING CA ROW', 'ca_name' => '', 'city' => 'DELHI'],
            ],
            'match_reason' => 'ca_name: CA name is required.',
        ]);

        $batchId = 'test_apply_'.uniqid();
        OcrQuarantineImportBatch::query()->create([
            'batch_id' => $batchId,
            'status' => 'pending',
            'dry_run' => false,
            'chunk_size' => 100,
            'backup_paths' => ['crm' => storage_path('app/backups/fake.sql')],
        ]);

        $report = $this->service->run([
            'dry_run' => false,
            'apply' => true,
            'batch_id' => $batchId,
            'resume' => true,
            'document' => (int) $doc->id,
            'backup_paths' => ['crm' => storage_path('app/backups/fake.sql')],
        ]);

        $dup->refresh();
        $unsafe->refresh();
        $existing->refresh();

        $this->assertNull($dup->crm_ca_id);
        $this->assertNull($unsafe->crm_ca_id);
        $this->assertSame('EXISTING CA', $existing->ca_name);
        $this->assertSame($mastersBefore, CaMaster::query()->count());
        $this->assertGreaterThan(0, $report['quarantined']);
        $this->assertSame(
            1,
            OcrForcedReviewCandidate::query()
                ->where('batch_id', $batchId)
                ->where('ocr_parsed_firm_id', $unsafe->id)
                ->where('disposition', OcrForcedReviewCandidate::DISPOSITION_QUARANTINED)
                ->count()
        );
    }

    public function test_artisan_dry_run_stops_for_approval(): void
    {
        $this->seedNeedsReview([
            'firm_name' => 'CMD FIRM',
            'city' => 'MUMBAI',
            'source_data' => [
                'raw' => ['firm_name' => 'CMD FIRM', 'ca_name' => 'CMD CA', 'city' => 'MUMBAI'],
                'parsed' => ['firm_name' => 'CMD FIRM', 'ca_name' => 'CMD CA', 'city' => 'MUMBAI'],
            ],
        ]);

        $this->artisan('ocr:quarantine-bulk-import', [
            '--dry-run' => true,
            '--limit' => 20,
            '--batch-id' => 'cmd_dry_'.uniqid(),
        ])->assertSuccessful();
    }

    /** @param  array<string, mixed>  $attrs */
    private function seedNeedsReview(array $attrs): OcrParsedFirm
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

    private function makeDocument(string $name = 'quarantine.pdf'): OcrDocument
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
