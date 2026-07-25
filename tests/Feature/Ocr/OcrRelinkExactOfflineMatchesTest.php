<?php

namespace Tests\Feature\Ocr;

use App\Services\Ocr\OcrRelinkExactOfflineMatchesService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OcrRelinkExactOfflineMatchesTest extends TestCase
{
    public function test_dry_run_aborts_on_duplicate_ocr_firm_ids(): void
    {
        $dir = storage_path('app/audits');
        @mkdir($dir, 0755, true);
        $csv = $dir.'/test-relink-dup-matches.csv';
        $fh = fopen($csv, 'wb');
        fputcsv($fh, [
            'CA ID', 'Master Firm Name', 'Matched OCR Firm', 'OCR City', 'Confidence',
            'Normalized Key', 'OCR Firm ID', 'OCR Document ID', 'Match Count',
        ]);
        fputcsv($fh, [910101, 'Dup Firm A', 'Dup Firm', 'Pune', 'Exact', 'DUP FIRM', 777001, 52, 1]);
        fputcsv($fh, [910102, 'Dup Firm B', 'Dup Firm', 'Pune', 'Exact', 'DUP FIRM', 777001, 52, 1]);
        fputcsv($fh, [910103, 'Other', 'Other', 'Mumbai', 'Strong', 'OTHER', 777002, 52, 2]);
        fclose($fh);

        $this->artisan('ocr:relink-exact-offline-matches', [
            '--dry-run' => true,
            '--matches-csv' => $csv,
            '--trust-csv-ids' => true,
            '--export' => $dir.'/test-relink-dup-out.csv',
        ])->assertFailed();
    }

    public function test_apply_relinks_exact_only_and_never_sets_city(): void
    {
        if (! Schema::hasTable('ca_masters') || ! Schema::hasColumn('ca_masters', 'source_ocr_row_id')) {
            $this->markTestSkipped('schema missing');
        }

        $caId = (int) (DB::table('ca_masters')->max('ca_id') ?? 0) + 920001;
        $firmId = 778001;
        $docId = 52;

        $insert = [
            'ca_id' => $caId,
            'firm_name' => 'Relink Exact Test Firm',
            'ca_name' => 'Test CA',
            'city_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('ca_masters', 'source_id')) {
            $insert['source_id'] = 1;
        }
        if (Schema::hasColumn('ca_masters', 'source_ocr_row_id')) {
            $insert['source_ocr_row_id'] = null;
        }
        if (Schema::hasColumn('ca_masters', 'source_ocr_document_id')) {
            $insert['source_ocr_document_id'] = null;
        }
        DB::table('ca_masters')->insert($insert);

        $csv = storage_path('app/audits/test-relink-exact-matches.csv');
        $fh = fopen($csv, 'wb');
        fputcsv($fh, [
            'CA ID', 'Master Firm Name', 'Matched OCR Firm', 'OCR City', 'Confidence',
            'Normalized Key', 'OCR Firm ID', 'OCR Document ID', 'Match Count',
        ]);
        fputcsv($fh, [
            $caId, 'Relink Exact Test Firm', 'Relink Exact Test Firm', 'Surat', 'Exact',
            'RELINK EXACT TEST FIRM', $firmId, $docId, 1,
        ]);
        fputcsv($fh, [
            $caId + 1, 'Strong Firm', 'Strong Firm', 'Pune', 'Strong',
            'STRONG FIRM', $firmId + 1, $docId, 3,
        ]);
        fclose($fh);

        $export = storage_path('app/audits/test-relink-exact-apply.csv');
        $this->artisan('ocr:relink-exact-offline-matches', [
            '--apply' => true,
            '--matches-csv' => $csv,
            '--trust-csv-ids' => true,
            '--export' => $export,
        ])->expectsConfirmation(
            'Restore ONLY source_ocr_row_id + source_ocr_document_id for Exact matches? city_id and business fields will NOT change.',
            'yes'
        )->assertSuccessful();

        $row = DB::table('ca_masters')->where('ca_id', $caId)->first();
        $this->assertSame($firmId, (int) $row->source_ocr_row_id);
        if (Schema::hasColumn('ca_masters', 'source_ocr_document_id')) {
            $this->assertSame($docId, (int) $row->source_ocr_document_id);
        }
        $this->assertTrue($row->city_id === null || (int) $row->city_id === 0);
        $this->assertSame('Relink Exact Test Firm', $row->firm_name);

        $rollback = preg_replace('/\.csv$/i', '.rollback.json', $export);
        $this->assertFileExists($rollback);

        $svc = app(OcrRelinkExactOfflineMatchesService::class);
        $rb = $svc->rollback($rollback, true, 50);
        $this->assertGreaterThanOrEqual(1, $rb['rolled_back']);

        $after = DB::table('ca_masters')->where('ca_id', $caId)->first();
        $this->assertTrue($after->source_ocr_row_id === null || (int) $after->source_ocr_row_id === 0);

        DB::table('ca_masters')->where('ca_id', $caId)->delete();
    }

    public function test_skip_duplicate_ocr_ids_allows_unique_exact_rows(): void
    {
        $dir = storage_path('app/audits');
        $csv = $dir.'/test-relink-skip-dup-matches.csv';
        $fh = fopen($csv, 'wb');
        fputcsv($fh, [
            'CA ID', 'Master Firm Name', 'Matched OCR Firm', 'OCR City', 'Confidence',
            'Normalized Key', 'OCR Firm ID', 'OCR Document ID', 'Match Count',
        ]);
        fputcsv($fh, [910201, 'Dup A', 'Dup', 'Pune', 'Exact', 'DUP', 779001, 52, 1]);
        fputcsv($fh, [910202, 'Dup B', 'Dup', 'Pune', 'Exact', 'DUP', 779001, 52, 1]);
        fputcsv($fh, [910203, 'Solo Firm', 'Solo Firm', 'Surat', 'Exact', 'SOLO FIRM', 779002, 52, 1]);
        fclose($fh);

        $this->artisan('ocr:relink-exact-offline-matches', [
            '--dry-run' => true,
            '--matches-csv' => $csv,
            '--trust-csv-ids' => true,
            '--skip-duplicate-ocr-ids' => true,
            '--export' => $dir.'/test-relink-skip-dup-out.csv',
        ])->assertSuccessful();
    }

    public function test_ambiguous_ocr_rows_are_skipped_not_aborted(): void
    {
        if (! Schema::hasTable('ocr_parsed_firms') || ! Schema::hasTable('ocr_documents')) {
            $this->markTestSkipped('ocr tables missing');
        }

        $docId = (int) (DB::table('ocr_documents')->max('id') ?? 0);
        if ($docId <= 0) {
            $docId = (int) DB::table('ocr_documents')->insertGetId([
                'uploaded_by' => 1,
                'original_filename' => 'ambiguous-relink-test.pdf',
                'stored_filename' => 'ambiguous-relink-test.pdf',
                'storage_disk' => 'local',
                'storage_path' => 'ocr/ambiguous-relink-test.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 1,
                'status' => 'completed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $idA = (int) (DB::table('ocr_parsed_firms')->max('id') ?? 0) + 1;
        $idB = $idA + 1;
        $idSolo = $idA + 2;
        DB::table('ocr_parsed_firms')->insert([
            [
                'id' => $idA,
                'ocr_document_id' => $docId,
                'firm_name' => 'Ambiguous Relink Co',
                'city' => 'Pune',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $idB,
                'ocr_document_id' => $docId,
                'firm_name' => 'Ambiguous Relink Co',
                'city' => 'Mumbai',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $idSolo,
                'ocr_document_id' => $docId,
                'firm_name' => 'Unique Relink Firm',
                'city' => 'Surat',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $dir = storage_path('app/audits');
        $csv = $dir.'/test-relink-ambiguous-matches.csv';
        $out = $dir.'/test-relink-ambiguous-out.csv';
        $fh = fopen($csv, 'wb');
        fputcsv($fh, [
            'CA ID', 'Master Firm Name', 'Matched OCR Firm', 'OCR City', 'Confidence',
            'Normalized Key', 'OCR Firm ID', 'OCR Document ID', 'Match Count',
        ]);
        // Missing CSV id → normalize to ambiguous key with 2 OCR firms.
        fputcsv($fh, [
            930001, 'Ambiguous Relink Co', 'Ambiguous Relink Co', 'Pune', 'Exact',
            'AMBIGUOUS RELINK CO', 999999001, $docId, 1,
        ]);
        // Unique Exact via existing DB id.
        fputcsv($fh, [
            930002, 'Unique Relink Firm', 'Unique Relink Firm', 'Surat', 'Exact',
            'UNIQUE RELINK FIRM', $idSolo, $docId, 1,
        ]);
        fclose($fh);

        $this->artisan('ocr:relink-exact-offline-matches', [
            '--dry-run' => true,
            '--matches-csv' => $csv,
            '--export' => $out,
        ])->assertSuccessful();

        $rows = array_map('str_getcsv', file($out));
        $header = array_shift($rows);
        $statusIdx = array_search('Status', $header, true);
        $statuses = array_count_values(array_map(static fn ($r) => $r[$statusIdx] ?? '', $rows));
        $this->assertArrayHasKey('skipped_ambiguous_ocr', $statuses);
        $this->assertSame(1, $statuses['skipped_ambiguous_ocr']);
        $this->assertArrayHasKey('would_relink', $statuses);
        $this->assertSame(1, $statuses['would_relink']);

        DB::table('ocr_parsed_firms')->whereIn('id', [$idA, $idB, $idSolo])->delete();
    }
}
