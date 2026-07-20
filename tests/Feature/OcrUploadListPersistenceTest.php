<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Jobs\ProcessOcrDocumentJob;
use App\Models\OcrDocument;
use App\Models\User;
use App\Services\Ocr\PdfPageCounter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OcrUploadListPersistenceTest extends TestCase
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
            'document-ai.sync_small_files' => false,
            'document-ai.queue' => 'default',
            'queue.default' => 'database',
        ]);
        app(\App\Services\Rbac\RbacDatabaseService::class)->ensureConfigDefaultGrants();
        app(\App\Services\Rbac\RbacMatrixService::class)->flushCache();
    }

    private function writeFakeCredentials(): string
    {
        $path = storage_path('framework/testing/document-ai-service-account.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, json_encode([
            'type' => 'service_account',
            'project_id' => 'test-project',
            'client_email' => 'ocr@test-project.iam.gserviceaccount.com',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nTEST\n-----END PRIVATE KEY-----\n",
        ], JSON_THROW_ON_ERROR));

        return $path;
    }

    private function actingAsAdmin(): User
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        return $admin;
    }

    public function test_upload_creates_one_row_returned_in_list_and_survives_requery(): void
    {
        $this->mock(PdfPageCounter::class, function ($mock) {
            $mock->shouldReceive('count')->andReturn(4);
        });
        Queue::fake();
        $this->actingAsAdmin();

        $upload = $this->post('/ocr-documents', [
            'import_type' => 'master_ca',
            'document' => UploadedFile::fake()->createWithContent(
                'northprop_first_4_pages.pdf',
                "%PDF-1.4\n".str_repeat("/Type /Page\n", 4)."\n%EOF",
                'application/pdf',
            ),
        ], ['Accept' => 'application/json'])->assertCreated();

        $id = (int) $upload->json('data.id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame('queued', $upload->json('data.status'));
        $this->assertSame('master_ca', $upload->json('data.import_type'));
        $this->assertDatabaseHas('ocr_documents', [
            'id' => $id,
            'status' => 'queued',
            'import_type' => 'master_ca',
        ]);

        Queue::assertPushed(ProcessOcrDocumentJob::class, 1);

        $list = $this->getJson('/ocr-documents')->assertOk();
        $ids = collect($list->json('data.items') ?? [])->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertContains($id, $ids);
        $this->assertGreaterThanOrEqual(1, (int) $list->json('data.pagination.total'));

        // Re-query simulates browser refresh.
        $again = $this->getJson('/ocr-documents')->assertOk();
        $idsAgain = collect($again->json('data.items') ?? [])->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertContains($id, $idsAgain);
        $this->assertSame(1, OcrDocument::query()->where('id', $id)->count());
    }

    public function test_all_statuses_includes_queued_master_ca_rows(): void
    {
        $admin = $this->actingAsAdmin();
        $doc = OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => $admin->id,
            'original_filename' => 'queued.pdf',
            'stored_filename' => 'queued.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/queued.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 62084,
            'status' => OcrDocument::STATUS_QUEUED,
            'import_type' => OcrDocument::IMPORT_MASTER_CA,
            'processing_mode' => 'online',
            'processing_progress' => 'Queued for online OCR',
            'page_count' => 4,
        ]);

        $list = $this->getJson('/ocr-documents')->assertOk();
        $ids = collect($list->json('data.items') ?? [])->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertContains((int) $doc->id, $ids);
    }

    public function test_double_submit_same_checksum_without_force_creates_one_active_document(): void
    {
        $this->mock(PdfPageCounter::class, function ($mock) {
            $mock->shouldReceive('count')->andReturn(4);
        });
        Queue::fake();
        $this->actingAsAdmin();

        $bytes = "%PDF-1.4\nunique-double-submit-".uniqid('', true)."\n/Type /Page\n%EOF";
        $file = UploadedFile::fake()->createWithContent('dup.pdf', $bytes, 'application/pdf');

        $first = $this->post('/ocr-documents', [
            'import_type' => 'master_ca',
            'document' => $file,
        ], ['Accept' => 'application/json'])->assertCreated();

        $second = $this->post('/ocr-documents', [
            'import_type' => 'master_ca',
            'document' => UploadedFile::fake()->createWithContent('dup.pdf', $bytes, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertTrue((bool) $second->json('errors.duplicate_file'));
        $this->assertSame(1, OcrDocument::query()->where('checksum', hash('sha256', $bytes))->count());
        Queue::assertPushed(ProcessOcrDocumentJob::class, 1);
        $this->assertNotNull($first->json('data.id'));
    }
}
