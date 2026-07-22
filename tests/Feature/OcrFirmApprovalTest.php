<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Models\CaFirm;
use App\Models\CaMaster;
use App\Models\CaPartner;
use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Models\OcrParsedMember;
use App\Models\User;
use App\Services\Ocr\OcrStructurePersistService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OcrFirmApprovalTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Manual-approve tests seed staging without auto-mapping.
        config([
            'crm_mapping.queue_after_ocr_parse' => false,
            'ocr_safety.require_verification' => true,
            'ocr_safety.auto_create' => false,
            'ocr_safety.auto_update' => false,
            'ocr_safety.allow_bulk_approve_safe' => false,
            'ocr_safety.reject_on_field_collision' => true,
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

    /**
     * @return array{0: OcrDocument, 1: OcrParsedFirm}
     */
    private function seedParsedFirm(User $admin, string $text = "DELHI\nEXAMPLE & ASSOCIATES\nCA EXAMPLE NAME\n9876543210\nexample@example.local\n"): array
    {
        $document = OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => $admin->id,
            'original_filename' => 'approve-test.pdf',
            'stored_filename' => 'approve-test.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/approve-test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 900,
            'status' => OcrDocument::STATUS_COMPLETED,
            'extracted_text' => $text,
            'processed_at' => now(),
        ]);

        app(OcrStructurePersistService::class)->parseAndPersist($document);
        $firm = OcrParsedFirm::query()->where('ocr_document_id', $document->id)->firstOrFail();

        return [$document, $firm];
    }

    public function test_approve_creates_one_ca_masters_row(): void
    {
        $admin = $this->actingAsAdmin();
        [$document, $firm] = $this->seedParsedFirm($admin);

        $before = CaMaster::query()->count();

        $response = $this->patchJson('/ocr-documents/'.$document->id.'/firms/'.$firm->id.'/review', [
            'review_status' => 'approved',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.review_status', 'approved')
            ->assertJsonPath('success', true);

        $caId = (int) $response->json('data.ca_id');
        $this->assertGreaterThan(0, $caId);
        $this->assertSame($before + 1, CaMaster::query()->count());

        $lead = CaMaster::query()->findOrFail($caId);
        $this->assertNotEmpty($lead->firm_name);
        $this->assertDatabaseHas('ocr_parsed_firms', [
            'id' => $firm->id,
            'review_status' => 'approved',
            'crm_ca_id' => $caId,
        ]);
    }

    public function test_approve_saves_detected_partners_to_ca_reference_when_available(): void
    {
        $admin = $this->actingAsAdmin();
        [$document, $firm] = $this->seedParsedFirm($admin);

        OcrParsedMember::query()->updateOrCreate(
            ['ocr_parsed_firm_id' => $firm->id, 'sequence_no' => 1],
            [
                'ca_name' => 'Example CA',
                'membership_no' => 'A12345',
                'mobile' => '9876543210',
                'email' => 'example@example.local',
                'role' => 'Partner',
                'is_primary' => true,
                'review_status' => 'pending',
            ],
        );

        $this->patchJson('/ocr-documents/'.$document->id.'/firms/'.$firm->id.'/review', [
            'review_status' => 'approved',
        ])->assertOk();

        $firm->refresh();
        $this->assertNotNull($firm->crm_ca_id);

        try {
            if (! Schema::connection('ca_reference')->hasTable('ca_partners')) {
                $this->markTestSkipped('ca_reference.ca_partners is not available in this environment.');
            }
        } catch (\Throwable) {
            $this->markTestSkipped('ca_reference connection is not available in this environment.');
        }

        $this->assertNotNull($firm->matched_reference_firm_id);
        $this->assertTrue(
            CaPartner::query()->where('firm_id', $firm->matched_reference_firm_id)->where('partner_name', 'Example CA')->exists()
        );
        $this->assertTrue(CaFirm::query()->whereKey($firm->matched_reference_firm_id)->exists());
    }

    public function test_duplicate_approval_is_idempotent(): void
    {
        $admin = $this->actingAsAdmin();
        [$document, $firm] = $this->seedParsedFirm($admin);

        $first = $this->patchJson('/ocr-documents/'.$document->id.'/firms/'.$firm->id.'/review', [
            'review_status' => 'approved',
        ])->assertOk();

        $caId = (int) $first->json('data.ca_id');
        $countAfterFirst = CaMaster::query()->count();

        $second = $this->patchJson('/ocr-documents/'.$document->id.'/firms/'.$firm->id.'/review', [
            'review_status' => 'approved',
        ])->assertOk();

        $this->assertSame($caId, (int) $second->json('data.ca_id'));
        $this->assertSame($countAfterFirst, CaMaster::query()->count());
        $this->assertSame('already_approved', $second->json('data.approval_action'));
    }

    public function test_reject_does_not_create_master_record(): void
    {
        $admin = $this->actingAsAdmin();
        [$document, $firm] = $this->seedParsedFirm($admin);
        $before = CaMaster::query()->count();

        $this->patchJson('/ocr-documents/'.$document->id.'/firms/'.$firm->id.'/review', [
            'review_status' => 'rejected',
        ])->assertOk()
            ->assertJsonPath('data.review_status', 'rejected')
            ->assertJsonPath('data.ca_id', null);

        $this->assertSame($before, CaMaster::query()->count());
        $this->assertDatabaseHas('ocr_parsed_firms', [
            'id' => $firm->id,
            'review_status' => 'rejected',
            'crm_ca_id' => null,
        ]);
    }

    public function test_approved_firm_appears_in_all_firms_api(): void
    {
        $admin = $this->actingAsAdmin();
        [$document, $firm] = $this->seedParsedFirm($admin);

        $approve = $this->patchJson('/ocr-documents/'.$document->id.'/firms/'.$firm->id.'/review', [
            'review_status' => 'approved',
        ])->assertOk();

        $caId = (int) $approve->json('data.ca_id');
        $this->assertGreaterThan(0, $caId);

        $this->getJson('/ca-masters/'.$caId)
            ->assertOk()
            ->assertJsonPath('data.ca_id', $caId);

        $list = $this->getJson('/ca-masters?q='.urlencode((string) $firm->fresh()->firm_name).'&per_page=100');
        $list->assertOk();

        $items = $list->json('data.items') ?? [];
        $ids = collect($items)->pluck('ca_id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($caId, $ids, 'Approved OCR firm should appear in All Firms / ca-masters API.');
    }

    public function test_master_ca_approve_inserts_into_ca_masters_and_completes_document(): void
    {
        if (! Schema::hasColumn('ocr_documents', 'import_type')) {
            $this->markTestSkipped('import_type column missing — run migration');
        }

        $admin = $this->actingAsAdmin();
        $document = OcrDocument::query()->create([
            'uploaded_by' => $admin->id,
            'original_filename' => 'master-approve.pdf',
            'stored_filename' => 'master-approve.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/master-approve-'.uniqid('', true).'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 900,
            'checksum' => hash('sha256', uniqid('', true)),
            'status' => OcrDocument::STATUS_COMPLETED,
            'import_type' => OcrDocument::IMPORT_MASTER_CA,
            'parse_status' => 'completed',
            'parsed_firm_count' => 1,
            'processing_progress' => 'Importing official Master CA records',
            'processed_at' => now(),
        ]);

        $firm = OcrParsedFirm::query()->create([
            'ocr_document_id' => $document->id,
            'sequence_no' => 1,
            'firm_name' => 'Master Approve Associates',
            'raw_firm_name' => 'Master Approve Associates',
            'frn' => 'FRNMA'.random_int(100000, 999999),
            'state' => 'Delhi',
            'review_status' => OcrParsedFirm::REVIEW_PENDING,
            'match_status' => 'needs_review',
            'match_reason' => 'insufficient_official_identity',
        ]);
        OcrParsedMember::query()->create([
            'ocr_parsed_firm_id' => $firm->id,
            'sequence_no' => 1,
            'ca_name' => 'Master Approve CA',
            'membership_no' => 'MEMMA'.random_int(100000, 999999),
            'is_primary' => true,
            'review_status' => 'pending',
        ]);

        $before = CaMaster::query()->count();

        $response = $this->patchJson('/ocr-documents/'.$document->id.'/firms/'.$firm->id.'/review', [
            'review_status' => 'approved',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.review_status', 'approved');

        $caId = (int) $response->json('data.ca_id');
        $this->assertGreaterThan(0, $caId);
        $this->assertSame($before + 1, CaMaster::query()->count());
        $this->assertDatabaseHas('ocr_parsed_firms', [
            'id' => $firm->id,
            'review_status' => 'approved',
            'crm_ca_id' => $caId,
        ]);
        $this->assertContains(
            $firm->fresh()->match_status,
            ['imported', 'updated_official', 'duplicate', 'auto_created'],
        );

        $document->refresh();
        $this->assertSame('Completed', $document->processing_progress);
        $this->assertSame('completed', $document->pipelineStage());

        $this->getJson('/ca-masters/'.$caId)->assertOk()->assertJsonPath('data.ca_id', $caId);
    }

    public function test_master_ca_approve_all_safe_does_not_throw(): void
    {
        if (! Schema::hasColumn('ocr_documents', 'import_type')) {
            $this->markTestSkipped('import_type column missing — run migration');
        }

        $admin = $this->actingAsAdmin();
        $document = OcrDocument::query()->create([
            'uploaded_by' => $admin->id,
            'original_filename' => 'master-safe.pdf',
            'stored_filename' => 'master-safe.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/master-safe-'.uniqid('', true).'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 900,
            'checksum' => hash('sha256', uniqid('', true)),
            'status' => OcrDocument::STATUS_COMPLETED,
            'import_type' => OcrDocument::IMPORT_MASTER_CA,
            'parse_status' => 'completed',
            'parsed_firm_count' => 1,
            'processing_progress' => 'Importing official Master CA records',
            'processed_at' => now(),
        ]);

        $firm = OcrParsedFirm::query()->create([
            'ocr_document_id' => $document->id,
            'sequence_no' => 1,
            'firm_name' => 'Safe Master Firm',
            'frn' => 'FRNSAFE'.random_int(100000, 999999),
            'state' => 'Maharashtra',
            'review_status' => OcrParsedFirm::REVIEW_PENDING,
            'match_status' => null,
        ]);
        OcrParsedMember::query()->create([
            'ocr_parsed_firm_id' => $firm->id,
            'sequence_no' => 1,
            'ca_name' => 'Safe Master CA',
            'membership_no' => 'MEMSAFE'.random_int(100000, 999999),
            'is_primary' => true,
            'review_status' => 'pending',
        ]);

        $this->postJson('/ocr-documents/'.$document->id.'/approve-safe')
            ->assertStatus(422)
            ->assertJsonFragment(['success' => false]);

        // Fail-closed: bulk stays off; individual Approve verified still writes Master.
        $this->patchJson('/ocr-documents/'.$document->id.'/firms/'.$firm->id.'/review', [
            'review_status' => 'approved',
        ])->assertOk()->assertJsonPath('success', true);

        $firm->refresh();
        $this->assertNotNull($firm->crm_ca_id);
        $this->assertSame('approved', $firm->review_status);
        $this->assertSame('Completed', $document->fresh()->processing_progress);

        // With the flag enabled, Accept All Eligible writes verified Master CA rows.
        config(['ocr_safety.allow_bulk_approve_safe' => true]);
        $firm2 = OcrParsedFirm::query()->create([
            'ocr_document_id' => $document->id,
            'sequence_no' => 2,
            'firm_name' => 'Bulk Eligible Master Firm',
            'city' => 'Mumbai',
            'frn' => 'FRNBULK'.random_int(100000, 999999),
            'state' => 'Maharashtra',
            'review_status' => OcrParsedFirm::REVIEW_PENDING,
            'match_status' => 'verified',
        ]);
        OcrParsedMember::query()->create([
            'ocr_parsed_firm_id' => $firm2->id,
            'sequence_no' => 1,
            'ca_name' => 'Bulk Eligible CA',
            'membership_no' => 'MEMBULK'.random_int(100000, 999999),
            'is_primary' => true,
            'review_status' => 'pending',
        ]);

        $this->postJson('/ocr-documents/'.$document->id.'/approve-safe')
            ->assertOk()
            ->assertJsonPath('success', true);

        $firm2->refresh();
        $this->assertNotNull($firm2->crm_ca_id);
        $this->assertSame(OcrParsedFirm::REVIEW_APPROVED, $firm2->review_status);
    }
}
