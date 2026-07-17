<?php

namespace Tests\Feature;

use App\Models\OcrDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OcrDuplicateFileUploadTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        config([
            'document-ai.storage_disk' => 'local',
            'document-ai.processor_name' => 'projects/test/locations/us/processors/test',
            'document-ai.project_id' => 'test',
            'document-ai.location' => 'us',
        ]);
        app(\App\Services\Rbac\RbacDatabaseService::class)->ensureConfigDefaultGrants();
        app(\App\Services\Rbac\RbacMatrixService::class)->flushCache();
    }

    public function test_duplicate_checksum_blocks_reimport_without_force(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $contents = '%PDF-1.4 duplicate-hash-body-'.uniqid('', true);
        $file = UploadedFile::fake()->createWithContent('dup-check.pdf', $contents);

        OcrDocument::query()->create([
            'uploaded_by' => $admin->id,
            'original_filename' => 'first.pdf',
            'stored_filename' => 'first.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/first.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => strlen($contents),
            'checksum' => hash('sha256', $contents),
            'status' => OcrDocument::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);

        $blocked = $this->post('/ocr-documents', [
            'document' => $file,
        ], ['Accept' => 'application/json']);

        $blocked->assertStatus(422);
        $blocked->assertJsonPath('errors.duplicate_file', true);
        $blocked->assertJsonPath('errors.requires_confirmation', true);
        $this->assertStringContainsString('already been imported', (string) $blocked->json('message'));

        $forced = $this->post('/ocr-documents', [
            'document' => UploadedFile::fake()->createWithContent('dup-check-2.pdf', $contents),
            'force_reimport' => 1,
        ], ['Accept' => 'application/json']);

        $forced->assertSuccessful();
        $this->assertSame(2, OcrDocument::query()->where('checksum', hash('sha256', $contents))->count());
    }
}
