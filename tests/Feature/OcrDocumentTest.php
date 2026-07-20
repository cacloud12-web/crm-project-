<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Jobs\ProcessOcrDocumentJob;
use App\Models\CaMaster;
use App\Models\LeadAssignmentEngine;
use App\Models\OcrDocument;
use App\Models\User;
use App\Contracts\Ocr\OcrProcessorInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OcrDocumentTest extends TestCase
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

    private function createLead(): CaMaster
    {
        return CaMaster::query()->firstOrFail();
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

    private function fakePdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'statement.pdf',
            '%PDF-1.4 fake bank statement content',
            'application/pdf',
        );
    }

    private function unassignedLeadForEmployee(User $employee): CaMaster
    {
        $employeeId = \App\Models\Employee::query()
            ->where('email_id', $employee->email)
            ->value('employee_id');

        return CaMaster::query()
            ->whereDoesntHave('leadAssignments', function ($query) use ($employeeId) {
                $query->where('employee_id', $employeeId)
                    ->where('status', 'Active');
            })
            ->firstOrFail();
    }

    public function test_unauthenticated_upload_is_rejected(): void
    {
        $lead = $this->createLead();

        $this->postJson('/ocr-documents', [
            'ca_id' => $lead->ca_id,
            'document' => $this->fakePdf(),
        ])->assertUnauthorized();
    }

    public function test_authorised_upload_creates_record_and_dispatches_job(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $lead = $this->createLead();

        $response = $this->post('/ocr-documents', [
            'ca_id' => $lead->ca_id,
            'document' => $this->fakePdf(),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonMissing(['private_key', 'client_email', 'storage_path']);

        $documentId = $response->json('data.id');
        $this->assertDatabaseHas('ocr_documents', [
            'id' => $documentId,
            'ca_id' => $lead->ca_id,
            'status' => 'queued',
        ]);

        Queue::assertPushed(ProcessOcrDocumentJob::class, function (ProcessOcrDocumentJob $job) use ($documentId) {
            return $job->ocrDocumentId === (int) $documentId;
        });
    }

    public function test_unauthorised_employee_cannot_upload_for_unassigned_lead(): void
    {
        $employee = CrmTestAccounts::employeeUser();
        $this->actingAs($employee);
        $lead = $this->unassignedLeadForEmployee($employee);

        $this->post('/ocr-documents', [
            'ca_id' => $lead->ca_id,
            'document' => $this->fakePdf(),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ca_id']);
    }

    public function test_invalid_mime_type_is_rejected(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();

        $this->post('/ocr-documents', [
            'ca_id' => $lead->ca_id,
            'document' => UploadedFile::fake()->create('script.svg', 10, 'image/svg+xml'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['document']);
    }

    public function test_oversized_file_is_rejected(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();

        $this->post('/ocr-documents', [
            'ca_id' => $lead->ca_id,
            'document' => UploadedFile::fake()->create('large.pdf', 11000, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['document']);
    }

    public function test_supported_image_types_are_accepted(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $lead = $this->createLead();

        foreach ([
            ['scan.jpg', 'image/jpeg'],
            ['scan.png', 'image/png'],
            ['scan.tiff', 'image/tiff'],
        ] as [$name, $mime]) {
            // Unique bytes per file so checksum duplicate protection does not block siblings.
            $this->post('/ocr-documents', [
                'ca_id' => $lead->ca_id,
                'document' => UploadedFile::fake()->createWithContent($name, 'fake image bytes for '.$name, $mime),
            ], ['Accept' => 'application/json'])->assertCreated();
        }
    }

    public function test_successful_processing_stores_extracted_text(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $lead = $this->createLead();

        $upload = $this->post('/ocr-documents', [
            'ca_id' => $lead->ca_id,
            'document' => $this->fakePdf(),
        ], ['Accept' => 'application/json'])->assertCreated();

        $document = OcrDocument::query()->findOrFail($upload->json('data.id'));
        Storage::disk('local')->put($document->storage_path, 'binary-content');

        $this->mock(OcrProcessorInterface::class, function ($mock) {
            $mock->shouldReceive('processBinary')->once()->andReturn([
                'text' => 'Extracted OCR text',
                'page_count' => 1,
                'languages' => ['en'],
                'detected_languages' => ['en'],
                'pages' => [['page_number' => 1, 'languages' => ['en'], 'paragraph_count' => 1]],
                'entities' => [],
                'average_confidence' => 0.98,
                'processor_name' => 'projects/test/locations/us/processors/test',
                'provider_reference' => 'projects/test/locations/us/processors/test',
                'metadata' => ['provider' => 'google_document_ai'],
            ]);
        });

        $job = new ProcessOcrDocumentJob($document->id);
        $job->handle(app(\App\Services\Ocr\OcrDocumentService::class));

        $document->refresh();
        $this->assertSame('completed', $document->status);
        $this->assertSame('Extracted OCR text', $document->extracted_text);
        $this->assertNull($document->corrected_text);
    }

    public function test_corrected_text_is_stored_separately(): void
    {
        $admin = $this->actingAsAdmin();
        $lead = $this->createLead();

        $document = OcrDocument::query()->create([
            'ca_id' => $lead->ca_id,
            'uploaded_by' => $admin->id,
            'original_filename' => 'doc.pdf',
            'stored_filename' => 'doc.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/2026/07/doc.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'status' => OcrDocument::STATUS_COMPLETED,
            'extracted_text' => 'Original extracted text',
        ]);

        Storage::disk('local')->put($document->storage_path, 'binary');

        $this->patchJson('/ocr-documents/'.$document->id.'/text', [
            'corrected_text' => 'Corrected by user',
        ])->assertOk();

        $document->refresh();
        $this->assertSame('Original extracted text', $document->extracted_text);
        $this->assertSame('Corrected by user', $document->corrected_text);
    }

    public function test_retry_is_allowed_for_failed_record(): void
    {
        Queue::fake();
        $admin = $this->actingAsAdmin();
        $lead = $this->createLead();

        $document = OcrDocument::query()->create([
            'ca_id' => $lead->ca_id,
            'uploaded_by' => $admin->id,
            'original_filename' => 'doc.pdf',
            'stored_filename' => 'doc.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/2026/07/doc.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'status' => OcrDocument::STATUS_FAILED,
            'error_code' => 'timeout',
            'error_message' => 'Temporary failure',
        ]);

        $this->postJson('/ocr-documents/'.$document->id.'/retry')->assertOk();

        Queue::assertPushed(ProcessOcrDocumentJob::class);
        $this->assertDatabaseHas('ocr_documents', [
            'id' => $document->id,
            'status' => 'queued',
        ]);
    }

    public function test_secure_original_download_requires_authorisation(): void
    {
        $admin = $this->actingAsAdmin();
        $lead = $this->createLead();

        $document = OcrDocument::query()->create([
            'ca_id' => $lead->ca_id,
            'uploaded_by' => $admin->id,
            'original_filename' => 'doc.pdf',
            'stored_filename' => 'doc.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/2026/07/doc.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'status' => OcrDocument::STATUS_COMPLETED,
        ]);

        Storage::disk('local')->put($document->storage_path, '%PDF-1.4 sample');

        $this->get('/ocr-documents/'.$document->id.'/original')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_employee_cannot_access_another_employees_document(): void
    {
        $admin = $this->actingAsAdmin();
        $employee = CrmTestAccounts::employeeUser();
        $lead = $this->unassignedLeadForEmployee($employee);

        $document = OcrDocument::query()->create([
            'ca_id' => $lead->ca_id,
            'uploaded_by' => $admin->id,
            'original_filename' => 'doc.pdf',
            'stored_filename' => 'doc.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/2026/07/doc.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'status' => OcrDocument::STATUS_COMPLETED,
        ]);

        $this->actingAs($employee);
        $this->getJson('/ocr-documents/'.$document->id)->assertForbidden();
    }

    public function test_assigned_employee_can_view_document(): void
    {
        $employee = CrmTestAccounts::employeeUser();
        $employeeId = CrmTestAccounts::employee()->employee_id;
        $lead = $this->createLead();

        LeadAssignmentEngine::query()->updateOrCreate(
            ['ca_id' => $lead->ca_id, 'employee_id' => $employeeId],
            ['status' => 'Active', 'assigned_at' => now()],
        );

        $document = OcrDocument::query()->create([
            'ca_id' => $lead->ca_id,
            'uploaded_by' => $employee->id,
            'original_filename' => 'doc.pdf',
            'stored_filename' => 'doc.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/2026/07/doc.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'status' => OcrDocument::STATUS_COMPLETED,
            'extracted_text' => 'Visible text',
        ]);

        $this->actingAs($employee);
        $this->getJson('/ocr-documents/'.$document->id.'?include_text=1')
            ->assertOk()
            ->assertJsonPath('data.extracted_text', 'Visible text');
    }

    public function test_safe_deletion_removes_record_and_file(): void
    {
        // Local DB matrices may drift; ensure config defaults (incl. ca_master.delete) before asserting admin delete.
        app(\App\Services\Rbac\RbacDatabaseService::class)->ensureConfigDefaultGrants();
        app(\App\Services\Rbac\RbacMatrixService::class)->flushCache();

        $admin = $this->actingAsAdmin();
        $lead = $this->createLead();

        $document = OcrDocument::query()->create([
            'ca_id' => $lead->ca_id,
            'uploaded_by' => $admin->id,
            'original_filename' => 'doc.pdf',
            'stored_filename' => 'doc.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/2026/07/doc.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'status' => OcrDocument::STATUS_COMPLETED,
        ]);

        Storage::disk('local')->put($document->storage_path, 'binary');

        $this->deleteJson('/ocr-documents/'.$document->id)->assertOk();
        $this->assertSoftDeleted('ocr_documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing($document->storage_path);
    }

    public function test_configuration_errors_do_not_expose_secrets_in_response(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $lead = $this->createLead();

        $upload = $this->post('/ocr-documents', [
            'ca_id' => $lead->ca_id,
            'document' => $this->fakePdf(),
        ], ['Accept' => 'application/json'])->assertCreated();

        $document = OcrDocument::query()->findOrFail($upload->json('data.id'));
        Storage::disk('local')->put($document->storage_path, 'binary');

        config(['document-ai.processor_id' => '']);

        try {
            (new ProcessOcrDocumentJob($document->id))->handle(app(\App\Services\Ocr\OcrDocumentService::class));
        } catch (\Throwable) {
            // expected configuration failure
        }

        $document->refresh();
        $this->assertSame('failed', $document->status);
        $this->assertStringNotContainsString('PRIVATE KEY', (string) $document->error_message);
        $upload->assertJsonMissing(['private_key', 'client_email']);
    }

    public function test_library_upload_without_ca_id_is_supported(): void
    {
        Queue::fake();
        $this->actingAsAdmin();

        $response = $this->post('/ocr-documents', [
            'document' => $this->fakePdf(),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.provider', 'google_document_ai')
            ->assertJsonPath('data.ca_id', null);

        $this->assertDatabaseHas('ocr_documents', [
            'id' => $response->json('data.id'),
            'ca_id' => null,
            'status' => 'queued',
        ]);

        Queue::assertPushed(ProcessOcrDocumentJob::class);
    }

    public function test_ocr_history_supports_search_status_and_pagination(): void
    {
        $this->actingAsAdmin();

        OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => CrmTestAccounts::admin()->id,
            'original_filename' => 'alpha-invoice.pdf',
            'stored_filename' => 'alpha.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/alpha.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1200,
            'status' => 'completed',
        ]);

        OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => CrmTestAccounts::admin()->id,
            'original_filename' => 'beta-statement.pdf',
            'stored_filename' => 'beta.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/beta.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1400,
            'status' => 'failed',
            'error_message' => 'Processor error',
        ]);

        $this->getJson('/ocr-documents?search=alpha&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.original_filename', 'alpha-invoice.pdf');

        $this->getJson('/ocr-documents?status=failed&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.items.0.status', 'failed');
    }

    public function test_employee_cannot_view_other_users_library_document(): void
    {
        $admin = $this->actingAsAdmin();
        $document = OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => $admin->id,
            'original_filename' => 'secret.pdf',
            'stored_filename' => 'secret.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/secret.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 900,
            'status' => 'completed',
            'extracted_text' => 'secret text',
        ]);

        $employee = CrmTestAccounts::employeeUser();
        $this->actingAs($employee);

        $this->getJson('/ocr-documents/'.$document->id)->assertForbidden();
    }

    public function test_show_completed_document_includes_extracted_text_and_permissions(): void
    {
        $admin = $this->actingAsAdmin();
        $document = OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => $admin->id,
            'original_filename' => 'sample.pdf',
            'stored_filename' => 'sample.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/sample.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1200,
            'status' => OcrDocument::STATUS_COMPLETED,
            'processing_mode' => 'online',
            'page_count' => 1,
            'extracted_text' => 'Extracted OCR sample text',
            'processed_at' => now(),
        ]);

        // Fast preview path — no full OCR text payload.
        $this->getJson('/ocr-documents/'.$document->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $document->id)
            ->assertJsonPath('data.extracted_text', null)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.can.view', true)
            ->assertJsonPath('data.can.delete', true)
            ->assertJsonMissingPath('data.storage_path')
            ->assertJsonMissingPath('data.gcs_input_uri');

        $this->getJson('/ocr-documents/'.$document->id.'?include_text=1')
            ->assertOk()
            ->assertJsonPath('data.extracted_text', 'Extracted OCR sample text');
    }

    public function test_failed_document_can_be_opened(): void
    {
        $admin = $this->actingAsAdmin();
        $document = OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => $admin->id,
            'original_filename' => 'centralpart.pdf',
            'stored_filename' => 'centralpart.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/centralpart.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 3000000,
            'status' => OcrDocument::STATUS_FAILED,
            'processing_mode' => 'batch',
            'error_message' => 'Large-document processing is not configured. Please contact the administrator.',
        ]);

        $this->getJson('/ocr-documents/'.$document->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.can.retry', true)
            ->assertJsonPath('data.can.delete', true)
            ->assertJsonFragment(['error_message' => 'Large-document processing is not configured. Please contact the administrator.']);
    }

    public function test_missing_document_returns_404(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/ocr-documents/999999')->assertNotFound();
    }

    public function test_preview_returns_inline_mime_type(): void
    {
        $admin = $this->actingAsAdmin();
        $document = OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => $admin->id,
            'original_filename' => 'preview.pdf',
            'stored_filename' => 'preview.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/preview.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 80,
            'status' => OcrDocument::STATUS_COMPLETED,
        ]);
        Storage::disk('local')->put($document->storage_path, '%PDF-1.4 preview');

        $this->get('/ocr-documents/'.$document->id.'/preview')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_download_preserves_original_filename(): void
    {
        $admin = $this->actingAsAdmin();
        $document = OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => $admin->id,
            'original_filename' => 'Client Statement.pdf',
            'stored_filename' => 'stored.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/download.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 80,
            'status' => OcrDocument::STATUS_COMPLETED,
        ]);
        Storage::disk('local')->put($document->storage_path, '%PDF-1.4 download');

        $response = $this->get('/ocr-documents/'.$document->id.'/download')->assertOk();
        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('Client Statement.pdf', $disposition);
    }

    public function test_failed_record_can_be_deleted(): void
    {
        $admin = $this->actingAsAdmin();
        $document = OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => $admin->id,
            'original_filename' => 'failed.pdf',
            'stored_filename' => 'failed.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/failed.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 90,
            'status' => OcrDocument::STATUS_FAILED,
        ]);
        Storage::disk('local')->put($document->storage_path, 'binary');

        $this->deleteJson('/ocr-documents/'.$document->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'OCR document deleted successfully.');

        $this->assertSoftDeleted('ocr_documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing($document->storage_path);
    }

    public function test_unauthorized_delete_returns_403(): void
    {
        $admin = $this->actingAsAdmin();
        $document = OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => $admin->id,
            'original_filename' => 'secret-delete.pdf',
            'stored_filename' => 'secret-delete.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/secret-delete.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 90,
            'status' => OcrDocument::STATUS_COMPLETED,
        ]);

        $employee = CrmTestAccounts::employeeUser();
        $this->actingAs($employee);

        $this->deleteJson('/ocr-documents/'.$document->id)->assertForbidden();
        $this->assertDatabaseHas('ocr_documents', ['id' => $document->id, 'deleted_at' => null]);
    }

    public function test_active_processing_record_cannot_be_deleted(): void
    {
        $admin = $this->actingAsAdmin();
        $document = OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => $admin->id,
            'original_filename' => 'processing.pdf',
            'stored_filename' => 'processing.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/processing.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'status' => OcrDocument::STATUS_PROCESSING,
        ]);
        Storage::disk('local')->put($document->storage_path, 'binary');

        $this->deleteJson('/ocr-documents/'.$document->id)
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('ocr_documents', ['id' => $document->id, 'deleted_at' => null]);
        Storage::disk('local')->assertExists($document->storage_path);

        $this->getJson('/ocr-documents/'.$document->id)
            ->assertOk()
            ->assertJsonPath('data.can.delete', false);
    }
}
