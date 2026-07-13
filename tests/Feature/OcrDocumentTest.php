<?php

namespace Tests\Feature;

use App\Jobs\ProcessOcrDocumentJob;
use App\Models\CaMaster;
use App\Models\LeadAssignmentEngine;
use App\Models\OcrDocument;
use App\Models\User;
use App\Services\DocumentAi\GoogleDocumentAiService;
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
        ]);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
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
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonMissing(['private_key', 'client_email', 'storage_path']);

        $documentId = $response->json('data.id');
        $this->assertDatabaseHas('ocr_documents', [
            'id' => $documentId,
            'ca_id' => $lead->ca_id,
            'status' => 'pending',
        ]);

        Queue::assertPushed(ProcessOcrDocumentJob::class, function (ProcessOcrDocumentJob $job) use ($documentId) {
            return $job->ocrDocumentId === (int) $documentId;
        });
    }

    public function test_unauthorised_employee_cannot_upload_for_unassigned_lead(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
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
            $this->post('/ocr-documents', [
                'ca_id' => $lead->ca_id,
                'document' => UploadedFile::fake()->createWithContent($name, 'fake image bytes', $mime),
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

        $this->mock(GoogleDocumentAiService::class, function ($mock) {
            $mock->shouldReceive('processBinary')->once()->andReturn([
                'text' => 'Extracted OCR text',
                'page_count' => 1,
                'detected_languages' => ['en'],
                'pages' => [['page_number' => 1, 'languages' => ['en'], 'paragraph_count' => 1]],
                'entities' => [],
                'average_confidence' => 0.98,
                'processor_name' => 'projects/test/locations/us/processors/test',
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
            'status' => 'pending',
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
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
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
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $employeeId = \App\Models\Employee::query()->where('email_id', 'employee@ca.local')->value('employee_id');
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
}
