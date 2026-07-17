<?php

namespace Tests\Feature;

use App\Jobs\ProcessOcrDocumentJob;
use App\Models\OcrDocument;
use App\Models\User;
use App\Services\Ocr\OcrDocumentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class OcrPipelineResumeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_stuck_processing_without_text_is_resumed_instead_of_skipped(): void
    {
        Queue::fake();

        $document = OcrDocument::query()->create([
            'uploaded_by' => User::query()->value('id'),
            'original_filename' => 'stuck.pdf',
            'stored_filename' => 'stuck.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/missing-stuck-'.uniqid('', true).'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'checksum' => hash('sha256', 'stuck-pipeline-'.uniqid('', true)),
            'status' => OcrDocument::STATUS_PROCESSING,
            'provider' => 'google_document_ai',
            'processing_mode' => 'online',
            'processing_progress' => 'Online OCR processing',
            'processing_started_at' => now()->subMinutes(20),
            'processing_attempts' => 1,
            'extracted_text' => null,
        ]);

        try {
            app(OcrDocumentService::class)->processQueuedDocument((int) $document->id);
        } catch (\Throwable) {
            // Expected: missing storage file after resume.
        }

        $document->refresh();
        // Previously this stayed forever on "processing". Now it must leave that dead state.
        $this->assertNotSame('Online OCR processing', $document->processing_progress);
        $this->assertTrue(
            in_array($document->status, [OcrDocument::STATUS_FAILED, OcrDocument::STATUS_QUEUED, OcrDocument::STATUS_COMPLETED], true),
            'Stuck processing must be resumed or failed, not left idle. Status='.$document->status,
        );
        $this->assertSame('storage_missing', $document->error_code);
        $this->assertSame(OcrDocument::STATUS_FAILED, $document->status);
    }

    public function test_process_job_rethrows_exceptions(): void
    {
        $service = Mockery::mock(OcrDocumentService::class);
        $service->shouldReceive('processQueuedDocument')
            ->once()
            ->andThrow(new \RuntimeException('provider down'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('provider down');

        (new ProcessOcrDocumentJob(999))->handle($service);
    }
}
