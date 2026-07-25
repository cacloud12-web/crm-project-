<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\City;
use App\Models\MasterImportBatch;
use App\Models\SalesContact;
use App\Models\SalesHistory;
use App\Models\SalesImportRow;
use App\Models\SalesMappingReview;
use App\Models\SalesMasterLink;
use App\Services\Mapping\SalesEmployeeListImportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesMappingImportTest extends TestCase
{
    use DatabaseTransactions;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = storage_path('app/sales-mapping-test-'.uniqid());
        File::ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    private function skipUnlessReady(): void
    {
        if (! Schema::hasTable('sales_import_rows')
            || ! Schema::hasTable('ca_masters')
            || ! Schema::hasTable('sales_master_links')) {
            $this->markTestSkipped('Sales Mapping schema incomplete');
        }
    }

    /**
     * @param  list<list<string|null>>  $rows
     * @param  list<string>|null  $headers
     */
    private function writeCsv(string $name, array $rows, ?array $headers = null): string
    {
        $path = $this->tempDir.DIRECTORY_SEPARATOR.$name;
        $fh = fopen($path, 'w');
        fputcsv($fh, $headers ?? [
            'Date', 'CA NAME', 'Firm Name', 'Mobile No', 'Alternate Mobile No', 'City', 'Remarks 1', 'Remarks 2',
        ]);
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        fclose($fh);

        return $path;
    }

    private function cityPair(): array
    {
        $cityId = City::query()->value('city_id');
        $cityName = (string) (City::query()->where('city_id', $cityId)->value('city_name') ?: 'Jaipur');

        return [$cityId, $cityName];
    }

    public function test_perfect_match_creates_link_contact_history(): void
    {
        $this->skipUnlessReady();
        [$cityId, $cityName] = $this->cityPair();
        $suffix = uniqid();

        $master = CaMaster::query()->create([
            'ca_name' => 'PM CA '.$suffix,
            'firm_name' => 'PM Firm '.$suffix,
            'normalized_ca_name' => 'PM CA '.$suffix,
            'normalized_firm_name' => 'PM FIRM '.$suffix,
            'city_id' => $cityId,
            'mobile_no' => '9812345670',
            'status' => 'New',
            'rating' => 1,
        ]);
        $master->refresh();
        $before = [];
        foreach (['verification_status', 'is_verified', 'mobile_no', 'ca_name', 'firm_name'] as $col) {
            if (Schema::hasColumn('ca_masters', $col)) {
                $before[$col] = $master->getAttributes()[$col] ?? null;
            }
        }

        $path = $this->writeCsv('CA CloudDesk Leads - ANKIT.csv', [
            ['01-01-2026', 'PM CA '.$suffix, 'PM Firm '.$suffix, '9812345670', '', $cityName, 'called', 'ok'],
        ]);

        $result = app(SalesEmployeeListImportService::class)->importFile($path);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(1, $result['matched']);
        $this->assertSame(0, $result['needs_review']);
        $this->assertSame(0, $result['unmatched']);

        $row = SalesImportRow::query()->where('source_file_name', basename($path))->first();
        $this->assertNotNull($row);
        $this->assertSame('matched', $row->mapping_status);
        $this->assertSame((int) $master->ca_id, (int) $row->matched_ca_id);

        $this->assertTrue(SalesMasterLink::query()->where('sales_import_row_id', $row->id)->exists());
        $this->assertTrue(SalesContact::query()->where('sales_import_row_id', $row->id)->exists());
        $this->assertTrue(SalesHistory::query()->where('sales_import_row_id', $row->id)->exists());
        $this->assertFalse(SalesMappingReview::query()->where('sales_import_row_id', $row->id)->exists());

        $master->refresh();
        foreach ($before as $col => $value) {
            $this->assertSame($value, $master->getAttributes()[$col] ?? null, "ca_masters.{$col} must remain unchanged");
        }
    }

    public function test_multiple_candidates_create_pending_review(): void
    {
        $this->skipUnlessReady();
        [$cityId, $cityName] = $this->cityPair();
        $suffix = uniqid();

        CaMaster::query()->create([
            'ca_name' => 'Multi CA '.$suffix,
            'firm_name' => 'Multi Firm '.$suffix,
            'normalized_ca_name' => 'MULTI CA '.$suffix,
            'normalized_firm_name' => 'MULTI FIRM '.$suffix,
            'city_id' => $cityId,
            'status' => 'New',
            'rating' => 1,
        ]);
        CaMaster::query()->create([
            'ca_name' => 'Multi CA '.$suffix,
            'firm_name' => 'Multi Firm '.$suffix,
            'normalized_ca_name' => 'MULTI CA '.$suffix,
            'normalized_firm_name' => 'MULTI FIRM '.$suffix,
            'city_id' => $cityId,
            'status' => 'New',
            'rating' => 1,
        ]);

        $path = $this->writeCsv('CA CloudDesk Leads - SIMRAN.csv', [
            ['01-01-2026', 'Multi CA '.$suffix, 'Multi Firm '.$suffix, '9822222222', '', $cityName, 'r1', ''],
        ]);

        $result = app(SalesEmployeeListImportService::class)->importFile($path);
        $this->assertSame(1, $result['needs_review']);
        $this->assertSame(0, $result['matched']);

        $row = SalesImportRow::query()->where('source_file_name', basename($path))->first();
        $review = SalesMappingReview::query()->where('sales_import_row_id', $row->id)->first();
        $this->assertNotNull($review);
        $this->assertSame(SalesMappingReview::STATUS_PENDING, $review->status);
        $this->assertNull($review->approved_ca_id);
        $this->assertFalse(SalesMasterLink::query()->where('sales_import_row_id', $row->id)->exists());
        $this->assertSame(CaMaster::query()->count(), CaMaster::query()->count()); // no creates
    }

    public function test_no_match_creates_unmatched_review_without_master(): void
    {
        $this->skipUnlessReady();
        $beforeCa = CaMaster::query()->count();
        $path = $this->writeCsv('CA CloudDesk Leads - RAHUL.csv', [
            ['01-01-2026', 'Ghost CA '.uniqid(), 'Ghost Firm '.uniqid(), '9833333333', '', 'Atlantis', 'r1', ''],
        ]);

        $result = app(SalesEmployeeListImportService::class)->importFile($path);
        $this->assertSame(1, $result['unmatched']);
        $this->assertSame($beforeCa, CaMaster::query()->count());

        $row = SalesImportRow::query()->where('source_file_name', basename($path))->first();
        $review = SalesMappingReview::query()->where('sales_import_row_id', $row->id)->first();
        $this->assertNotNull($review);
        $this->assertSame('pending', $review->status);
        $this->assertSame('no_match', $review->reason);
    }

    public function test_duplicate_row_is_skipped_idempotently(): void
    {
        $this->skipUnlessReady();
        $path = $this->writeCsv('CA CloudDesk Leads - KARISHMA.csv', [
            ['01-01-2026', 'Dup CA', 'Dup Firm '.uniqid(), '9844444444', '', 'Jaipur', 'note', ''],
        ]);

        $service = app(SalesEmployeeListImportService::class);
        $first = $service->importFile($path);
        $this->assertSame(1, $first['imported']);

        $second = $service->importFile($path, null, true);
        $this->assertSame(0, $second['imported']);
        $this->assertGreaterThanOrEqual(1, $second['duplicate']);
        $this->assertSame(
            1,
            SalesImportRow::query()->where('source_file_name', basename($path))->count()
        );
    }

    public function test_same_master_from_different_employees_appends_separate_histories(): void
    {
        $this->skipUnlessReady();
        [$cityId, $cityName] = $this->cityPair();
        $suffix = uniqid();

        $master = CaMaster::query()->create([
            'ca_name' => 'Shared CA '.$suffix,
            'firm_name' => 'Shared Firm '.$suffix,
            'normalized_ca_name' => 'SHARED CA '.$suffix,
            'normalized_firm_name' => 'SHARED FIRM '.$suffix,
            'city_id' => $cityId,
            'mobile_no' => '9855555555',
            'status' => 'New',
            'rating' => 1,
        ]);

        $pathA = $this->writeCsv('CA CloudDesk Leads - ANKIT.csv', [
            ['01-01-2026', 'Shared CA '.$suffix, 'Shared Firm '.$suffix, '9855555555', '', $cityName, 'from-ankit', ''],
        ]);
        $pathB = $this->writeCsv('CA CloudDesk Leads - SIMRAN.csv', [
            ['02-01-2026', 'Shared CA '.$suffix, 'Shared Firm '.$suffix, '9855555555', '', $cityName, 'from-simran', ''],
        ]);

        $service = app(SalesEmployeeListImportService::class);
        $service->importFile($pathA);
        $service->importFile($pathB);

        $this->assertSame(2, SalesHistory::query()->where('ca_id', $master->ca_id)->count());
        $this->assertSame(2, SalesMasterLink::query()->where('ca_id', $master->ca_id)->count());
        $this->assertSame(
            ['from-ankit', 'from-simran'],
            SalesHistory::query()->where('ca_id', $master->ca_id)->orderBy('id')->pluck('remarks')->all()
        );
    }

    public function test_needs_verification_master_receives_history_but_is_not_auto_verified(): void
    {
        $this->skipUnlessReady();
        [$cityId, $cityName] = $this->cityPair();
        $suffix = uniqid();

        $attrs = [
            'ca_name' => 'NV CA '.$suffix,
            'firm_name' => 'NV Firm '.$suffix,
            'normalized_ca_name' => 'NV CA '.$suffix,
            'normalized_firm_name' => 'NV FIRM '.$suffix,
            'city_id' => $cityId,
            'mobile_no' => '9866666666',
            'status' => 'New',
            'rating' => 1,
        ];
        if (Schema::hasColumn('ca_masters', 'verification_status')) {
            $attrs['verification_status'] = 'needs_verification';
        }
        if (Schema::hasColumn('ca_masters', 'is_verified')) {
            $attrs['is_verified'] = false;
        }
        $master = CaMaster::query()->create($attrs);

        $path = $this->writeCsv('CA CloudDesk Leads - MONU.csv', [
            ['01-01-2026', 'NV CA '.$suffix, 'NV Firm '.$suffix, '9866666666', '', $cityName, 'nv-note', ''],
        ]);

        app(SalesEmployeeListImportService::class)->importFile($path);

        $master->refresh();
        $this->assertTrue(SalesHistory::query()->where('ca_id', $master->ca_id)->exists());
        if (Schema::hasColumn('ca_masters', 'verification_status')) {
            $this->assertSame('needs_verification', $master->verification_status);
        }
        if (Schema::hasColumn('ca_masters', 'is_verified')) {
            $this->assertFalse((bool) $master->is_verified);
        }
    }

    public function test_batch_counters_and_extra_columns(): void
    {
        $this->skipUnlessReady();
        [$cityId, $cityName] = $this->cityPair();
        $suffix = uniqid();

        CaMaster::query()->create([
            'ca_name' => 'Cnt CA '.$suffix,
            'firm_name' => 'Cnt Firm '.$suffix,
            'normalized_ca_name' => 'CNT CA '.$suffix,
            'normalized_firm_name' => 'CNT FIRM '.$suffix,
            'city_id' => $cityId,
            'mobile_no' => '9877777777',
            'status' => 'New',
            'rating' => 1,
        ]);

        $path = $this->writeCsv(
            'CA CloudDesk Leads - SHIVANI.csv',
            [
                ['01-01-2026', 'Cnt CA '.$suffix, 'Cnt Firm '.$suffix, '9877777777', '', $cityName, 'r1', '', 'CustomVal'],
                ['01-01-2026', 'NoMatch CA', 'NoMatch Firm '.uniqid(), '9888888888', '', 'Nowhere', 'r2', '', 'Other'],
            ],
            ['Date', 'CA NAME', 'Firm Name', 'Mobile No', 'Alternate Mobile No', 'City', 'Remarks 1', 'Remarks 2', 'Weird Column']
        );

        $result = app(SalesEmployeeListImportService::class)->importFile($path);
        $this->assertSame(2, $result['imported']);
        $this->assertSame(1, $result['matched']);
        $this->assertSame(1, $result['unmatched']);
        $this->assertGreaterThan(0, $result['processing_ms']);

        $batch = MasterImportBatch::query()->find($result['import_batch_id']);
        $this->assertNotNull($batch);
        $this->assertSame(2, (int) $batch->total_records);
        $this->assertSame(1, (int) $batch->matched_count);
        $this->assertSame(1, (int) $batch->unmatched_count);
        $this->assertNotNull($batch->processing_ms);

        $row = SalesImportRow::query()
            ->where('source_file_name', basename($path))
            ->where('mapping_status', 'matched')
            ->first();
        if (Schema::hasColumn('sales_import_rows', 'extra_columns')) {
            $this->assertIsArray($row->extra_columns);
            $this->assertArrayHasKey('Weird Column', $row->extra_columns);
        }
    }

    public function test_history_is_append_only_per_sales_row(): void
    {
        $this->skipUnlessReady();
        [$cityId, $cityName] = $this->cityPair();
        $suffix = uniqid();

        $master = CaMaster::query()->create([
            'ca_name' => 'Hist CA '.$suffix,
            'firm_name' => 'Hist Firm '.$suffix,
            'normalized_ca_name' => 'HIST CA '.$suffix,
            'normalized_firm_name' => 'HIST FIRM '.$suffix,
            'city_id' => $cityId,
            'mobile_no' => '9899999999',
            'status' => 'New',
            'rating' => 1,
        ]);

        $path = $this->writeCsv('CA CloudDesk Leads - ANKIT.csv', [
            ['01-01-2026', 'Hist CA '.$suffix, 'Hist Firm '.$suffix, '9899999999', '', $cityName, 'first', ''],
        ]);

        $service = app(SalesEmployeeListImportService::class);
        $service->importFile($path);
        $row = SalesImportRow::query()->where('source_file_name', basename($path))->first();
        $this->assertSame(1, SalesHistory::query()->where('sales_import_row_id', $row->id)->count());

        // Re-apply enrichment must not duplicate history for the same sales row.
        app(\App\Services\SalesMapping\SalesEnrichmentWriter::class)->applyForRow($row->fresh());
        $this->assertSame(1, SalesHistory::query()->where('sales_import_row_id', $row->id)->count());
        $this->assertSame(1, SalesHistory::query()->where('ca_id', $master->ca_id)->count());
    }
}
