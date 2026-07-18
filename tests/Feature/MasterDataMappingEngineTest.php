<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Models\CaMaster;
use App\Models\MasterMappingDecision;
use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Models\User;
use App\Services\Mapping\MasterDataMappingService;
use App\Services\Ocr\OcrStructurePersistService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MasterDataMappingEngineTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'crm_mapping.queue_after_ocr_parse' => true,
            'crm_mapping.auto_apply_exact' => true,
            'crm_mapping.auto_create_unmatched' => true,
            'crm_mapping.auto_update_min_confidence' => 0.90,
            'crm_mapping.review_min_confidence' => 0.55,
            'crm_mapping.sync_max_firms' => 50,
            'ocr_safety.require_verification' => false,
            'ocr_safety.auto_create' => true,
            'ocr_safety.auto_update' => true,
            'ocr_safety.allow_bulk_approve_safe' => true,
            'ocr_safety.min_required_field_confidence' => 0.90,
        ]);
        app(\App\Services\Rbac\RbacDatabaseService::class)->ensureConfigDefaultGrants();
        app(\App\Services\Rbac\RbacMatrixService::class)->flushCache();
    }

    private function actingAsAdmin(): User
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        return $admin;
    }

    public function test_ocr_parse_auto_creates_unmatched_master_record(): void
    {
        if (! Schema::hasColumn('ocr_parsed_firms', 'match_status')) {
            $this->markTestSkipped('Mapping engine migration not applied.');
        }

        config(['crm_mapping.queue_after_ocr_parse' => false]);
        $admin = $this->actingAsAdmin();
        $before = CaMaster::query()->count();

        $unique = 'UNIQUE MAPPING FIRM '.strtoupper(uniqid()).' & CO';
        $document = OcrDocument::query()->create([
            'uploaded_by' => $admin->id,
            'original_filename' => 'map-auto.pdf',
            'stored_filename' => 'map-auto.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/map-auto-'.uniqid('', true).'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 800,
            'status' => OcrDocument::STATUS_COMPLETED,
            'extracted_text' => "MAHARASHTRA\n{$unique}\nRAJ UNIQUE ".uniqid()."\n",
            'processed_at' => now(),
        ]);

        app(OcrStructurePersistService::class)->parseAndPersist($document);

        $firm = OcrParsedFirm::query()->where('ocr_document_id', $document->id)->firstOrFail();
        $firm->update([
            'firm_name' => $unique,
            'normalized_firm_name' => mb_strtoupper($unique),
            'overall_confidence' => 0.96,
            'field_meta' => [
                'firm_name' => ['confidence' => 0.97, 'value' => $unique],
                'state' => ['confidence' => 0.95, 'value' => 'Maharashtra'],
            ],
            'state' => 'Maharashtra',
            'frn' => 'FRN'.random_int(100000, 999999),
            'source_data' => array_merge(is_array($firm->source_data) ? $firm->source_data : [], [
                'validation' => ['ok' => true, 'auto_apply_ok' => true, 'errors' => [], 'warnings' => [], 'fields' => []],
            ]),
            'match_status' => null,
            'crm_ca_id' => null,
            'review_status' => OcrParsedFirm::REVIEW_PENDING,
        ]);
        app(MasterDataMappingService::class)->processOcrDocument((int) $document->id, (int) $admin->id);
        $firm->refresh();

        $this->assertSame('auto_created', $firm->match_status);
        $this->assertNotNull($firm->crm_ca_id);
        $this->assertSame(OcrParsedFirm::REVIEW_APPROVED, $firm->review_status);
        $this->assertSame($before + 1, CaMaster::query()->count());
        $this->assertTrue(CaMaster::query()->where('ca_id', $firm->crm_ca_id)->exists());
    }

    public function test_ocr_parse_auto_updates_exact_gst_match(): void
    {
        if (! Schema::hasColumn('ocr_parsed_firms', 'match_status')) {
            $this->markTestSkipped('Mapping engine migration not applied.');
        }

        $admin = $this->actingAsAdmin();
        $existing = CaMaster::query()->create([
            'ca_name' => 'Old Name',
            'firm_name' => 'GST Match Firm',
            'normalized_firm_name' => 'GST MATCH FIRM',
            'gst_no' => '27EEEEEE4444E1Z5',
            'address' => null,
            'status' => 'New',
            'rating' => 1,
        ]);
        $before = CaMaster::query()->count();

        config(['crm_mapping.queue_after_ocr_parse' => false]);
        $document = OcrDocument::query()->create([
            'uploaded_by' => $admin->id,
            'original_filename' => 'map-gst.pdf',
            'stored_filename' => 'map-gst.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/map-gst.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 800,
            'status' => OcrDocument::STATUS_COMPLETED,
            'extracted_text' => "MUMBAI\nGST MATCH FIRM\nPARTNER ONE\n12 MG ROAD\n400001\n",
            'processed_at' => now(),
        ]);

        app(OcrStructurePersistService::class)->parseAndPersist($document);
        $firm = OcrParsedFirm::query()->where('ocr_document_id', $document->id)->firstOrFail();
        $firm->update([
            'gst_no' => '27EEEEEE4444E1Z5',
            'address' => '12 MG ROAD',
            'state' => 'Maharashtra',
            'overall_confidence' => 0.95,
            'field_meta' => [
                'firm_name' => ['confidence' => 0.95],
                'gst_no' => ['confidence' => 0.98],
                'state' => ['confidence' => 0.95],
            ],
            'source_data' => array_merge(is_array($firm->source_data) ? $firm->source_data : [], [
                'validation' => ['ok' => true, 'auto_apply_ok' => true, 'errors' => [], 'warnings' => [], 'fields' => []],
            ]),
            'review_status' => 'pending',
            'crm_ca_id' => null,
            'match_status' => null,
        ]);

        app(MasterDataMappingService::class)->processOcrDocument((int) $document->id, (int) $admin->id);
        $firm->refresh();

        $this->assertSame('auto_mapped', $firm->match_status);
        $this->assertSame((int) $existing->ca_id, (int) $firm->crm_ca_id);
        $this->assertSame($before, CaMaster::query()->count());
        $existing->refresh();
        $this->assertSame('12 MG ROAD', $existing->address);
    }

    public function test_process_batch_writes_audit_decisions(): void
    {
        if (! Schema::hasTable('master_mapping_decisions')) {
            $this->markTestSkipped('master_mapping_decisions table missing.');
        }

        $service = app(MasterDataMappingService::class);
        $before = MasterMappingDecision::query()->count();

        $stats = $service->processBatch('csv', 'unit-batch-1', [
            [
                'firm_name' => 'CSV Import Firm One',
                'ca_name' => 'CSV Partner',
                'email' => 'csv.firm.one@example.test',
            ],
        ]);

        $this->assertSame(1, $stats['processed']);
        $this->assertSame(1, $stats['auto_created']);
        $this->assertGreaterThan($before, MasterMappingDecision::query()->count());
        $this->assertDatabaseHas('master_mapping_decisions', [
            'source_type' => 'csv',
            'source_ref' => 'unit-batch-1',
            'decision' => MasterMappingDecision::DECISION_AUTO_CREATE,
        ]);
    }

    public function test_conflict_goes_to_manual_review_without_creating(): void
    {
        if (! Schema::hasColumn('ocr_parsed_firms', 'match_status')) {
            $this->markTestSkipped('Mapping engine migration not applied.');
        }

        $admin = $this->actingAsAdmin();
        CaMaster::query()->create([
            'ca_name' => 'P1', 'firm_name' => 'Conflict A', 'frn' => 'FRNCONFLICT1', 'status' => 'New', 'rating' => 1,
        ]);
        CaMaster::query()->create([
            'ca_name' => 'P2', 'firm_name' => 'Conflict B', 'frn' => 'FRNCONFLICT1', 'status' => 'New', 'rating' => 1,
        ]);
        $before = CaMaster::query()->count();

        $stats = app(MasterDataMappingService::class)->processBatch('api', 'conflict-1', [
            [
                'staging_id' => null,
                'firm_name' => 'Incoming Conflict Firm',
                'frn' => 'FRNCONFLICT1',
            ],
        ], (int) $admin->id);

        $this->assertSame(1, $stats['conflicts']);
        $this->assertSame(0, $stats['auto_created']);
        $this->assertSame($before, CaMaster::query()->count());
    }
}
