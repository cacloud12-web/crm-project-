<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\MasterImportBatch;
use App\Models\SalesImportRow;
use App\Models\User;
use App\Services\Mapping\SalesEmployeeListImportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CrmTestAccounts;
use Tests\TestCase;

class SalesEmployeeMultiFileImportTest extends TestCase
{
    use DatabaseTransactions;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        app(\App\Services\Rbac\RbacDatabaseService::class)->ensureConfigDefaultGrants();
        app(\App\Services\Rbac\RbacMatrixService::class)->flushCache();
        $this->tempDir = storage_path('app/sales-imports-test-'.uniqid());
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
        if (! Schema::hasTable('sales_import_rows') || ! Schema::hasTable('ca_masters')) {
            $this->markTestSkipped('sales_import_rows or ca_masters missing');
        }
    }

    private function actingAsAdmin(): User
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        return $admin;
    }

    private function writeCsv(string $name, array $rows): string
    {
        $path = $this->tempDir.DIRECTORY_SEPARATOR.$name;
        $fh = fopen($path, 'w');
        fputcsv($fh, ['Date', 'CA NAME', 'Firm Name', 'Mobile No', 'Alternate Mobile No', 'City', 'Remarks 1', 'Remarks 2']);
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        fclose($fh);

        return $path;
    }

    public function test_discovers_csv_and_skips_unsupported_files(): void
    {
        $this->skipUnlessReady();
        $this->writeCsv('CA CloudDesk Leads - ANKIT.csv', [['01-01-2026', 'CA A', 'Firm A', '9876543210', '', 'Jaipur', 'r1', 'r2']]);
        File::put($this->tempDir.'/notes.txt', 'not csv enough');
        File::put($this->tempDir.'/CA CloudDesk Leads - Monu.numbers', 'binary');
        File::put($this->tempDir.'/.hidden.csv', "Date,Firm Name,Mobile No,City\n");
        File::put($this->tempDir.'/backup.csv.bak', "Date,Firm Name,Mobile No,City\n");

        $service = app(SalesEmployeeListImportService::class);
        $files = $service->discoverFiles($this->tempDir);

        $this->assertCount(1, $files);
        $this->assertSame('CA CloudDesk Leads - ANKIT.csv', basename($files[0]));
        $this->assertTrue($service->shouldSkipFilename('CA CloudDesk Leads - Monu.numbers'));
        $this->assertTrue($service->shouldSkipFilename('.hidden.csv'));
    }

    public function test_resolves_employee_from_filename_and_skips_unknown(): void
    {
        $this->skipUnlessReady();
        $service = app(SalesEmployeeListImportService::class);

        $this->assertSame('ANKIT', $service->resolveEmployeeName('CA CloudDesk Leads - ANKIT.csv'));
        $this->assertSame('SIMRAN', $service->resolveEmployeeName('SIMRAN.csv'));
        $this->assertSame('RAHUL', $service->resolveEmployeeName('RAHUL SALES LIST.csv'));
        $this->assertNull($service->resolveEmployeeName('random dump export 2024.csv'));
    }

    public function test_importing_two_files_creates_separate_batches_without_ca_writes(): void
    {
        $this->skipUnlessReady();
        $this->actingAsAdmin();
        $beforeCa = CaMaster::query()->count();

        $pathA = $this->writeCsv('CA CloudDesk Leads - ANKIT.csv', [
            ['01-01-2026', 'CA A', 'Unique Firm A '.uniqid(), '9111111111', '', 'Jaipur', 'keep-a', ''],
        ]);
        $pathB = $this->writeCsv('CA CloudDesk Leads - SIMRAN.csv', [
            ['02-01-2026', 'CA B', 'Unique Firm B '.uniqid(), '9222222222', '', 'Delhi', 'keep-b', ''],
        ]);

        $service = app(SalesEmployeeListImportService::class);
        $resultA = $service->importFile($pathA);
        $resultB = $service->importFile($pathB);

        $this->assertSame('completed', $resultA['status']);
        $this->assertSame('completed', $resultB['status']);
        $this->assertNotNull($resultA['import_batch_id']);
        $this->assertNotNull($resultB['import_batch_id']);
        $this->assertNotSame($resultA['import_batch_id'], $resultB['import_batch_id']);
        $this->assertSame(1, $resultA['imported']);
        $this->assertSame(1, $resultB['imported']);
        $this->assertSame($beforeCa, CaMaster::query()->count());

        if (Schema::hasTable('master_import_batches')) {
            $this->assertSame(
                SalesEmployeeListImportService::SOURCE_TYPE,
                MasterImportBatch::query()->find($resultA['import_batch_id'])?->source_type
            );
        }
    }

    public function test_rerun_same_file_creates_no_duplicate_rows_and_keeps_manual_match(): void
    {
        $this->skipUnlessReady();
        $path = $this->writeCsv('CA CloudDesk Leads - KARISHMA.csv', [
            ['03-01-2026', 'CA K', 'Firm Keep '.uniqid(), '9333333333', '', 'Jaipur', 'history', 'note'],
        ]);

        $service = app(SalesEmployeeListImportService::class);
        $first = $service->importFile($path);
        $this->assertSame(1, $first['imported']);

        $row = SalesImportRow::query()->where('source_file_name', basename($path))->first();
        $this->assertNotNull($row);
        $row->fill([
            'mapping_status' => 'matched',
            'matched_on' => 'manual_confirm',
            'review_reason' => 'Manual decision must remain',
            'matched_ca_id' => null,
        ])->save();
        $remarks = $row->remarks_1;
        $countBefore = SalesImportRow::query()->where('source_file_name', basename($path))->count();

        $second = $service->importFile($path, null, true);
        $this->assertSame(0, $second['imported']);
        $this->assertGreaterThanOrEqual(1, $second['already_existing']);
        $this->assertSame(
            $countBefore,
            SalesImportRow::query()->where('source_file_name', basename($path))->count()
        );

        $row->refresh();
        $this->assertSame('matched', $row->mapping_status);
        $this->assertSame('manual_confirm', $row->matched_on);
        $this->assertSame('Manual decision must remain', $row->review_reason);
        $this->assertSame($remarks, $row->remarks_1);
    }

    public function test_one_file_failure_does_not_roll_back_other_file(): void
    {
        $this->skipUnlessReady();
        $good = $this->writeCsv('CA CloudDesk Leads - MONU.csv', [
            ['04-01-2026', 'CA M', 'Firm M '.uniqid(), '9444444444', '', 'Pune', 'ok', ''],
        ]);
        $bad = $this->tempDir.'/CA CloudDesk Leads - BAD.csv';
        File::put($bad, "Totally,Wrong,Headers\n1,2,3\n");

        $service = app(SalesEmployeeListImportService::class);
        $ok = $service->importFile($good);
        $fail = $service->importFile($bad);

        $this->assertSame('completed', $ok['status']);
        $this->assertSame(1, $ok['imported']);
        $this->assertSame('failed', $fail['status']);
        $this->assertSame(
            1,
            SalesImportRow::query()->where('source_file_name', basename($good))->count()
        );
        $this->assertSame(
            0,
            SalesImportRow::query()->where('source_file_name', basename($bad))->count()
        );
    }

    public function test_file_list_api_search_pagination_and_selected_file_scope(): void
    {
        $this->skipUnlessReady();
        $this->actingAsAdmin();

        $pathA = $this->writeCsv('CA CloudDesk Leads - SHIVANI.csv', [
            ['05-01-2026', 'CA S', 'Firm S '.uniqid(), '9555555551', '', 'Jaipur', 'a', ''],
            ['06-01-2026', 'CA S2', 'Firm S2 '.uniqid(), '9555555552', '', 'Jaipur', 'b', ''],
        ]);
        $pathB = $this->writeCsv('CA CloudDesk Leads - SONIYA.csv', [
            ['07-01-2026', 'CA Y', 'Firm Y '.uniqid(), '9666666666', '', 'Delhi', 'c', ''],
        ]);
        $service = app(SalesEmployeeListImportService::class);
        $a = $service->importFile($pathA);
        $b = $service->importFile($pathB);

        $files = $this->getJson('/employee-imports/files?search=SHIVANI&per_page=10');
        $files->assertOk();
        $items = $files->json('data.data') ?? [];
        $this->assertNotEmpty($items);
        $this->assertTrue(collect($items)->every(fn ($f) => str_contains((string) ($f['source_file_name'] ?? ''), 'SHIVANI')
            || str_contains((string) ($f['employee_name'] ?? ''), 'SHIVANI')));

        $summary = $this->getJson('/employee-imports/summary?import_batch_id='.$a['import_batch_id']);
        $summary->assertOk();
        $this->assertSame(2, (int) $summary->json('data.total'));

        $data = $this->getJson('/employee-imports/data?import_batch_id='.$a['import_batch_id'].'&per_page=25');
        $data->assertOk();
        $rows = $data->json('data.data') ?? [];
        $this->assertCount(2, $rows);
        $this->assertTrue(collect($rows)->every(fn ($r) => (int) ($r['import_batch_id'] ?? 0) === (int) $a['import_batch_id']));

        $other = $this->getJson('/employee-imports/data?import_batch_id='.$b['import_batch_id'].'&per_page=25');
        $this->assertCount(1, $other->json('data.data') ?? []);
    }

    public function test_accept_all_matched_only_affects_selected_batch(): void
    {
        $this->skipUnlessReady();
        $admin = $this->actingAsAdmin();

        $ca = CaMaster::query()->create([
            'firm_name' => 'Accept Scope Firm '.uniqid(),
            'ca_name' => 'Accept CA',
            'status' => 'New',
            'rating' => 1,
        ]);

        $batchA = SalesImportRow::query()->create([
            'import_batch_id' => 91001,
            'source_file_name' => 'file-a.csv',
            'source_row_number' => 2,
            'employee_name' => 'A',
            'firm_name' => $ca->firm_name,
            'ca_name' => $ca->ca_name,
            'city_name' => 'Jaipur',
            'mobile_no' => '9000000001',
            'mapping_status' => 'matched',
            'matched_ca_id' => $ca->ca_id,
            'matched_on' => 'exact_normalized_firm_city',
            'remarks_1' => 'keep-a',
        ]);
        $batchB = SalesImportRow::query()->create([
            'import_batch_id' => 91002,
            'source_file_name' => 'file-b.csv',
            'source_row_number' => 2,
            'employee_name' => 'B',
            'firm_name' => $ca->firm_name,
            'ca_name' => $ca->ca_name,
            'city_name' => 'Jaipur',
            'mobile_no' => '9000000002',
            'mapping_status' => 'matched',
            'matched_ca_id' => $ca->ca_id,
            'matched_on' => 'exact_normalized_firm_city',
            'remarks_1' => 'keep-b',
        ]);

        $response = $this->postJson('/employee-imports/accept-all-matched', [
            'import_batch_id' => 91001,
        ]);
        $response->assertOk();
        $this->assertSame(1, (int) $response->json('data.accepted'));

        $batchA->refresh();
        $batchB->refresh();
        $this->assertSame('accepted_matched', $batchA->matched_on);
        $this->assertSame('exact_normalized_firm_city', $batchB->matched_on);
        $this->assertSame('keep-a', $batchA->remarks_1);
        $this->assertSame('keep-b', $batchB->remarks_1);
        unset($admin);
    }

    public function test_unauthorized_cannot_accept_all_matched(): void
    {
        $this->skipUnlessReady();
        $this->postJson('/employee-imports/accept-all-matched', ['import_batch_id' => 1])
            ->assertUnauthorized();
    }

    public function test_import_all_command_discovers_directory(): void
    {
        $this->skipUnlessReady();
        $this->writeCsv('CA CloudDesk Leads - SUHANSHI.csv', [
            ['08-01-2026', 'CA U', 'Firm U '.uniqid(), '9777777777', '', 'Surat', 'x', ''],
        ]);

        $exit = Artisan::call('sales-list:import-all', [
            '--dir' => $this->tempDir,
        ]);
        $this->assertContains($exit, [0, 1]);
        $this->assertSame(
            1,
            SalesImportRow::query()->where('source_file_name', 'CA CloudDesk Leads - SUHANSHI.csv')->count()
        );
    }
}
