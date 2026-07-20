<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Jobs\CheckBatchOcrStatusJob;
use App\Jobs\FinalizeBatchOcrResultJob;
use App\Jobs\ProcessOcrDocumentJob;
use App\Jobs\StartBatchOcrJob;
use App\Models\OcrDocument;
use App\Models\User;
use App\Services\Ocr\GoogleDocumentAiBatchService;
use App\Services\Ocr\OcrDocumentService;
use App\Services\Ocr\OcrProcessingModeSelector;
use App\Services\Ocr\PdfPageCounter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OcrHybridProcessingTest extends TestCase
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
            'document-ai.max_file_mb' => 100,
            'document-ai.online_max_pages' => 30,
            'document-ai.batch_max_pages' => 500,
            'document-ai.online_max_file_mb' => 40,
            'document-ai.batch_max_file_mb' => 1024,
            'document-ai.sync_small_files' => false,
            'document-ai.gcs.input_bucket' => 'ocr-input-test',
            'document-ai.gcs.output_bucket' => 'ocr-output-test',
            'queue.default' => 'sync',
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

    private function fakePdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'statement.pdf',
            '%PDF-1.4 one page statement',
            'application/pdf',
        );
    }

    public function test_small_pdf_selects_online_mode(): void
    {
        $this->mock(PdfPageCounter::class, function ($mock) {
            $mock->shouldReceive('count')->andReturn(1);
        });

        $decision = app(OcrProcessingModeSelector::class)->decide(
            'application/pdf',
            50_000,
            '%PDF-1.4',
        );

        $this->assertSame('online', $decision['mode']);
        $this->assertSame(1, $decision['page_count']);
    }

    public function test_thirty_page_pdf_stays_online(): void
    {
        $this->mock(PdfPageCounter::class, function ($mock) {
            $mock->shouldReceive('count')->andReturn(30);
        });

        $decision = app(OcrProcessingModeSelector::class)->decide(
            'application/pdf',
            500_000,
            '%PDF-1.4',
        );

        $this->assertSame('online', $decision['mode']);
    }

    public function test_large_pdf_selects_batch_mode(): void
    {
        $this->mock(PdfPageCounter::class, function ($mock) {
            $mock->shouldReceive('count')->andReturn(262);
        });

        $decision = app(OcrProcessingModeSelector::class)->decide(
            'application/pdf',
            3_107_780,
            '%PDF-1.4',
        );

        $this->assertSame('batch', $decision['mode']);
        $this->assertSame(262, $decision['page_count']);
    }

    public function test_missing_gcs_config_rejects_large_upload(): void
    {
        config([
            'document-ai.gcs.input_bucket' => '',
            'document-ai.gcs.output_bucket' => '',
        ]);
        $this->mock(PdfPageCounter::class, function ($mock) {
            $mock->shouldReceive('count')->andReturn(262);
        });

        Queue::fake();

        $employee = CrmTestAccounts::employeeUser();
        $this->actingAs($employee);

        $this->post('/ocr-documents', [
            'document' => $this->fakePdf(),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Large-document processing is not configured. Please contact the administrator.',
            ])
            ->assertJsonMissing(['GOOGLE_CLOUD_STORAGE_INPUT_BUCKET']);

        $this->actingAsAdmin();
        $adminResponse = $this->post('/ocr-documents', [
            'document' => $this->fakePdf(),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertStringContainsString(
            'GOOGLE_CLOUD_STORAGE_INPUT_BUCKET',
            (string) $adminResponse->getContent(),
        );
    }

    public function test_bucket_names_accept_gs_prefix_but_normalize(): void
    {
        config([
            'document-ai.gcs.input_bucket' => 'gs://my-ocr-input-bucket',
            'document-ai.gcs.output_bucket' => 'gs://my-ocr-output-bucket/path',
        ]);

        // Re-read through selector normalization (config closures already boot once; set cleaned values).
        config([
            'document-ai.gcs.input_bucket' => app(OcrProcessingModeSelector::class)
                ->normalizedBucket('gs://my-ocr-input-bucket'),
            'document-ai.gcs.output_bucket' => app(OcrProcessingModeSelector::class)
                ->normalizedBucket('gs://my-ocr-output-bucket/path'),
        ]);

        $selector = app(OcrProcessingModeSelector::class);
        $selector->assertBatchConfigured();

        $this->assertSame('my-ocr-input-bucket', config('document-ai.gcs.input_bucket'));
        $this->assertSame('my-ocr-output-bucket', config('document-ai.gcs.output_bucket'));
    }

    public function test_missing_output_bucket_alone_is_rejected(): void
    {
        config([
            'document-ai.gcs.input_bucket' => 'valid-ocr-input-bucket',
            'document-ai.gcs.output_bucket' => '',
        ]);

        try {
            app(OcrProcessingModeSelector::class)->assertBatchConfigured();
            $this->fail('Expected configuration exception');
        } catch (\App\Exceptions\DocumentAi\DocumentAiConfigurationException $exception) {
            $this->assertSame(
                'Large-document processing is not configured. Please contact the administrator.',
                $exception->publicMessage(),
            );
            $this->assertStringContainsString('output bucket', $exception->detailForAdministrators());
        }
    }

    public function test_large_upload_sets_batch_mode_and_dispatches_job(): void
    {
        $this->mock(PdfPageCounter::class, function ($mock) {
            $mock->shouldReceive('count')->andReturn(262);
        });

        Queue::fake();
        $this->actingAsAdmin();

        $response = $this->post('/ocr-documents', [
            'document' => $this->fakePdf(),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame('batch', $response->json('data.processing_mode'));
        $this->assertSame('queued', $response->json('data.status'));
        $this->assertSame(262, $response->json('data.page_count'));
        $this->assertArrayNotHasKey('gcs_input_uri', $response->json('data') ?? []);
        $this->assertArrayNotHasKey('provider_operation_name', $response->json('data') ?? []);

        Queue::assertPushed(ProcessOcrDocumentJob::class);
    }

    public function test_process_job_routes_batch_documents_to_start_batch_job(): void
    {
        Queue::fake();
        $admin = $this->actingAsAdmin();

        $document = OcrDocument::query()->create([
            'uploaded_by' => $admin->id,
            'original_filename' => 'centralpart.pdf',
            'stored_filename' => 'centralpart.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/centralpart.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 3107780,
            'status' => OcrDocument::STATUS_QUEUED,
            'processing_mode' => 'batch',
            'page_count' => 262,
            'total_pages' => 262,
            'provider' => 'google_document_ai',
        ]);
        Storage::disk('local')->put($document->storage_path, '%PDF-1.4');

        $job = new ProcessOcrDocumentJob($document->id);
        $job->handle(app(OcrDocumentService::class));

        Queue::assertPushed(StartBatchOcrJob::class, fn (StartBatchOcrJob $job) => $job->ocrDocumentId === $document->id);
    }

    public function test_start_batch_persists_operation_and_schedules_status_check(): void
    {
        Queue::fake();
        $admin = $this->actingAsAdmin();

        $document = OcrDocument::query()->create([
            'uploaded_by' => $admin->id,
            'original_filename' => 'centralpart.pdf',
            'stored_filename' => 'centralpart.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/centralpart.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 3107780,
            'status' => OcrDocument::STATUS_QUEUED,
            'processing_mode' => 'batch',
            'page_count' => 262,
            'provider' => 'google_document_ai',
        ]);
        Storage::disk('local')->put($document->storage_path, '%PDF-1.4 binary');

        $this->mock(GoogleDocumentAiBatchService::class, function ($mock) {
            $mock->shouldReceive('submit')->once()->andReturn([
                'operation_name' => 'projects/test/locations/us/operations/op-1',
                'gcs_input_uri' => 'gs://ocr-input-test/ocr-input/1.pdf',
                'gcs_output_uri' => 'gs://ocr-output-test/ocr-output/1/',
            ]);
        });

        app(OcrDocumentService::class)->startBatchProcessing($document->id);

        $document->refresh();
        $this->assertSame(OcrDocument::STATUS_PROCESSING, $document->status);
        $this->assertSame('projects/test/locations/us/operations/op-1', $document->provider_operation_name);
        $this->assertSame('gs://ocr-input-test/ocr-input/1.pdf', $document->gcs_input_uri);
        Queue::assertPushed(CheckBatchOcrStatusJob::class);
    }

    public function test_status_check_dispatches_finalizer_when_done(): void
    {
        Queue::fake();
        $admin = $this->actingAsAdmin();

        $document = OcrDocument::query()->create([
            'uploaded_by' => $admin->id,
            'original_filename' => 'centralpart.pdf',
            'stored_filename' => 'centralpart.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/centralpart.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 3107780,
            'status' => OcrDocument::STATUS_PROCESSING,
            'processing_mode' => 'batch',
            'provider_operation_name' => 'projects/test/operations/op-1',
            'gcs_output_uri' => 'gs://ocr-output-test/out/',
            'batch_started_at' => now(),
            'provider' => 'google_document_ai',
        ]);

        $this->mock(GoogleDocumentAiBatchService::class, function ($mock) {
            $mock->shouldReceive('checkOperation')->once()->andReturn([
                'done' => true,
                'error' => null,
                'metadata' => [],
            ]);
        });

        app(OcrDocumentService::class)->checkBatchProcessing($document->id);

        $document->refresh();
        $this->assertSame(OcrDocument::STATUS_FINALIZING, $document->status);
        Queue::assertPushed(FinalizeBatchOcrResultJob::class);
    }

    public function test_finalizer_saves_combined_text_once(): void
    {
        $admin = $this->actingAsAdmin();

        $document = OcrDocument::query()->create([
            'uploaded_by' => $admin->id,
            'original_filename' => 'centralpart.pdf',
            'stored_filename' => 'centralpart.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/centralpart.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 3107780,
            'status' => OcrDocument::STATUS_FINALIZING,
            'processing_mode' => 'batch',
            'gcs_output_uri' => 'gs://ocr-output-test/out/',
            'provider' => 'google_document_ai',
        ]);

        $this->mock(GoogleDocumentAiBatchService::class, function ($mock) {
            $mock->shouldReceive('finalizeFromGcs')->once()->andReturn([
                'text' => "Page one text\n\nPage two text",
                'page_count' => 262,
                'languages' => ['en'],
                'pages' => [],
                'average_confidence' => 0.91,
                'result_checksum' => hash('sha256', 'Page one text'."\n\n".'Page two text'.'|262|2'),
                'shard_count' => 2,
            ]);
            $mock->shouldReceive('cleanupAfterSuccess')->once();
        });

        $service = app(OcrDocumentService::class);
        $service->finalizeBatchProcessing($document->id);
        $service->finalizeBatchProcessing($document->id); // idempotent

        $document->refresh();
        $this->assertSame(OcrDocument::STATUS_COMPLETED, $document->status);
        $this->assertSame(262, $document->page_count);
        $this->assertStringContainsString('Page one text', (string) $document->extracted_text);
    }

    public function test_failed_batch_check_marks_failed(): void
    {
        $admin = $this->actingAsAdmin();

        $document = OcrDocument::query()->create([
            'uploaded_by' => $admin->id,
            'original_filename' => 'centralpart.pdf',
            'stored_filename' => 'centralpart.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/centralpart.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 3107780,
            'status' => OcrDocument::STATUS_PROCESSING,
            'processing_mode' => 'batch',
            'provider_operation_name' => 'projects/test/operations/op-1',
            'batch_started_at' => now(),
            'provider' => 'google_document_ai',
        ]);

        $this->mock(GoogleDocumentAiBatchService::class, function ($mock) {
            $mock->shouldReceive('checkOperation')->once()->andReturn([
                'done' => true,
                'error' => 'Batch OCR processing failed. Please retry or verify Document AI configuration.',
                'metadata' => [],
            ]);
        });

        app(OcrDocumentService::class)->checkBatchProcessing($document->id);

        $document->refresh();
        $this->assertSame(OcrDocument::STATUS_FAILED, $document->status);
        $this->assertSame('batch_failed', $document->error_code);
    }

    public function test_retry_failed_large_pdf_uses_batch_mode(): void
    {
        $this->mock(PdfPageCounter::class, function ($mock) {
            $mock->shouldReceive('count')->andReturn(262);
        });

        Queue::fake();
        $admin = $this->actingAsAdmin();

        $document = OcrDocument::query()->create([
            'uploaded_by' => $admin->id,
            'original_filename' => 'centralpart.pdf',
            'stored_filename' => 'centralpart.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/centralpart.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 3107780,
            'status' => OcrDocument::STATUS_FAILED,
            'processing_mode' => 'online',
            'error_code' => 'page_limit_exceeded',
            'error_message' => 'too many pages',
            'provider' => 'google_document_ai',
        ]);
        Storage::disk('local')->put($document->storage_path, '%PDF-1.4');

        $this->postJson('/ocr-documents/'.$document->id.'/retry')
            ->assertOk()
            ->assertJsonPath('data.processing_mode', 'batch')
            ->assertJsonPath('data.status', 'queued');

        Queue::assertPushed(ProcessOcrDocumentJob::class);
    }

    public function test_responses_do_not_expose_credentials_or_bucket_paths(): void
    {
        $this->mock(PdfPageCounter::class, function ($mock) {
            $mock->shouldReceive('count')->andReturn(1);
        });

        Queue::fake();
        $this->actingAsAdmin();

        $response = $this->post('/ocr-documents', [
            'document' => $this->fakePdf(),
        ], ['Accept' => 'application/json'])->assertCreated();

        $json = $response->getContent();
        $this->assertStringNotContainsString('private_key', $json);
        $this->assertStringNotContainsString('ocr-input-test', $json);
        $this->assertStringNotContainsString('storage_path', $json);
    }

    public function test_small_pdf_processes_synchronously_when_fast_path_enabled(): void
    {
        config([
            'document-ai.sync_small_files' => true,
            'document-ai.sync_max_pages' => 5,
            'document-ai.sync_max_file_mb' => 5,
        ]);

        $this->mock(PdfPageCounter::class, function ($mock) {
            $mock->shouldReceive('count')->andReturn(1);
        });

        Queue::fake();
        $this->actingAsAdmin();

        $this->mock(\App\Contracts\Ocr\OcrProcessorInterface::class, function ($mock) {
            $mock->shouldReceive('processBinary')->once()->andReturn([
                'text' => 'Fast path OCR text',
                'page_count' => 1,
                'languages' => ['en'],
                'detected_languages' => ['en'],
                'pages' => [['page_number' => 1]],
                'entities' => [],
                'average_confidence' => 0.99,
                'processor_name' => 'projects/test/locations/us/processors/test',
                'provider_reference' => 'projects/test/locations/us/processors/test',
                'metadata' => [],
            ]);
        });

        $response = $this->post('/ocr-documents', [
            'document' => $this->fakePdf(),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame('completed', $response->json('data.status'));
        $this->assertSame('online', $response->json('data.processing_mode'));
        $this->assertSame(1, $response->json('data.page_count'));
        Queue::assertNothingPushed();
    }

    public function test_small_pdf_is_queued_when_fast_path_disabled(): void
    {
        config(['document-ai.sync_small_files' => false]);
        $this->mock(PdfPageCounter::class, function ($mock) {
            $mock->shouldReceive('count')->andReturn(1);
        });

        Queue::fake();
        $this->actingAsAdmin();

        $this->post('/ocr-documents', [
            'document' => $this->fakePdf(),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.processing_mode', 'online');

        Queue::assertPushed(ProcessOcrDocumentJob::class);
    }

    public function test_status_list_sends_no_store_cache_headers(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/ocr-documents')->assertOk();
        $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
    }

    public function test_batch_documents_never_use_small_file_sync_path(): void
    {
        config([
            'document-ai.sync_small_files' => true,
            'document-ai.sync_max_pages' => 5,
        ]);
        $this->mock(PdfPageCounter::class, function ($mock) {
            $mock->shouldReceive('count')->andReturn(262);
        });

        Queue::fake();
        $this->actingAsAdmin();

        $this->post('/ocr-documents', [
            'document' => $this->fakePdf(),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.processing_mode', 'batch')
            ->assertJsonPath('data.status', 'queued');

        Queue::assertPushed(ProcessOcrDocumentJob::class);
    }

    public function test_master_ca_upload_is_queued_even_when_small_file_sync_enabled(): void
    {
        config([
            'document-ai.sync_small_files' => true,
            'document-ai.sync_max_pages' => 5,
            'document-ai.sync_max_file_mb' => 5,
        ]);
        $this->mock(PdfPageCounter::class, function ($mock) {
            $mock->shouldReceive('count')->andReturn(4);
        });

        Queue::fake();
        $this->actingAsAdmin();

        $this->mock(\App\Contracts\Ocr\OcrProcessorInterface::class, function ($mock) {
            $mock->shouldReceive('processBinary')->never();
        });

        $this->post('/ocr-documents', [
            'import_type' => 'master_ca',
            'document' => $this->fakePdf(),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.import_type', 'master_ca')
            ->assertJsonPath('data.processing_mode', 'online');

        Queue::assertPushed(ProcessOcrDocumentJob::class, 1);
    }

    public function test_four_page_small_pdf_sync_path_stores_lean_structured_data(): void
    {
        config([
            'document-ai.sync_small_files' => true,
            'document-ai.sync_max_pages' => 5,
            'document-ai.sync_max_file_mb' => 5,
            'document-ai.imageless_mode' => true,
            'crm_mapping.queue_after_ocr_parse' => false,
        ]);

        $this->mock(PdfPageCounter::class, function ($mock) {
            $mock->shouldReceive('count')->andReturn(4);
        });

        Queue::fake();
        $this->actingAsAdmin();

        $pages = [];
        for ($page = 1; $page <= 4; $page++) {
            $paragraphs = [];
            for ($i = 0; $i < 40; $i++) {
                $paragraphs[] = [
                    'text' => "FIRM {$page}-{$i} & ASSOCIATES",
                    'x' => 0.12,
                    'y' => round(0.05 + ($i * 0.02), 4),
                    'confidence' => 0.91,
                    'bounding_box' => [
                        ['x' => 0.1, 'y' => 0.1],
                        ['x' => 0.4, 'y' => 0.1],
                        ['x' => 0.4, 'y' => 0.2],
                        ['x' => 0.1, 'y' => 0.2],
                    ],
                ];
            }
            $pages[] = [
                'page_number' => $page,
                'languages' => ['en'],
                'paragraph_count' => count($paragraphs),
                'paragraphs' => $paragraphs,
                'paragraph_layouts' => $paragraphs,
                'tables' => [['page_number' => $page, 'body_rows' => []]],
                'table_count' => 1,
                'confidence' => 0.9,
                'has_text' => true,
                'text_length' => 1200,
            ];
        }

        $peakBefore = memory_get_peak_usage(true);

        $this->mock(\App\Contracts\Ocr\OcrProcessorInterface::class, function ($mock) use ($pages) {
            $mock->shouldReceive('processBinary')->once()->andReturn([
                'text' => "PUNE\nMEHTA & CO\nRAJ MEHTA\n",
                'page_count' => 4,
                'languages' => ['en'],
                'detected_languages' => ['en'],
                'pages' => $pages,
                'entities' => [],
                'tables' => [['page_number' => 1, 'body_rows' => [['text' => 'A']]]],
                'structured_data' => [
                    'pages' => $pages,
                    'tables' => [['page_number' => 1, 'body_rows' => [['text' => 'A']]]],
                    'extraction_mode' => 'document_ai_tables_and_paragraphs',
                ],
                'average_confidence' => 0.92,
                'processor_name' => 'projects/test/locations/us/processors/test',
                'provider_reference' => 'projects/test/locations/us/processors/test',
                'metadata' => ['provider' => 'google_document_ai'],
            ]);
        });

        $response = $this->post('/ocr-documents', [
            'document' => UploadedFile::fake()->createWithContent(
                'northprop_first_4_pages.pdf',
                "%PDF-1.4\n".str_repeat('/Type /Page\n', 4)."\n%EOF",
                'application/pdf',
            ),
        ], ['Accept' => 'application/json'])->assertCreated();

        $peakAfter = memory_get_peak_usage(true);
        $this->assertLessThan(128 * 1024 * 1024, $peakAfter, '4-page sync OCR path must stay under 128MB peak');
        $this->assertLessThan(48 * 1024 * 1024, max(0, $peakAfter - $peakBefore), 'incremental memory for lean 4-page payload should stay practical');

        $this->assertSame('completed', $response->json('data.status'));
        $this->assertSame(4, $response->json('data.page_count'));
        Queue::assertNotPushed(ProcessOcrDocumentJob::class);

        $document = OcrDocument::query()->findOrFail($response->json('data.id'));
        $structured = $document->structured_data;
        $this->assertIsArray($structured);
        $this->assertArrayHasKey('pages', $structured);
        $this->assertArrayHasKey('tables', $structured);
        $this->assertCount(4, $structured['pages']);
        $this->assertArrayNotHasKey('paragraph_layouts', $structured['pages'][0]);
        $this->assertArrayNotHasKey('tables', $structured['pages'][0]);
        $this->assertArrayHasKey('paragraphs', $structured['pages'][0]);
        $this->assertTrue((bool) ($structured['metadata']['imageless_mode'] ?? false));
    }

    public function test_online_processing_failure_marks_document_failed_and_clears_progress(): void
    {
        config([
            'document-ai.sync_small_files' => true,
            'document-ai.sync_max_pages' => 5,
        ]);
        $this->mock(PdfPageCounter::class, function ($mock) {
            $mock->shouldReceive('count')->andReturn(4);
        });

        Queue::fake();
        $this->actingAsAdmin();

        $this->mock(\App\Contracts\Ocr\OcrProcessorInterface::class, function ($mock) {
            $mock->shouldReceive('processBinary')->once()->andThrow(new \RuntimeException('simulated provider failure'));
        });

        $response = $this->post('/ocr-documents', [
            'document' => $this->fakePdf(),
        ], ['Accept' => 'application/json'])->assertCreated();

        $document = OcrDocument::query()->findOrFail($response->json('data.id'));
        $this->assertSame(OcrDocument::STATUS_FAILED, $document->status);
        $this->assertNull($document->processing_progress);
        $this->assertNotNull($document->error_code);
        $this->assertNotNull($document->failed_at);
        Queue::assertNothingPushed();
    }

    public function test_list_endpoint_does_not_serialize_full_ocr_payload(): void
    {
        $admin = $this->actingAsAdmin();
        $marker = 'FULL_OCR_PAGE_PAYLOAD_MARKER_'.uniqid('', true);

        OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => $admin->id,
            'original_filename' => 'list-payload.pdf',
            'stored_filename' => 'list-payload.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/list-payload.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 60600,
            'status' => OcrDocument::STATUS_COMPLETED,
            'processing_mode' => 'online',
            'page_count' => 4,
            'extracted_text' => $marker.' '.str_repeat('X', 5000),
            'structured_data' => [
                'pages' => [
                    [
                        'page_number' => 1,
                        'paragraphs' => [['text' => $marker]],
                        'tables' => [['body_rows' => [[$marker]]]],
                    ],
                ],
                'tables' => [['page_number' => 1, 'body_rows' => [[$marker]]]],
            ],
            'processed_at' => now(),
        ]);

        $response = $this->getJson('/ocr-documents')->assertOk();
        $json = $response->getContent();
        $this->assertStringNotContainsString($marker, $json);
        $item = collect($response->json('data.items') ?? [])->firstWhere('original_filename', 'list-payload.pdf');
        $this->assertIsArray($item);
        $this->assertArrayNotHasKey('structured_data', $item);
        $this->assertArrayNotHasKey('extracted_text', $item);
    }

    public function test_retry_after_failed_memory_path_queues_without_duplicate_sync_dispatch(): void
    {
        config(['document-ai.sync_small_files' => false]);
        $this->mock(PdfPageCounter::class, function ($mock) {
            $mock->shouldReceive('count')->andReturn(4);
        });

        Queue::fake();
        $admin = $this->actingAsAdmin();

        $document = OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => $admin->id,
            'original_filename' => 'northprop_first_4_pages.pdf',
            'stored_filename' => 'northprop_first_4_pages.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/northprop_first_4_pages.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 62084,
            'status' => OcrDocument::STATUS_FAILED,
            'processing_mode' => 'online',
            'page_count' => 4,
            'error_code' => 'memory_exhausted',
            'error_message' => 'OCR processing ran out of memory while decoding the Document AI response. Please retry.',
            'failed_at' => now(),
        ]);
        Storage::disk('local')->put($document->storage_path, "%PDF-1.4\n".str_repeat('/Type /Page\n', 4));

        $this->postJson('/ocr-documents/'.$document->id.'/retry')
            ->assertOk()
            ->assertJsonPath('data.status', 'queued');

        Queue::assertPushed(ProcessOcrDocumentJob::class, 1);
        $document->refresh();
        $this->assertSame(OcrDocument::STATUS_QUEUED, $document->status);
        $this->assertNull($document->error_code);
    }
}
