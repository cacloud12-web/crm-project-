<?php

namespace Tests\Feature;

use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Models\OcrParsedMember;
use App\Models\User;
use App\Services\Ocr\OcrSelectedTransferService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Support\CrmTestAccounts;
use Tests\TestCase;

class OcrSelectedTransferTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAdmin(): User
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        return $admin;
    }

    private function seedTransferFixture(User $admin): array
    {
        $existing = OcrDocument::query()->create([
            'uploaded_by' => $admin->id,
            'original_filename' => 'existing-live.pdf',
            'stored_filename' => 'existing-live.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr/existing-live.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1000,
            'status' => OcrDocument::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);

        $docA = OcrDocument::query()->create([
            'uploaded_by' => $admin->id,
            'ca_id' => null,
            'import_batch_id' => 99,
            'original_filename' => 'transfer-a.pdf',
            'stored_filename' => 'transfer-a.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr/transfer-a.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1200,
            'status' => OcrDocument::STATUS_COMPLETED,
            'extracted_text' => 'Sample extracted text',
            'structured_data' => ['pages' => [['page' => 1]]],
            'corrected_by' => $admin->id,
            'processed_at' => now(),
            'parse_status' => 'completed',
            'parsed_firm_count' => 1,
        ]);

        $docB = OcrDocument::query()->create([
            'uploaded_by' => $admin->id,
            'original_filename' => 'transfer-b.pdf',
            'stored_filename' => 'transfer-b.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr/transfer-b.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1300,
            'status' => OcrDocument::STATUS_COMPLETED,
            'processed_at' => now(),
            'parse_status' => 'completed',
            'parsed_firm_count' => 1,
        ]);

        $firmA = OcrParsedFirm::query()->create([
            'ocr_document_id' => $docA->id,
            'sequence_no' => 1,
            'firm_name' => 'Alpha Firm',
            'city' => 'Mumbai',
            'review_status' => 'approved',
            'crm_ca_id' => 12345,
            'matched_ca_id' => 54321,
            'matched_reference_firm_id' => 777,
            'source_data' => ['raw' => 'alpha'],
            'field_meta' => ['city' => ['confidence' => 0.9]],
        ]);

        $firmB = OcrParsedFirm::query()->create([
            'ocr_document_id' => $docB->id,
            'sequence_no' => 1,
            'firm_name' => 'Beta Firm',
            'city' => 'Pune',
            'review_status' => 'pending',
            'source_data' => ['raw' => 'beta'],
        ]);

        $memberA = OcrParsedMember::query()->create([
            'ocr_parsed_firm_id' => $firmA->id,
            'sequence_no' => 1,
            'ca_name' => 'Alpha Partner',
            'mobile' => '9876543210',
            'matched_reference_member_id' => 888,
            'review_status' => 'approved',
            'source_data' => ['raw' => 'partner'],
        ]);

        $memberB = OcrParsedMember::query()->create([
            'ocr_parsed_firm_id' => $firmB->id,
            'sequence_no' => 1,
            'ca_name' => 'Beta Partner',
            'mobile' => '9876500011',
            'review_status' => 'pending',
        ]);

        return compact('existing', 'docA', 'docB', 'firmA', 'firmB', 'memberA', 'memberB');
    }

    public function test_export_selected_creates_manifest_and_ndjson_package(): void
    {
        $admin = $this->actingAdmin();
        $fixture = $this->seedTransferFixture($admin);
        $ids = [$fixture['docA']->id, $fixture['docB']->id];

        $result = app(OcrSelectedTransferService::class)->export($ids);
        $path = $result['path'];

        $this->assertDirectoryExists($path);
        $this->assertFileExists($path.'/manifest.json');
        $this->assertFileExists($path.'/documents.ndjson');
        $this->assertFileExists($path.'/firms.ndjson');
        $this->assertFileExists($path.'/members.ndjson');

        $manifest = json_decode((string) file_get_contents($path.'/manifest.json'), true);
        $this->assertSame(2, $manifest['documents']['count']);
        $this->assertSame(2, $manifest['firms']['count']);
        $this->assertSame(2, $manifest['members']['count']);
        $this->assertSame(['transfer-a.pdf', 'transfer-b.pdf'], $manifest['filenames']);

        File::deleteDirectory($path);
    }

    public function test_export_dry_run_does_not_write_files(): void
    {
        $admin = $this->actingAdmin();
        $fixture = $this->seedTransferFixture($admin);

        $result = app(OcrSelectedTransferService::class)->export([$fixture['docA']->id], true);
        $this->assertFalse(is_dir($result['path']));
        $this->assertSame(1, $result['manifest']['documents']['count']);
    }

    private function simulateLiveWithoutSourceRows(array $documentIds): void
    {
        $firmIds = OcrParsedFirm::query()->whereIn('ocr_document_id', $documentIds)->pluck('id');
        OcrParsedMember::query()->whereIn('ocr_parsed_firm_id', $firmIds)->delete();
        OcrParsedFirm::query()->whereIn('ocr_document_id', $documentIds)->delete();
        OcrDocument::query()->whereIn('id', $documentIds)->forceDelete();
    }

    public function test_import_selected_remaps_ids_and_preserves_relationships(): void
    {
        $admin = $this->actingAdmin();
        $fixture = $this->seedTransferFixture($admin);
        $service = app(OcrSelectedTransferService::class);
        $export = $service->export([$fixture['docA']->id, $fixture['docB']->id]);
        $path = $export['path'];
        $this->simulateLiveWithoutSourceRows([$fixture['docA']->id, $fixture['docB']->id]);

        $summary = $service->import($path, $admin->id, 500, false, false);
        $this->assertSame(2, $summary['documents_imported']);
        $this->assertSame(2, $summary['firms_imported']);
        $this->assertSame(2, $summary['members_imported']);

        $newDocA = (int) $summary['document_id_map'][(string) $fixture['docA']->id];
        $newDocB = (int) $summary['document_id_map'][(string) $fixture['docB']->id];
        $newFirmA = (int) $summary['firm_id_map'][(string) $fixture['firmA']->id];
        $newFirmB = (int) $summary['firm_id_map'][(string) $fixture['firmB']->id];

        $this->assertNotSame($fixture['docA']->id, $newDocA);
        $this->assertNotSame($fixture['firmA']->id, $newFirmA);

        $importedFirmA = OcrParsedFirm::query()->findOrFail($newFirmA);
        $this->assertSame($newDocA, (int) $importedFirmA->ocr_document_id);
        $this->assertSame('Alpha Firm', $importedFirmA->firm_name);
        $this->assertNull($importedFirmA->crm_ca_id);
        $this->assertNull($importedFirmA->matched_ca_id);
        $this->assertNull($importedFirmA->matched_reference_firm_id);

        $importedMemberA = OcrParsedMember::query()
            ->where('ocr_parsed_firm_id', $newFirmA)
            ->firstOrFail();
        $this->assertSame('Alpha Partner', $importedMemberA->ca_name);
        $this->assertNull($importedMemberA->matched_reference_member_id);

        $importedDocA = OcrDocument::query()->findOrFail($newDocA);
        $this->assertSame($admin->id, (int) $importedDocA->uploaded_by);
        $this->assertNull($importedDocA->ca_id);
        $this->assertNull($importedDocA->import_batch_id);
        $this->assertNull($importedDocA->corrected_by);
        $this->assertSame('Sample extracted text', $importedDocA->extracted_text);

        $this->assertSame(1, OcrDocument::query()->where('original_filename', 'existing-live.pdf')->count());

        File::deleteDirectory($path);
    }

    public function test_import_dry_run_does_not_insert_rows(): void
    {
        $admin = $this->actingAdmin();
        $fixture = $this->seedTransferFixture($admin);
        $service = app(OcrSelectedTransferService::class);
        $export = $service->export([$fixture['docA']->id]);
        $this->simulateLiveWithoutSourceRows([$fixture['docA']->id]);
        $before = OcrDocument::query()->count();

        $summary = $service->import($export['path'], $admin->id, 500, true, false);
        $this->assertSame(1, $summary['would_import']['documents']);
        $this->assertSame($before, OcrDocument::query()->count());

        File::deleteDirectory($export['path']);
    }

    public function test_import_prevents_duplicate_batch_import(): void
    {
        $admin = $this->actingAdmin();
        $fixture = $this->seedTransferFixture($admin);
        $service = app(OcrSelectedTransferService::class);
        $export = $service->export([$fixture['docA']->id]);
        $this->simulateLiveWithoutSourceRows([$fixture['docA']->id]);
        $service->import($export['path'], $admin->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already imported');
        $service->import($export['path'], $admin->id);

        File::deleteDirectory($export['path']);
    }

    public function test_export_command_fails_for_non_completed_document(): void
    {
        $admin = $this->actingAdmin();
        $doc = OcrDocument::query()->create([
            'uploaded_by' => $admin->id,
            'original_filename' => 'queued.pdf',
            'stored_filename' => 'queued.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr/queued.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 500,
            'status' => OcrDocument::STATUS_QUEUED,
        ]);

        Artisan::call('ocr:export-selected', ['--documents' => (string) $doc->id]);
        $this->assertStringContainsString('completed', Artisan::output());
    }
}
