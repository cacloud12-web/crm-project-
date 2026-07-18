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
        config(['crm_mapping.queue_after_ocr_parse' => false]);
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
}
