<?php

namespace Tests\Feature;

use App\Jobs\ImportMasterCaOcrJob;
use App\Jobs\MapOcrParsedFirmsJob;
use App\Models\CaMaster;
use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Models\OcrParsedMember;
use App\Models\State;
use App\Models\User;
use App\Services\Ocr\MasterCaDirectImportService;
use App\Services\Ocr\OcrStructurePersistService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OcrImportModeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'document-ai.project_id' => 'test-project',
            'document-ai.processor_id' => 'test-processor',
            'document-ai.location' => 'us',
            'document-ai.credentials' => $this->writeFakeCredentials(),
            'document-ai.max_file_mb' => 10,
            'document-ai.sync_small_files' => false,
            // Mode tests assert intentional Master CA auto-import when policy allows.
            'ocr_safety.require_verification' => false,
            'ocr_safety.auto_create' => true,
            'ocr_safety.auto_update' => true,
            'ocr_safety.allow_bulk_approve_safe' => true,
            'ocr_safety.min_required_field_confidence' => 0.90,
        ]);
        app(\App\Services\Rbac\RbacDatabaseService::class)->ensureConfigDefaultGrants();
        app(\App\Services\Rbac\RbacMatrixService::class)->flushCache();
    }

    private function writeFakeCredentials(): string
    {
        $path = storage_path('app/testing-document-ai-credentials.json');
        if (! is_file($path)) {
            file_put_contents($path, json_encode([
                'type' => 'service_account',
                'project_id' => 'test-project',
                'private_key_id' => 'test',
                'private_key' => "-----BEGIN PRIVATE KEY-----\nMIIE\n-----END PRIVATE KEY-----\n",
                'client_email' => 'ocr@test-project.iam.gserviceaccount.com',
                'client_id' => '1',
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ], JSON_PRETTY_PRINT));
        }

        return $path;
    }

    private function actingAsAdmin(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);
    }

    private function createParsedDocument(string $importType, array $firmAttrs = []): OcrDocument
    {
        $doc = OcrDocument::query()->create([
            'uploaded_by' => User::query()->value('id'),
            'original_filename' => $importType.'-sample.pdf',
            'stored_filename' => $importType.'-sample.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/'.$importType.'-'.uniqid('', true).'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 2048,
            'checksum' => hash('sha256', $importType.uniqid('', true)),
            'status' => OcrDocument::STATUS_COMPLETED,
            'provider' => 'google_document_ai',
            'import_type' => Schema::hasColumn('ocr_documents', 'import_type') ? $importType : null,
            'processing_mode' => 'online',
            'extracted_text' => 'Sample OCR',
            'parse_status' => 'completed',
            'parsed_firm_count' => 1,
            'parsed_at' => now(),
            'processing_progress' => $importType === OcrDocument::IMPORT_MASTER_CA
                ? 'Validating official Master records'
                : 'Mapping to Master Data',
        ]);

        $stateId = State::query()->value('state_id');
        $stateName = State::query()->where('state_id', $stateId)->value('state_name');

        $firm = OcrParsedFirm::query()->create(array_merge([
            'ocr_document_id' => $doc->id,
            'sequence_no' => 1,
            'raw_firm_name' => 'Official Firm & Co',
            'firm_name' => 'Official Firm & Co',
            'normalized_firm_name' => 'OFFICIAL FIRM & CO',
            'frn' => 'FRN'.random_int(100000, 999999),
            'state' => $stateName,
            'city' => 'Test City',
            'address' => '1 Official Road',
            'match_status' => 'pending',
            'review_status' => OcrParsedFirm::REVIEW_PENDING,
            'overall_confidence' => 0.95,
        ], $firmAttrs));

        OcrParsedMember::query()->create([
            'ocr_parsed_firm_id' => $firm->id,
            'sequence_no' => 1,
            'raw_ca_name' => 'Official Partner',
            'ca_name' => 'Official Partner',
            'is_primary' => true,
            'membership_no' => 'MEM'.random_int(100000, 999999),
        ]);

        return $doc->fresh(['parsedFirms.members']);
    }

    public function test_upload_persists_master_ca_import_type(): void
    {
        if (! Schema::hasColumn('ocr_documents', 'import_type')) {
            $this->markTestSkipped('import_type column missing — run migration');
        }

        Queue::fake();
        Storage::fake('local');
        $this->actingAsAdmin();

        $response = $this->post('/ocr-documents', [
            'import_type' => 'master_ca',
            'document' => UploadedFile::fake()->createWithContent('master-a.pdf', '%PDF-1.4 master unique '.uniqid()),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonPath('data.import_type', 'master_ca');
        $this->assertDatabaseHas('ocr_documents', [
            'id' => $response->json('data.id'),
            'import_type' => 'master_ca',
        ]);
    }

    public function test_master_file_without_mobile_imports_directly_without_mapping_job(): void
    {
        if (! Schema::hasColumn('ocr_documents', 'import_type')) {
            $this->markTestSkipped('import_type column missing — run migration');
        }

        Queue::fake();
        $doc = $this->createParsedDocument(OcrDocument::IMPORT_MASTER_CA, [
            'phone' => null,
            'frn' => 'MASTERFRN'.random_int(10000, 99999),
        ]);
        $before = CaMaster::query()->count();

        $stats = app(MasterCaDirectImportService::class)->processDocument((int) $doc->id);

        $doc->refresh();
        $firm = $doc->parsedFirms->first();
        $this->assertSame(1, $stats['imported']);
        $this->assertSame(MasterCaDirectImportService::MATCH_IMPORTED, $firm->match_status);
        $this->assertNotNull($firm->crm_ca_id);
        $this->assertSame('Completed', $doc->processing_progress);
        $this->assertStringNotContainsString('mapping', mb_strtolower((string) $doc->processing_progress));
        $this->assertSame('completed', $doc->pipelineStage());
        $this->assertSame($before + 1, CaMaster::query()->count());

        $master = CaMaster::query()->find($firm->crm_ca_id);
        $this->assertNotNull($master);
        $this->assertTrue($master->mobile_no === null || $master->mobile_no === '');
        Queue::assertNotPushed(MapOcrParsedFirmsJob::class);
    }

    public function test_master_import_never_shows_queued_for_master_mapping(): void
    {
        if (! Schema::hasColumn('ocr_documents', 'import_type')) {
            $this->markTestSkipped('import_type column missing — run migration');
        }

        Queue::fake();
        $doc = $this->createParsedDocument(OcrDocument::IMPORT_MASTER_CA);
        $persist = app(OcrStructurePersistService::class);
        $method = new \ReflectionMethod($persist, 'dispatchPostParse');
        $method->setAccessible(true);
        $method->invoke($persist, $doc->fresh(), 1);

        $doc->refresh();
        $this->assertStringNotContainsString('Queued for Master mapping', (string) $doc->processing_progress);
        $this->assertStringNotContainsString('Queued for sales-team', (string) $doc->processing_progress);
        Queue::assertNotPushed(MapOcrParsedFirmsJob::class);
    }

    public function test_reimporting_same_master_identity_does_not_duplicate(): void
    {
        if (! Schema::hasColumn('ocr_documents', 'import_type')) {
            $this->markTestSkipped('import_type column missing — run migration');
        }

        $frn = 'DUPFRN'.random_int(100000, 999999);
        $doc1 = $this->createParsedDocument(OcrDocument::IMPORT_MASTER_CA, ['frn' => $frn]);
        app(MasterCaDirectImportService::class)->processDocument((int) $doc1->id);
        $countAfterFirst = CaMaster::query()->where('frn', $frn)->count();
        $this->assertSame(1, $countAfterFirst);

        $doc2 = $this->createParsedDocument(OcrDocument::IMPORT_MASTER_CA, ['frn' => $frn]);
        $stats = app(MasterCaDirectImportService::class)->processDocument((int) $doc2->id);

        $this->assertSame(0, $stats['imported']);
        $this->assertGreaterThanOrEqual(1, $stats['duplicates'] + $stats['updated']);
        $this->assertSame(1, CaMaster::query()->where('frn', $frn)->count());
        $this->assertSame(
            MasterCaDirectImportService::MATCH_DUPLICATE,
            $doc2->parsedFirms->first()->fresh()->match_status,
        );
    }

    public function test_sales_team_file_triggers_mapping_profile(): void
    {
        if (! Schema::hasColumn('ocr_documents', 'import_type')) {
            $this->markTestSkipped('import_type column missing — run migration');
        }

        Queue::fake();
        $stateId = State::query()->value('state_id');
        $stateName = State::query()->where('state_id', $stateId)->value('state_name');

        $master = CaMaster::query()->create([
            'ca_name' => 'Sales Match CA',
            'firm_name' => 'Sales Match Firm & Co',
            'normalized_firm_name' => 'SALES MATCH FIRM & CO',
            'normalized_ca_name' => Schema::hasColumn('ca_masters', 'normalized_ca_name') ? 'SALES MATCH CA' : null,
            'state_id' => $stateId,
            'status' => 'New',
            'rating' => 1,
            'mobile_no' => null,
        ]);

        $doc = $this->createParsedDocument(OcrDocument::IMPORT_SALES_TEAM, [
            'firm_name' => 'Sales Match Firm and Company',
            'normalized_firm_name' => 'SALES MATCH FIRM & CO',
            'frn' => null,
            'state' => $stateName,
            'phone' => '9876501234',
        ]);
        $doc->parsedFirms->first()->members->first()->update([
            'ca_name' => 'Sales Match CA',
            'raw_ca_name' => 'Sales Match CA',
            'membership_no' => null,
        ]);

        $stats = app(\App\Services\Mapping\MasterDataMappingService::class)->processOcrDocument((int) $doc->id);
        $this->assertSame(1, $stats['auto_updated']);
        $master->refresh();
        $this->assertSame('9876501234', preg_replace('/\D+/', '', (string) $master->mobile_no));
        $this->assertSame('Sales Match Firm & Co', $master->firm_name);
    }

    public function test_process_ocr_document_rejects_master_ca_mode(): void
    {
        if (! Schema::hasColumn('ocr_documents', 'import_type')) {
            $this->markTestSkipped('import_type column missing — run migration');
        }

        $doc = $this->createParsedDocument(OcrDocument::IMPORT_MASTER_CA);
        $this->expectException(\InvalidArgumentException::class);
        app(\App\Services\Mapping\MasterDataMappingService::class)->processOcrDocument((int) $doc->id);
    }

    public function test_map_job_reroutes_master_ca_to_direct_import_job(): void
    {
        if (! Schema::hasColumn('ocr_documents', 'import_type')) {
            $this->markTestSkipped('import_type column missing — run migration');
        }

        Queue::fake();
        $doc = $this->createParsedDocument(OcrDocument::IMPORT_MASTER_CA);
        (new MapOcrParsedFirmsJob((int) $doc->id))->handle(app(\App\Services\Mapping\MasterDataMappingService::class));
        Queue::assertPushed(ImportMasterCaOcrJob::class);
    }
}
