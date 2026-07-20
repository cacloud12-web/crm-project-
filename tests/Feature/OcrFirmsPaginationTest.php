<?php

namespace Tests\Feature;

use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CrmTestAccounts;
use Tests\TestCase;

class OcrFirmsPaginationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        app(\App\Services\Rbac\RbacDatabaseService::class)->ensureConfigDefaultGrants();
        app(\App\Services\Rbac\RbacMatrixService::class)->flushCache();
    }

    private function actingAsAdmin(): User
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        return $admin;
    }

    public function test_firms_endpoint_paginates_all_records_and_search_finds_last_page(): void
    {
        $admin = $this->actingAsAdmin();
        $document = OcrDocument::query()->create([
            'uploaded_by' => $admin->id,
            'original_filename' => 'thousand.pdf',
            'stored_filename' => 'thousand.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/thousand.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 2000,
            'status' => OcrDocument::STATUS_COMPLETED,
            'processing_mode' => 'online',
            'parsed_firm_count' => 1000,
            'parse_status' => 'completed',
            'processed_at' => now(),
        ]);

        $rows = [];
        for ($i = 1; $i <= 1000; $i++) {
            $rows[] = [
                'ocr_document_id' => $document->id,
                'sequence_no' => $i,
                'firm_name' => 'FIRM '.$i.' & CO',
                'raw_firm_name' => 'FIRM '.$i.' & CO',
                'normalized_firm_name' => 'firm '.$i.' & co',
                'city' => 'CITY'.($i % 50),
                'review_status' => 'pending',
                'match_status' => 'needs_review',
                'page_number' => (int) ceil($i / 3),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            OcrParsedFirm::query()->insert($chunk);
        }

        // Seed a distinctive last firm for search.
        OcrParsedFirm::query()->where('ocr_document_id', $document->id)->where('sequence_no', 1000)
            ->update(['firm_name' => 'ZEBRA LAST FIRM & CO', 'city' => 'ZEDCITY']);

        $page1 = $this->getJson('/ocr-documents/'.$document->id.'/firms?per_page=50&page=1')
            ->assertOk()
            ->json('data');
        $this->assertSame(50, count($page1['items']));
        $this->assertSame(1000, $page1['pagination']['total']);
        $this->assertSame(20, $page1['pagination']['last_page']);

        $last = $this->getJson('/ocr-documents/'.$document->id.'/firms?per_page=50&page=20')
            ->assertOk()
            ->json('data');
        $this->assertSame(50, count($last['items']));
        $this->assertSame(1000, $last['items'][49]['sequence_no'] ?? $last['items'][49]['row_number'] ?? null);

        $search = $this->getJson('/ocr-documents/'.$document->id.'/firms?per_page=50&search='.urlencode('ZEBRA LAST'))
            ->assertOk()
            ->json('data');
        $this->assertGreaterThanOrEqual(1, $search['pagination']['total']);
        $this->assertStringContainsString('ZEBRA', (string) ($search['items'][0]['firm_name'] ?? ''));

        $show = $this->getJson('/ocr-documents/'.$document->id)->assertOk()->json('data');
        $this->assertTrue($show['firms_preview_limited'] ?? false);
        $this->assertLessThanOrEqual(100, count($show['parsed_firms'] ?? []));
        $this->assertSame(1000, (int) ($show['firms_total'] ?? $show['parsed_firm_count']));
    }

    public function test_export_csv_streams_all_three_fields(): void
    {
        $admin = $this->actingAsAdmin();
        $document = OcrDocument::query()->create([
            'uploaded_by' => $admin->id,
            'original_filename' => 'export.pdf',
            'stored_filename' => 'export.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/export.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 500,
            'status' => OcrDocument::STATUS_COMPLETED,
            'parsed_firm_count' => 2,
            'parse_status' => 'completed',
            'processed_at' => now(),
        ]);
        OcrParsedFirm::query()->create([
            'ocr_document_id' => $document->id,
            'sequence_no' => 1,
            'firm_name' => 'ALPHA & CO',
            'city' => 'ADIPUR',
            'review_status' => 'pending',
        ]);
        OcrParsedFirm::query()->create([
            'ocr_document_id' => $document->id,
            'sequence_no' => 2,
            'firm_name' => 'BETA & ASSOCIATES',
            'city' => 'AMBALA',
            'review_status' => 'pending',
        ]);

        $response = $this->get('/ocr-documents/'.$document->id.'/firms/export');
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('city,firm_name,ca_name,partner_count,status', $csv);
        $this->assertStringContainsString('ALPHA & CO', $csv);
        $this->assertStringContainsString('ADIPUR', $csv);
        $this->assertStringContainsString('BETA & ASSOCIATES', $csv);
    }

    public function test_recover_stuck_command_is_registered(): void
    {
        $this->assertSame(0, Artisan::call('ocr:recover-stuck', ['--dry-run' => true, '--minutes' => 10]));
    }
}
