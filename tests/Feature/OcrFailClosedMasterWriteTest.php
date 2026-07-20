<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Models\OcrParsedMember;
use App\Services\Ocr\MasterCaDirectImportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CrmTestAccounts;
use Tests\TestCase;

class OcrFailClosedMasterWriteTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'ocr_safety.require_verification' => true,
            'ocr_safety.auto_create' => false,
            'ocr_safety.auto_update' => false,
            'ocr_safety.reject_on_field_collision' => true,
            'crm_mapping.queue_after_ocr_parse' => false,
        ]);
        app(\App\Services\Rbac\RbacDatabaseService::class)->ensureConfigDefaultGrants();
        app(\App\Services\Rbac\RbacMatrixService::class)->flushCache();
    }

    public function test_low_confidence_and_unverified_rows_do_not_update_master_data(): void
    {
        if (! Schema::hasTable('ocr_parsed_firms') || ! Schema::hasTable('ca_masters')) {
            $this->markTestSkipped('Required tables missing.');
        }

        $admin = CrmTestAccounts::admin();
        $before = CaMaster::query()->count();

        $document = OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => $admin->id,
            'original_filename' => 'fail-closed.pdf',
            'stored_filename' => 'fail-closed.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/fail-closed.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size' => 100,
            'checksum' => hash('sha256', 'fail-closed-'.uniqid()),
            'status' => 'completed',
            'parse_status' => 'completed',
            'import_type' => OcrDocument::IMPORT_MASTER_CA,
            'processing_progress' => 'Validating official Master records',
            'processed_at' => now(),
            'extracted_text' => 'test',
        ]);

        $firm = OcrParsedFirm::query()->create([
            'ocr_document_id' => $document->id,
            'sequence_no' => 1,
            'firm_name' => 'Unverified Firm LLP',
            'frn' => 'FRNUNVER'.random_int(1000, 9999),
            'state' => 'Maharashtra',
            'overall_confidence' => 0.40,
            'review_status' => OcrParsedFirm::REVIEW_PENDING,
            'match_status' => null,
            'source_data' => [
                'validation' => [
                    'ok' => false,
                    'verified' => false,
                    'auto_apply_ok' => false,
                    'errors' => ['LOW_FIELD_CONFIDENCE'],
                ],
            ],
            'field_meta' => ['firm_name' => ['confidence' => 0.40]],
        ]);
        OcrParsedMember::query()->create([
            'ocr_parsed_firm_id' => $firm->id,
            'sequence_no' => 1,
            'ca_name' => 'Some CA',
            'membership_no' => 'MEM'.random_int(100000, 999999),
            'is_primary' => true,
            'review_status' => 'pending',
        ]);

        $stats = app(MasterCaDirectImportService::class)->processDocument((int) $document->id, (int) $admin->id);

        $this->assertSame(0, (int) ($stats['imported'] ?? 0));
        $this->assertGreaterThan(0, (int) ($stats['review'] ?? 0));
        $this->assertSame($before, CaMaster::query()->count());
        $firm->refresh();
        $this->assertNull($firm->crm_ca_id);
        $this->assertSame('needs_review', $firm->match_status);
    }

    public function test_rejected_collision_never_reaches_master_on_force_approve(): void
    {
        if (! Schema::hasTable('ocr_parsed_firms')) {
            $this->markTestSkipped('ocr_parsed_firms missing.');
        }

        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);
        $before = CaMaster::query()->count();

        $document = OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => $admin->id,
            'original_filename' => 'collision.pdf',
            'stored_filename' => 'collision.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/collision.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size' => 100,
            'checksum' => hash('sha256', 'collision-'.uniqid()),
            'status' => 'completed',
            'parse_status' => 'completed',
            'import_type' => OcrDocument::IMPORT_MASTER_CA,
            'processing_progress' => 'Completed',
            'processed_at' => now(),
            'extracted_text' => 'test',
        ]);

        $firm = OcrParsedFirm::query()->create([
            'ocr_document_id' => $document->id,
            'sequence_no' => 1,
            'firm_name' => 'Collision Firm',
            'address' => '12 MG Road',
            'pincode' => '400001',
            'review_status' => OcrParsedFirm::REVIEW_PENDING,
            'match_status' => 'needs_review',
        ]);
        OcrParsedMember::query()->create([
            'ocr_parsed_firm_id' => $firm->id,
            'sequence_no' => 1,
            'ca_name' => '12 MG Road Near Station PIN 400001',
            'membership_no' => '400001',
            'is_primary' => true,
            'review_status' => 'pending',
        ]);

        $this->patchJson('/ocr-documents/'.$document->id.'/firms/'.$firm->id.'/review', [
            'review_status' => 'approved',
        ])->assertStatus(422);

        $this->assertSame($before, CaMaster::query()->count());
        $firm->refresh();
        $this->assertNull($firm->crm_ca_id);
    }
}
