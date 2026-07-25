<?php

namespace Tests\Feature\Ocr;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OcrRepairCategoryAMissingCitiesTest extends TestCase
{
    public function test_category_a_repair_defaults_to_dry_run_and_writes_no_city_id(): void
    {
        if (! Schema::hasTable('ca_masters') || ! Schema::hasTable('cities')) {
            $this->markTestSkipped('required tables missing');
        }

        $city = DB::table('cities')->orderBy('city_id')->first();
        if (! $city) {
            $this->markTestSkipped('no cities');
        }

        $caId = (int) (DB::table('ca_masters')->max('ca_id') ?? 0) + 910001;
        DB::table('ca_masters')->insert([
            'ca_id' => $caId,
            'firm_name' => 'CATEGORY A REPAIR TEST FIRM',
            'ca_name' => 'Test CA',
            'city_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $classification = storage_path('app/audits/test-category-a-classification.csv');
        @mkdir(dirname($classification), 0755, true);
        $fh = fopen($classification, 'wb');
        fputcsv($fh, [
            'CA ID', 'Firm Name', 'OCR City', 'Master City', 'Category', 'Decision', 'Parser Stage',
            'Raw OCR City', 'Heading City', 'Resolved City', 'OCR Locality', 'Evidence Sources',
            'Link Method', 'Local Firm ID', 'Local Doc ID', 'Prod source_ocr_row_id', 'Prod source_ocr_document_id',
            'Failure Reason',
        ]);
        fputcsv($fh, [
            $caId, 'CATEGORY A REPAIR TEST FIRM', $city->city_name, '', 'A', 'apply_exact_unique', 'staging_city_field',
            $city->city_name, '', $city->city_name, '', 'test',
            'test', '', '', '', '', 'test',
        ]);
        // Category B must never be applied even if present.
        fputcsv($fh, [
            $caId + 1, 'SHOULD NOT UPDATE', 'LOCALITYNAGAR', '', 'B', 'skip_locality_only', 'lost_at_locality_without_parent_city',
            'LOCALITYNAGAR', '', '', 'LOCALITYNAGAR', 'test',
            'test', '', '', '', '', 'test',
        ]);
        fclose($fh);

        $export = storage_path('app/audits/test-category-a-repair-dry.csv');
        $before = DB::table('ca_masters')->where('ca_id', $caId)->value('city_id');

        $this->artisan('ocr:repair-missing-cities-category-a', [
            '--dry-run' => true,
            '--classification' => $classification,
            '--export' => $export,
            '--baseline-missing' => 26492,
        ])->assertSuccessful();

        $after = DB::table('ca_masters')->where('ca_id', $caId)->value('city_id');
        $this->assertSame($before, $after);
        $this->assertFileExists($export);

        DB::table('ca_masters')->where('ca_id', $caId)->delete();
    }

    public function test_category_a_repair_apply_updates_only_missing_city_id(): void
    {
        if (! Schema::hasTable('ca_masters') || ! Schema::hasTable('cities')) {
            $this->markTestSkipped('required tables missing');
        }

        $city = DB::table('cities')->orderBy('city_id')->first();
        if (! $city) {
            $this->markTestSkipped('no cities');
        }

        $caId = (int) (DB::table('ca_masters')->max('ca_id') ?? 0) + 910002;
        DB::table('ca_masters')->insert([
            'ca_id' => $caId,
            'firm_name' => 'CATEGORY A APPLY TEST FIRM',
            'ca_name' => 'Test CA',
            'city_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $classification = storage_path('app/audits/test-category-a-classification-apply.csv');
        $fh = fopen($classification, 'wb');
        fputcsv($fh, [
            'CA ID', 'Firm Name', 'OCR City', 'Master City', 'Category', 'Decision', 'Parser Stage',
            'Raw OCR City', 'Heading City', 'Resolved City', 'OCR Locality', 'Evidence Sources',
            'Link Method', 'Local Firm ID', 'Local Doc ID', 'Prod source_ocr_row_id', 'Prod source_ocr_document_id',
            'Failure Reason',
        ]);
        fputcsv($fh, [
            $caId, 'CATEGORY A APPLY TEST FIRM', $city->city_name, '', 'A', 'apply_from_page_heading', 'page_heading_forward_fill',
            '', $city->city_name, $city->city_name, '', 'test',
            'test', '', '', '', '', 'test',
        ]);
        fclose($fh);

        $export = storage_path('app/audits/test-category-a-repair-apply.csv');

        $this->artisan('ocr:repair-missing-cities-category-a', [
            '--apply' => true,
            '--classification' => $classification,
            '--export' => $export,
        ])->expectsConfirmation(
            'Update ONLY city_id on Category A Masters from the classification CSV? B/C/D/E will not be touched.',
            'yes'
        )->assertSuccessful();

        $after = (int) DB::table('ca_masters')->where('ca_id', $caId)->value('city_id');
        $this->assertSame((int) $city->city_id, $after);

        DB::table('ca_masters')->where('ca_id', $caId)->delete();
    }

    public function test_ambiguous_mapping_aborts_without_writes(): void
    {
        if (! Schema::hasTable('ca_masters') || ! Schema::hasTable('cities')) {
            $this->markTestSkipped('required tables missing');
        }

        $citiesCsv = storage_path('app/audits/test-ambiguous-cities.csv');
        $fh = fopen($citiesCsv, 'wb');
        fputcsv($fh, ['city_id', 'city_name', 'state_id']);
        fputcsv($fh, [9001, 'TwinCity', 1]);
        fputcsv($fh, [9002, 'TwinCity', 2]);
        fclose($fh);

        $classification = storage_path('app/audits/test-category-a-ambiguous.csv');
        $fh = fopen($classification, 'wb');
        fputcsv($fh, [
            'CA ID', 'Firm Name', 'OCR City', 'Master City', 'Category', 'Decision', 'Parser Stage',
            'Raw OCR City', 'Heading City', 'Resolved City', 'OCR Locality', 'Evidence Sources',
            'Link Method', 'Local Firm ID', 'Local Doc ID', 'Prod source_ocr_row_id', 'Prod source_ocr_document_id',
            'Failure Reason',
        ]);
        fputcsv($fh, [
            1, 'AMBIGUOUS FIRM', 'TwinCity', '', 'A', 'apply_exact_unique', 'staging_city_field',
            'TwinCity', '', 'TwinCity', '', 'test',
            'test', '', '', '', '', 'test',
        ]);
        fclose($fh);

        $this->artisan('ocr:repair-missing-cities-category-a', [
            '--dry-run' => true,
            '--classification' => $classification,
            '--cities-csv' => $citiesCsv,
            '--export' => storage_path('app/audits/test-category-a-ambiguous-out.csv'),
        ])->assertFailed();
    }
}
