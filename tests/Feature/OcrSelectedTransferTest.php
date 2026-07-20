<?php

namespace Tests\Feature;

use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Models\OcrParsedMember;
use App\Models\User;
use App\Services\Ocr\OcrSelectedTransferService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
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

    /** @return array<string, mixed> */
    private function seedTransferFixture(User $admin): array
    {
        $this->seedExistingLiveDocument($admin);

        $docA = $this->createLocalDocument($admin, 52, 'transfer-a.pdf');
        $docB = $this->createLocalDocument($admin, 53, 'transfer-b.pdf');

        $firmA = OcrParsedFirm::query()->create([
            'ocr_document_id' => 52,
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
            'ocr_document_id' => 53,
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

        return compact('docA', 'docB', 'firmA', 'firmB', 'memberA', 'memberB');
    }

    private function seedExistingLiveDocument(User $admin): void
    {
        if (OcrDocument::query()->whereKey(1)->exists()) {
            return;
        }

        DB::table('ocr_documents')->insert([
            'id' => 1,
            'uploaded_by' => $admin->id,
            'original_filename' => 'existing-live.pdf',
            'stored_filename' => 'existing-live.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr/existing-live.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1000,
            'status' => OcrDocument::STATUS_COMPLETED,
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createLocalDocument(User $admin, int $id, string $filename): OcrDocument
    {
        DB::table('ocr_documents')->insert([
            'id' => $id,
            'uploaded_by' => $admin->id,
            'ca_id' => null,
            'import_batch_id' => 99,
            'original_filename' => $filename,
            'stored_filename' => $filename,
            'storage_disk' => 'local',
            'storage_path' => 'ocr/'.$filename,
            'mime_type' => 'application/pdf',
            'file_size' => 1200,
            'status' => OcrDocument::STATUS_COMPLETED,
            'extracted_text' => $filename === 'transfer-a.pdf' ? 'Sample extracted text' : null,
            'structured_data' => $filename === 'transfer-a.pdf' ? json_encode(['pages' => [['page' => 1]]]) : null,
            'corrected_by' => $filename === 'transfer-a.pdf' ? $admin->id : null,
            'processed_at' => now(),
            'parse_status' => 'completed',
            'parsed_firm_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return OcrDocument::query()->findOrFail($id);
    }

    public function test_export_selected_creates_manifest_and_ndjson_package(): void
    {
        $admin = $this->actingAdmin();
        $fixture = $this->seedTransferFixture($admin);

        $result = app(OcrSelectedTransferService::class)->export([52, 53]);
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
        $this->assertSame([52, 53], $manifest['source_document_ids']);
        $this->assertSame(['transfer-a.pdf', 'transfer-b.pdf'], $manifest['filenames']);

        File::deleteDirectory($path);
    }

    public function test_export_dry_run_does_not_write_files(): void
    {
        $admin = $this->actingAdmin();
        $this->seedTransferFixture($admin);

        $result = app(OcrSelectedTransferService::class)->export([52], true);
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
        $export = $service->export([52, 53]);
        $path = $export['path'];
        $this->simulateLiveWithoutSourceRows([52, 53]);

        $beforeCount = OcrDocument::query()->count();
        $summary = $service->import($path, $admin->id, 500, false, false);

        $this->assertSame(2, $summary['documents_imported']);
        $this->assertSame(2, $summary['firms_imported']);
        $this->assertSame(2, $summary['members_imported']);
        $this->assertSame($beforeCount + 2, OcrDocument::query()->count());

        $newDocA = (int) $summary['document_id_map'][52];
        $newDocB = (int) $summary['document_id_map'][53];
        $newFirmA = (int) $summary['firm_id_map'][(int) $fixture['firmA']->id];
        $newFirmB = (int) $summary['firm_id_map'][(int) $fixture['firmB']->id];

        $this->assertNotSame(52, $newDocA);
        $this->assertNotSame(53, $newDocB);
        $this->assertNotSame((int) $fixture['firmA']->id, $newFirmA);

        $importedFirmA = OcrParsedFirm::query()->findOrFail($newFirmA);
        $this->assertSame($newDocA, (int) $importedFirmA->ocr_document_id);
        $this->assertNotSame(52, (int) $importedFirmA->ocr_document_id);
        $this->assertSame('Alpha Firm', $importedFirmA->firm_name);
        $this->assertNull($importedFirmA->crm_ca_id);
        $this->assertNull($importedFirmA->matched_ca_id);
        $this->assertNull($importedFirmA->matched_reference_firm_id);

        $importedFirmB = OcrParsedFirm::query()->findOrFail($newFirmB);
        $this->assertSame($newDocB, (int) $importedFirmB->ocr_document_id);

        $importedMemberA = OcrParsedMember::query()
            ->where('ocr_parsed_firm_id', $newFirmA)
            ->firstOrFail();
        $this->assertSame('Alpha Partner', $importedMemberA->ca_name);
        $this->assertNull($importedMemberA->matched_reference_member_id);

        $importedMemberB = OcrParsedMember::query()
            ->where('ocr_parsed_firm_id', $newFirmB)
            ->firstOrFail();
        $this->assertSame('Beta Partner', $importedMemberB->ca_name);

        $importedDocA = OcrDocument::query()->findOrFail($newDocA);
        $this->assertSame($admin->id, (int) $importedDocA->uploaded_by);
        $this->assertNull($importedDocA->ca_id);
        $this->assertNull($importedDocA->import_batch_id);
        $this->assertNull($importedDocA->corrected_by);
        $this->assertSame('Sample extracted text', $importedDocA->extracted_text);

        $this->assertSame(1, (int) OcrDocument::query()->whereKey(1)->value('id'));
        $this->assertFalse(OcrDocument::query()->whereKey(52)->exists());
        $this->assertFalse(OcrDocument::query()->whereKey(53)->exists());

        File::deleteDirectory($path);
    }

    public function test_import_dry_run_does_not_insert_rows(): void
    {
        $admin = $this->actingAdmin();
        $this->seedTransferFixture($admin);
        $service = app(OcrSelectedTransferService::class);
        $export = $service->export([52]);
        $this->simulateLiveWithoutSourceRows([52]);
        $before = OcrDocument::query()->count();

        $summary = $service->import($export['path'], $admin->id, 500, true, false);
        $this->assertSame(1, $summary['would_import']['documents']);
        $this->assertSame($before, OcrDocument::query()->count());

        File::deleteDirectory($export['path']);
    }

    public function test_import_prevents_duplicate_batch_import(): void
    {
        $admin = $this->actingAdmin();
        $this->seedTransferFixture($admin);
        $service = app(OcrSelectedTransferService::class);
        $export = $service->export([52]);
        $this->simulateLiveWithoutSourceRows([52]);
        $service->import($export['path'], $admin->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already imported');
        $service->import($export['path'], $admin->id);

        File::deleteDirectory($export['path']);
    }

    public function test_import_rolls_back_when_document_mapping_is_missing(): void
    {
        $admin = $this->actingAdmin();
        $this->seedTransferFixture($admin);
        $service = app(OcrSelectedTransferService::class);
        $export = $service->export([52, 53]);
        $path = $export['path'];
        $this->simulateLiveWithoutSourceRows([52, 53]);

        $manifest = json_decode((string) file_get_contents($path.'/manifest.json'), true);
        $statePath = storage_path('app/ocr-transfer/.import-state/'.$manifest['batch_id'].'.json');
        @mkdir(dirname($statePath), 0755, true);
        file_put_contents($statePath, json_encode([
            'documents_done' => true,
            'documents_imported' => 2,
            'document_id_map' => [],
            'firms_done' => false,
        ], JSON_PRETTY_PRINT));

        $beforeDocs = OcrDocument::query()->count();
        $beforeFirms = OcrParsedFirm::query()->count();
        $beforeMembers = OcrParsedMember::query()->count();

        try {
            $service->import($path, $admin->id, 500, false, true);
            $this->fail('Expected import to fail on missing document mapping.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Missing document ID mapping for local document', $exception->getMessage());
        }

        $this->assertSame($beforeDocs, OcrDocument::query()->count());
        $this->assertSame($beforeFirms, OcrParsedFirm::query()->count());
        $this->assertSame($beforeMembers, OcrParsedMember::query()->count());

        @unlink($statePath);
        File::deleteDirectory($path);
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

    public function test_imports_over_one_hundred_thousand_firms_without_placeholder_limit_error(): void
    {
        $admin = $this->actingAdmin();
        $this->seedExistingLiveDocument($admin);
        $this->createLocalDocument($admin, 52, 'bulk-firms.pdf');

        $firmCount = 100_001;
        $path = $this->buildLargeFirmImportPackage($admin, $firmCount);
        $this->simulateLiveWithoutSourceRows([52]);

        $service = app(OcrSelectedTransferService::class);
        $summary = $service->import($path, $admin->id, 5000, false, false);

        $this->assertSame(1, $summary['documents_imported']);
        $this->assertSame($firmCount, $summary['firms_imported']);
        $this->assertSame(0, $summary['members_imported']);
        $this->assertSame(
            $firmCount,
            OcrParsedFirm::query()->where('ocr_document_id', $summary['document_id_map'][52])->count(),
        );
        $this->assertFalse(OcrParsedFirm::query()->where('ocr_document_id', 52)->exists());

        File::deleteDirectory($path);
        @unlink(storage_path('app/ocr-transfer/.imported/'.basename($path).'.json'));
        @unlink(storage_path('app/ocr-transfer/.import-state/'.basename($path).'.json'));
    }

    private function buildLargeFirmImportPackage(User $admin, int $firmCount): string
    {
        $batchId = 'test-large-'.uniqid('', true);
        $path = storage_path('app/ocr-transfer/'.$batchId);
        mkdir($path, 0755, true);

        $docColumns = Schema::getColumnListing('ocr_documents');
        $firmColumns = Schema::getColumnListing('ocr_parsed_firms');
        $memberColumns = Schema::getColumnListing('ocr_parsed_members');

        $documentRow = [
            'id' => 52,
            'uploaded_by' => $admin->id,
            'original_filename' => 'bulk-firms.pdf',
            'stored_filename' => 'bulk-firms.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr/bulk-firms.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 2048,
            'status' => OcrDocument::STATUS_COMPLETED,
            'provider' => 'google_document_ai',
            'processing_attempts' => 0,
            'processed_at' => now()->toIso8601String(),
            'parse_status' => 'completed',
            'parsed_firm_count' => $firmCount,
        ];
        file_put_contents($path.'/documents.ndjson', json_encode($documentRow, JSON_UNESCAPED_UNICODE).PHP_EOL);
        file_put_contents($path.'/members.ndjson', '');

        $firmsHandle = fopen($path.'/firms.ndjson', 'wb');
        $template = [
            'ocr_document_id' => 52,
            'sequence_no' => 1,
            'raw_firm_name' => 'Bulk Import Firm',
            'firm_name' => 'Bulk Import Firm',
            'normalized_firm_name' => 'BULK IMPORT FIRM',
            'firm_type' => 'Partnership',
            'address' => '123 Market Street, Sample City',
            'city' => 'Mumbai',
            'district' => 'Mumbai',
            'state' => 'Maharashtra',
            'pincode' => '400001',
            'phone' => '9876543210',
            'email' => 'bulk@example.test',
            'website' => 'https://example.test',
            'partner_count' => 2,
            'review_status' => 'pending',
            'overall_confidence' => 0.9123,
            'page_number' => 3,
            'row_number' => 10,
            'column_number' => 2,
            'match_status' => 'unmapped',
            'match_confidence' => 0.55,
            'match_reason' => 'pending_review',
            'match_candidates' => json_encode([['ca_id' => 1, 'score' => 0.4]]),
            'source_data' => json_encode(['source' => 'bulk-test']),
            'notes' => 'bulk regression row',
            'field_meta' => json_encode(['city' => ['confidence' => 0.91]]),
            'bounding_box' => json_encode(['x' => 1, 'y' => 2, 'w' => 3, 'h' => 4]),
            'validation_errors' => json_encode([]),
            'parse_run_id' => 'parse-run-bulk',
            'source_fingerprint' => 'fp-source',
            'business_fingerprint' => 'fp-business',
            'is_noise' => 0,
        ];

        for ($i = 1; $i <= $firmCount; $i++) {
            $row = $template;
            $row['id'] = $i;
            $row['sequence_no'] = $i;
            $row['firm_name'] = 'Bulk Import Firm '.$i;
            $row['normalized_firm_name'] = 'BULK IMPORT FIRM '.$i;
            $row['source_fingerprint'] = 'fp-source-'.$i;
            $row['business_fingerprint'] = 'fp-business-'.$i;
            fwrite($firmsHandle, json_encode($row, JSON_UNESCAPED_UNICODE).PHP_EOL);
        }
        fclose($firmsHandle);

        $manifest = [
            'batch_id' => $batchId,
            'exported_at' => now()->toIso8601String(),
            'source_connection' => 'sqlite',
            'source_document_ids' => [52],
            'filenames' => ['bulk-firms.pdf'],
            'documents' => ['count' => 1, 'columns' => $docColumns, 'file' => 'documents.ndjson'],
            'firms' => ['count' => $firmCount, 'columns' => $firmColumns, 'file' => 'firms.ndjson'],
            'members' => ['count' => 0, 'columns' => $memberColumns, 'file' => 'members.ndjson'],
            'orphan_checks' => ['orphan_firms' => 0, 'orphan_members' => 0],
        ];
        $manifest = $this->finalizeTransferManifest($manifest, $path);
        file_put_contents($path.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $path;
    }

    /** @param  array<string, mixed>  $manifest */
    private function finalizeTransferManifest(array $manifest, string $path): array
    {
        foreach (['documents', 'firms', 'members'] as $section) {
            $file = $manifest[$section]['file'] ?? "{$section}.ndjson";
            $manifest[$section]['sha256'] = hash_file('sha256', $path.'/'.$file);
        }

        $copy = $manifest;
        unset($copy['checksum']);
        $manifest['checksum'] = hash('sha256', json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $manifest;
    }
}
