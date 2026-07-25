<?php

namespace Tests\Feature\Ocr;

use App\Services\Ocr\OcrRepairMissingCitiesService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OcrMissingCitiesCommandsTest extends TestCase
{
    public function test_audit_command_is_read_only(): void
    {
        if (! Schema::hasTable('ca_masters')) {
            $this->markTestSkipped('ca_masters missing');
        }

        $before = DB::table('ca_masters')->where(function ($q) {
            $q->whereNull('city_id')->orWhere('city_id', 0);
        })->count();

        $this->artisan('ocr:audit-missing-cities', [
            '--limit' => 20,
            '--export' => storage_path('app/audits/test-missing-cities-audit.csv'),
        ])->assertSuccessful();

        $after = DB::table('ca_masters')->where(function ($q) {
            $q->whereNull('city_id')->orWhere('city_id', 0);
        })->count();

        $this->assertSame($before, $after);
    }

    public function test_repair_defaults_to_dry_run(): void
    {
        if (! Schema::hasTable('ca_masters')) {
            $this->markTestSkipped('ca_masters missing');
        }

        $beforeSum = (int) DB::table('ca_masters')->sum(DB::raw('COALESCE(city_id,0)'));

        $this->artisan('ocr:repair-missing-cities', [
            '--dry-run' => true,
            '--limit' => 20,
            '--export' => storage_path('app/audits/test-repair-missing-cities-dry.csv'),
        ])->assertSuccessful();

        $afterSum = (int) DB::table('ca_masters')->sum(DB::raw('COALESCE(city_id,0)'));
        $this->assertSame($beforeSum, $afterSum);
        $this->assertFileExists(storage_path('app/audits/test-repair-missing-cities-dry.csv'));
        $this->assertFileExists(storage_path('app/audits/test-repair-missing-cities-dry.audit.json'));
        $this->assertFileExists(storage_path('app/audits/test-repair-missing-cities-dry.rollback.json'));
    }

    public function test_repair_apply_sets_city_id_only_and_supports_rollback(): void
    {
        if (! Schema::hasTable('ca_masters')
            || ! Schema::hasTable('cities')
            || ! Schema::hasTable('ocr_parsed_firms')
            || ! Schema::hasColumn('ca_masters', 'source_ocr_row_id')) {
            $this->markTestSkipped('required schema missing');
        }

        $city = DB::table('cities')->orderBy('city_id')->first();
        if (! $city) {
            $this->markTestSkipped('no cities');
        }

        $docId = $this->ensureOcrDocument();
        $firmId = (int) (DB::table('ocr_parsed_firms')->max('id') ?? 0) + 1;
        DB::table('ocr_parsed_firms')->insert([
            'id' => $firmId,
            'ocr_document_id' => $docId,
            'firm_name' => 'Repair Missing City Test Firm',
            'city' => $city->city_name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert ahead of existing OCR-linked missing rows so --limit=1 only touches this fixture.
        $minMissing = (int) (DB::table('ca_masters')
            ->where(function ($q) {
                $q->whereNull('city_id')->orWhere('city_id', 0);
            })
            ->when(Schema::hasColumn('ca_masters', 'source_ocr_row_id'), function ($q) {
                $q->whereNotNull('source_ocr_row_id');
            })
            ->when(Schema::hasColumn('ca_masters', 'deleted_at'), function ($q) {
                $q->whereNull('deleted_at');
            })
            ->min('ca_id') ?? PHP_INT_MAX);
        $caId = max(1, $minMissing - 1);
        while ($caId >= 1 && DB::table('ca_masters')->where('ca_id', $caId)->exists()) {
            $caId--;
        }
        if ($caId < 1) {
            $this->markTestSkipped('unable to reserve a ca_id ahead of missing OCR-linked rows');
        }

        $insert = [
            'ca_id' => $caId,
            'firm_name' => 'Repair Missing City Test Firm',
            'ca_name' => 'Test CA',
            'city_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('ca_masters', 'source_ocr_row_id')) {
            $insert['source_ocr_row_id'] = $firmId;
        }
        if (Schema::hasColumn('ca_masters', 'source_ocr_document_id')) {
            $insert['source_ocr_document_id'] = $docId;
        }
        if (Schema::hasColumn('ca_masters', 'ocr_city_text')) {
            $insert['ocr_city_text'] = $city->city_name;
        }
        if (Schema::hasColumn('ca_masters', 'source_id')) {
            $insert['source_id'] = 1;
        }
        DB::table('ca_masters')->insert($insert);

        $export = storage_path('app/audits/test-repair-missing-cities-apply.csv');
        $this->artisan('ocr:repair-missing-cities', [
            '--apply' => true,
            '--limit' => 1,
            '--export' => $export,
        ])->expectsConfirmation(
            'Update ONLY city_id on OCR-linked Category A Masters with exactly one valid OCR→cities match? Firm/CA and other fields will not be touched.',
            'yes'
        )->assertSuccessful();

        $row = DB::table('ca_masters')->where('ca_id', $caId)->first();
        $this->assertSame((int) $city->city_id, (int) $row->city_id);
        $this->assertSame('Repair Missing City Test Firm', $row->firm_name);
        $this->assertSame($firmId, (int) $row->source_ocr_row_id);

        $rollback = preg_replace('/\.csv$/i', '.rollback.json', $export);
        $this->assertFileExists($rollback);

        $svc = app(OcrRepairMissingCitiesService::class);
        $rb = $svc->rollback($rollback, true, 50);
        $this->assertGreaterThanOrEqual(1, $rb['rolled_back']);

        $after = DB::table('ca_masters')->where('ca_id', $caId)->first();
        $this->assertTrue($after->city_id === null || (int) $after->city_id === 0);

        DB::table('ca_masters')->where('ca_id', $caId)->delete();
        DB::table('ocr_parsed_firms')->where('id', $firmId)->delete();
    }

    public function test_repair_never_overwrites_existing_city_id(): void
    {
        if (! Schema::hasTable('ca_masters') || ! Schema::hasTable('cities')) {
            $this->markTestSkipped('required tables missing');
        }

        $cities = DB::table('cities')->orderBy('city_id')->limit(2)->get();
        if ($cities->count() < 1) {
            $this->markTestSkipped('no cities');
        }
        $keepCityId = (int) $cities[0]->city_id;

        $caId = (int) (DB::table('ca_masters')->max('ca_id') ?? 0) + 940101;
        $insert = [
            'ca_id' => $caId,
            'firm_name' => 'Already Has City Firm',
            'ca_name' => 'Test CA',
            'city_id' => $keepCityId,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('ca_masters', 'source_ocr_row_id')) {
            $insert['source_ocr_row_id'] = 1;
        }
        DB::table('ca_masters')->insert($insert);

        $svc = app(OcrRepairMissingCitiesService::class);
        $svc->run([
            'apply' => true,
            'limit' => 50,
            'ocr_linked_only' => false,
            'export' => storage_path('app/audits/test-repair-never-overwrite.csv'),
        ]);

        $after = DB::table('ca_masters')->where('ca_id', $caId)->value('city_id');
        $this->assertSame($keepCityId, (int) $after);

        DB::table('ca_masters')->where('ca_id', $caId)->delete();
    }

    private function ensureOcrDocument(): int
    {
        $docId = (int) (DB::table('ocr_documents')->max('id') ?? 0);
        if ($docId > 0) {
            return $docId;
        }

        return (int) DB::table('ocr_documents')->insertGetId([
            'uploaded_by' => 1,
            'original_filename' => 'repair-missing-city-test.pdf',
            'stored_filename' => 'repair-missing-city-test.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr/repair-missing-city-test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
