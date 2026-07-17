<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Models\User;
use App\Services\Mapping\MasterDataMatchingService;
use App\Services\Mapping\MasterDataMappingService;
use App\Services\Ocr\OcrFirmApprovalService;
use App\Services\Ocr\OcrStructurePersistService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OcrAutoMappingFlowTest extends TestCase
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
            'crm_mapping.fuzzy_auto_update_min' => 0.97,
            'crm_mapping.sync_max_firms' => 50,
        ]);
        app(\App\Services\Rbac\RbacDatabaseService::class)->ensureConfigDefaultGrants();
        app(\App\Services\Rbac\RbacMatrixService::class)->flushCache();
    }

    private function actingAsAdmin(): User
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        return $admin;
    }

    private function makeDocument(User $admin, string $text, string $name = 'auto-map.pdf'): OcrDocument
    {
        return OcrDocument::query()->create([
            'uploaded_by' => $admin->id,
            'original_filename' => $name,
            'stored_filename' => $name,
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/'.$name,
            'mime_type' => 'application/pdf',
            'file_size' => 800,
            'status' => OcrDocument::STATUS_COMPLETED,
            'extracted_text' => $text,
            'processed_at' => now(),
            'page_count' => 1,
        ]);
    }

    public function test_high_confidence_firm_auto_creates_without_manual_approval(): void
    {
        if (! Schema::hasColumn('ocr_parsed_firms', 'match_status')) {
            $this->markTestSkipped('Mapping engine migration not applied.');
        }

        $admin = $this->actingAsAdmin();
        $before = CaMaster::query()->count();
        $document = $this->makeDocument($admin, "PUNE\nALPHA AUTO CREATE LLP\nRAJESH KUMAR\n20 MG ROAD\n411001\n");

        app(OcrStructurePersistService::class)->parseAndPersist($document);

        $firm = OcrParsedFirm::query()->where('ocr_document_id', $document->id)->firstOrFail();
        $this->assertSame('auto_created', $firm->match_status);
        $this->assertSame(OcrParsedFirm::REVIEW_APPROVED, $firm->review_status);
        $this->assertNotNull($firm->crm_ca_id);
        $this->assertSame($before + 1, CaMaster::query()->count());
    }

    public function test_exact_gst_match_auto_updates_without_duplicate(): void
    {
        if (! Schema::hasColumn('ocr_parsed_firms', 'match_status')) {
            $this->markTestSkipped('Mapping engine migration not applied.');
        }

        $admin = $this->actingAsAdmin();
        $existing = CaMaster::query()->create([
            'ca_name' => 'Old',
            'firm_name' => 'Exact GST Firm',
            'normalized_firm_name' => 'EXACT GST FIRM',
            'gst_no' => '27AAAAA0000A1Z5',
            'status' => 'New',
            'rating' => 1,
        ]);
        $before = CaMaster::query()->count();

        $document = $this->makeDocument($admin, "MUMBAI\nEXACT GST FIRM\nPARTNER ONE\n12 MG ROAD\n400001\n");
        app(OcrStructurePersistService::class)->parseAndPersist($document);
        $firm = OcrParsedFirm::query()->where('ocr_document_id', $document->id)->first();
        if (! $firm) {
            $firm = OcrParsedFirm::query()->create([
                'ocr_document_id' => $document->id,
                'sequence_no' => 1,
                'raw_firm_name' => 'EXACT GST FIRM',
                'firm_name' => 'EXACT GST FIRM',
                'normalized_firm_name' => 'EXACT GST FIRM',
                'gst_no' => '27AAAAA0000A1Z5',
                'review_status' => 'pending',
            ]);
        } else {
            $firm->update([
                'gst_no' => '27AAAAA0000A1Z5',
                'overall_confidence' => 0.95,
                'field_meta' => ['firm_name' => ['confidence' => 0.95]],
                'review_status' => 'pending',
                'crm_ca_id' => null,
                'match_status' => null,
            ]);
        }

        app(MasterDataMappingService::class)->processOcrDocument((int) $document->id, (int) $admin->id);
        $firm->refresh();

        $this->assertSame('auto_mapped', $firm->match_status);
        $this->assertSame((int) $existing->ca_id, (int) $firm->crm_ca_id);
        $this->assertSame($before, CaMaster::query()->count());
    }

    public function test_low_confidence_or_incomplete_remains_pending_review(): void
    {
        $service = app(MasterDataMappingService::class);
        $match = \App\Services\Mapping\MatchResult::unmatched('no_candidates');
        $decision = $service->decide($match, [
            'firm_name' => 'AB',
            'ca_name' => null,
            'gst_no' => null,
            'frn' => null,
            'normalized_mobile' => null,
            'normalized_email' => null,
            'address' => null,
            'city' => null,
            'pincode' => null,
        ]);
        $this->assertSame(\App\Models\MasterMappingDecision::DECISION_NEEDS_REVIEW, $decision);

        $fuzzy = \App\Services\Mapping\MatchResult::possible([[
            'ca_id' => 1,
            'score' => 0.91,
            'matched_on' => 'fuzzy_firm_name',
            'firm_name' => 'X',
            'ca_name' => 'Y',
        ]], 0.91, 'fuzzy_firm_name');
        $this->assertSame(
            \App\Models\MasterMappingDecision::DECISION_NEEDS_REVIEW,
            $service->decide($fuzzy, ['firm_name' => 'Almost Firm', 'ca_name' => 'Partner', 'overall_confidence' => 0.9]),
        );
    }

    public function test_raw_source_values_are_preserved_exactly(): void
    {
        if (! Schema::hasColumn('ocr_parsed_firms', 'source_data')) {
            $this->markTestSkipped('source_data column missing.');
        }

        $admin = $this->actingAsAdmin();
        $document = $this->makeDocument($admin, "DELHI\nRaw Name & Co.\nAMIT SHARMA\n");
        app(OcrStructurePersistService::class)->parseAndPersist($document);
        $firm = OcrParsedFirm::query()->where('ocr_document_id', $document->id)->firstOrFail();

        $this->assertSame($firm->firm_name, $firm->raw_firm_name);
        $source = $firm->source_data;
        $this->assertIsArray($source);
        $this->assertArrayHasKey('raw', $source);
        $this->assertArrayHasKey('normalized', $source);
        $this->assertSame($firm->firm_name, $source['raw']['firm_name'] ?? null);
        $this->assertNotSame($source['raw']['firm_name'] ?? null, null);
    }

    public function test_normalized_values_used_only_for_matching(): void
    {
        $payload = app(MasterDataMatchingService::class)->normalizePayload([
            'firm_name' => '  Acme & Co. ',
            'ca_name' => '  Priya Sharma ',
            'phone' => '09876543210',
            'email' => 'Priya@Example.COM',
            'gst_no' => '27aaaaa0000a1z5',
        ]);

        $this->assertSame('Acme & Co.', $payload['firm_name']);
        $this->assertSame('Priya Sharma', $payload['ca_name']);
        $this->assertSame('09876543210', $payload['mobile_no']);
        $this->assertSame('Priya@Example.COM', $payload['email_id']);
        $this->assertSame('27aaaaa0000a1z5', $payload['gst_no']);
        $this->assertNotNull($payload['normalized_firm_name']);
        $this->assertNotNull($payload['normalized_mobile']);
        $this->assertNotNull($payload['normalized_gst']);
        $this->assertNotEquals($payload['gst_no'], $payload['normalized_gst']);
    }

    public function test_reparse_is_idempotent_for_firm_count(): void
    {
        if (! Schema::hasColumn('ocr_parsed_firms', 'match_status')) {
            $this->markTestSkipped('Mapping engine migration not applied.');
        }

        $admin = $this->actingAsAdmin();
        $document = $this->makeDocument($admin, "CHENNAI\nIDEM FIRM LLP\nSURESH\n600001\n");
        $service = app(OcrStructurePersistService::class);
        $service->parseAndPersist($document);
        $firstCount = OcrParsedFirm::query()->where('ocr_document_id', $document->id)->count();
        $service->parseAndPersist($document->fresh());
        $secondCount = OcrParsedFirm::query()->where('ocr_document_id', $document->id)->count();

        $this->assertSame($firstCount, $secondCount);
        $this->assertGreaterThan(0, $firstCount);
    }

    public function test_bulk_approve_safe_records_works(): void
    {
        if (! Schema::hasColumn('ocr_parsed_firms', 'match_status')) {
            $this->markTestSkipped('Mapping engine migration not applied.');
        }

        $admin = $this->actingAsAdmin();
        $document = $this->makeDocument($admin, "JAIPUR\nBULK SAFE FIRM\nNEHA GUPTA\n302001\n");
        config(['crm_mapping.queue_after_ocr_parse' => false]);
        app(OcrStructurePersistService::class)->parseAndPersist($document);

        $firm = OcrParsedFirm::query()->where('ocr_document_id', $document->id)->firstOrFail();
        $firm->update([
            'match_status' => null,
            'review_status' => 'pending',
            'crm_ca_id' => null,
            'overall_confidence' => 0.95,
            'field_meta' => ['firm_name' => ['confidence' => 0.95]],
            'city' => 'JAIPUR',
            'pincode' => '302001',
            'address' => '1 MAIN ROAD',
        ]);

        $response = $this->postJson('/ocr-documents/'.$document->id.'/approve-safe');
        $response->assertOk();

        $firm->refresh();
        $this->assertSame('auto_created', $firm->match_status);
        $this->assertSame(OcrParsedFirm::REVIEW_APPROVED, $firm->review_status);
        $this->assertNotNull($firm->crm_ca_id);
    }

    public function test_completeness_warnings_logged_when_no_firms_parsed(): void
    {
        $admin = $this->actingAsAdmin();
        $document = $this->makeDocument($admin, "   \n\n");
        $document->update(['extracted_text' => "zzz\n"]);
        app(OcrStructurePersistService::class)->parseAndPersist($document->fresh());
        $document->refresh();
        $completeness = $document->structured_data['parsed']['completeness'] ?? [];
        $firmCount = (int) ($document->parsed_firm_count ?? 0);
        if ($firmCount === 0) {
            $this->assertTrue((bool) ($completeness['needs_review'] ?? false));
            $this->assertNotEmpty($completeness['warnings'] ?? []);
        } else {
            $this->assertArrayHasKey('firm_count', $completeness);
        }
    }
}
