<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Models\User;
use App\Services\Ocr\OcrStructurePersistService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OcrStructureParseTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
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

    public function test_completed_document_show_returns_structured_firms(): void
    {
        $admin = $this->actingAsAdmin();

        $document = OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => $admin->id,
            'original_filename' => 'directory.pdf',
            'stored_filename' => 'directory.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/directory.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1200,
            'status' => OcrDocument::STATUS_COMPLETED,
            'processing_mode' => 'online',
            'extracted_text' => "ABHANPUR\nAGRAWAL GIREPUNJE & ASSOCIATES\nSIO SUNIL KUMAR GIREPUNJE\nSHOP NO 1ST FLOOR\n477908\nABU ROAD\nAGRAWAL PIYUSH & CO\nPIYUSH AGRAWAL\nINDUSTRIAL AREA",
            'processed_at' => now(),
        ]);

        app(OcrStructurePersistService::class)->parseAndPersist($document);

        $this->getJson('/ocr-documents/'.$document->id.'?include_text=1')
            ->assertOk()
            ->assertJsonPath('data.has_structured_data', true)
            ->assertJsonPath('data.parsed_firm_count', 2)
            ->assertJsonPath('data.parsed_firms.0.firm_name', 'AGRAWAL GIREPUNJE & ASSOCIATES')
            ->assertJsonPath('data.parsed_firms.0.city', 'ABHANPUR')
            ->assertJsonPath('data.parsed_firms.0.ca_name', 'SUNIL KUMAR GIREPUNJE')
            ->assertJsonPath('data.parsed_firms.1.firm_name', 'AGRAWAL PIYUSH & CO');
    }

    public function test_firm_review_approve_and_reject(): void
    {
        $admin = $this->actingAsAdmin();

        $document = OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => $admin->id,
            'original_filename' => 'one.pdf',
            'stored_filename' => 'one.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/one.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 800,
            'status' => OcrDocument::STATUS_COMPLETED,
            'extracted_text' => "DELHI\nEXAMPLE & ASSOCIATES\nCA EXAMPLE NAME\n",
            'processed_at' => now(),
        ]);

        app(OcrStructurePersistService::class)->parseAndPersist($document);
        $firm = OcrParsedFirm::query()->where('ocr_document_id', $document->id)->firstOrFail();

        $approve = $this->patchJson('/ocr-documents/'.$document->id.'/firms/'.$firm->id.'/review', [
            'review_status' => 'approved',
        ])->assertOk()
            ->assertJsonPath('data.review_status', 'approved');

        $this->assertGreaterThan(0, (int) $approve->json('data.ca_id'));

        $this->patchJson('/ocr-documents/'.$document->id.'/firms/'.$firm->id.'/review', [
            'review_status' => 'rejected',
        ])->assertOk()->assertJsonPath('data.review_status', 'rejected');
    }

    public function test_reparse_rebuilds_structured_firms(): void
    {
        $admin = $this->actingAsAdmin();

        $document = OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => $admin->id,
            'original_filename' => 'reparse.pdf',
            'stored_filename' => 'reparse.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/reparse.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 900,
            'status' => OcrDocument::STATUS_COMPLETED,
            'extracted_text' => "PUNE\nMEHTA & CO\nRAJ MEHTA\n",
            'processed_at' => now(),
            'parse_status' => 'completed',
            'parsed_firm_count' => 0,
        ]);

        $this->postJson('/ocr-documents/'.$document->id.'/reparse')
            ->assertOk()
            ->assertJsonPath('data.parsed_firm_count', 1)
            ->assertJsonPath('data.parsed_firms.0.firm_name', 'Mehta & Co');

        $this->assertSame(
            1,
            OcrParsedFirm::query()->where('ocr_document_id', $document->id)->count()
        );

        // Second re-structure must replace, not duplicate.
        $this->postJson('/ocr-documents/'.$document->id.'/reparse')
            ->assertOk()
            ->assertJsonPath('data.parsed_firm_count', 1);
        $this->assertSame(
            1,
            OcrParsedFirm::query()->where('ocr_document_id', $document->id)->count()
        );
    }

    public function test_restructure_persists_noisy_directory_fixture_without_zero_firms(): void
    {
        $admin = $this->actingAsAdmin();
        $raw = file_get_contents(base_path('tests/Fixtures/Ocr/directory_multicolumn_sample.txt'));
        $this->assertNotFalse($raw);

        $document = OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => $admin->id,
            'original_filename' => 'noisy-directory.pdf',
            'stored_filename' => 'noisy-directory.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/noisy-directory.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 2200,
            'status' => OcrDocument::STATUS_COMPLETED,
            'processing_mode' => 'online',
            'extracted_text' => $raw,
            'processed_at' => now(),
            'parse_status' => 'failed',
            'parsed_firm_count' => null,
        ]);

        $response = $this->postJson('/ocr-documents/'.$document->id.'/reparse')->assertOk();
        $count = (int) $response->json('data.parsed_firm_count');
        $this->assertGreaterThan(0, $count);
        $this->assertSame($count, OcrParsedFirm::query()->where('ocr_document_id', $document->id)->count());
        $response->assertJsonPath('data.parse_status', 'completed');
        $this->assertNull($response->json('data.parse_error'));
    }

    public function test_deleting_document_removes_parsed_firms(): void
    {
        $admin = $this->actingAsAdmin();

        $document = OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => $admin->id,
            'original_filename' => 'delete-struct.pdf',
            'stored_filename' => 'delete-struct.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/delete-struct.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 500,
            'status' => OcrDocument::STATUS_COMPLETED,
            'extracted_text' => "JAIPUR\nJAIN & ASSOCIATES\nVIKAS JAIN\n",
            'processed_at' => now(),
        ]);
        Storage::disk('local')->put($document->storage_path, 'binary');

        app(OcrStructurePersistService::class)->parseAndPersist($document);
        $this->assertDatabaseHas('ocr_parsed_firms', ['ocr_document_id' => $document->id]);

        $this->deleteJson('/ocr-documents/'.$document->id)->assertOk();

        $this->assertDatabaseMissing('ocr_parsed_firms', ['ocr_document_id' => $document->id]);
    }
}
