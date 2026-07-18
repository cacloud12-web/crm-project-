<?php

namespace Tests\Feature;

use App\Models\CaAddress;
use App\Models\CaFirm;
use App\Models\CaPartner;
use App\Models\CaReferenceImportBatch;
use App\Services\Bulk\BulkCaReferenceImportService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BulkCaReferenceImportTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureCaReferenceImportSchema();
        $this->tempDir = sys_get_temp_dir().'/ca_ref_import_'.uniqid('', true);
        mkdir($this->tempDir, 0777, true);
        $this->resetReferenceData();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_one_valid_row_creates_firm_partner_and_city(): void
    {
        $path = $this->writeCsv([
            ['Firm Name', 'CA Name', 'City'],
            ['Alpha & Co', 'Ravi Kumar', 'Delhi'],
        ]);

        $result = app(BulkCaReferenceImportService::class)->import($path, false, 1000);

        $this->assertSame(1, $result['reconciliation']['source_rows']);
        $this->assertSame(1, $result['reconciliation']['imported_firms']);
        $this->assertSame(1, $result['reconciliation']['imported_ca_names']);
        $this->assertSame(1, $result['reconciliation']['imported_cities']);
        $this->assertSame(1, CaFirm::query()->count());
        $this->assertSame(1, CaPartner::query()->count());
        $this->assertSame(1, CaAddress::query()->count());

        $firm = CaFirm::query()->first();
        $this->assertSame('Alpha & Co', $firm->firm_name);
        $this->assertNotNull($firm->normalized_firm_name);
        $this->assertSame('Ravi Kumar', CaPartner::query()->first()->partner_name);
        $this->assertSame('Delhi', CaAddress::query()->first()->city);
        $this->assertSame($firm->id, CaPartner::query()->first()->firm_id);
        $this->assertSame($firm->id, CaAddress::query()->first()->firm_id);
    }

    public function test_duplicate_firm_reuses_existing_record(): void
    {
        $path = $this->writeCsv([
            ['Firm Name', 'CA Name', 'City'],
            ['Beta Associates', 'Anita Shah', 'Mumbai'],
            ['Beta Associates', 'Anita Shah', 'Mumbai'],
        ]);

        $result = app(BulkCaReferenceImportService::class)->import($path, false, 1000);

        $this->assertSame(1, CaFirm::query()->count());
        $this->assertSame(1, CaPartner::query()->count());
        $this->assertSame(1, CaAddress::query()->count());
        $this->assertSame(1, $result['reconciliation']['imported_firms']);
        $this->assertGreaterThanOrEqual(1, $result['reconciliation']['duplicates']);
        $this->assertSame(1, $result['reconciliation']['reused_firms']);
    }

    public function test_multiple_ca_names_for_one_firm(): void
    {
        $path = $this->writeCsv([
            ['Firm Name', 'CA Name', 'City'],
            ['Gamma LLP', 'Partner One', 'Pune'],
            ['Gamma LLP', 'Partner Two', 'Pune'],
        ]);

        $result = app(BulkCaReferenceImportService::class)->import($path, false, 1000);

        $this->assertSame(1, CaFirm::query()->count());
        $this->assertSame(2, CaPartner::query()->count());
        $this->assertSame(1, CaAddress::query()->count());
        $this->assertSame(1, $result['reconciliation']['imported_firms']);
        $this->assertSame(2, $result['reconciliation']['imported_ca_names']);
        $this->assertSame(1, $result['reconciliation']['imported_cities']);
        $this->assertSame(2, (int) CaFirm::query()->first()->partner_count);
    }

    public function test_missing_city_imports_firm_and_ca_without_address(): void
    {
        $path = $this->writeCsv([
            ['Firm Name', 'CA Name', 'City'],
            ['Delta & Co', 'Neha Verma', ''],
        ]);

        $result = app(BulkCaReferenceImportService::class)->import($path, false, 1000);

        $this->assertSame(1, CaFirm::query()->count());
        $this->assertSame(1, CaPartner::query()->count());
        $this->assertSame(0, CaAddress::query()->count());
        $this->assertSame(0, $result['reconciliation']['imported_cities']);
        $this->assertSame(1, $result['reconciliation']['success_rows']);
        $this->assertSame('imported_without_city', $result['rows'][0]['failure_reason']);
    }

    public function test_repeated_import_is_idempotent(): void
    {
        $path = $this->writeCsv([
            ['Firm Name', 'CA Name', 'City'],
            ['Epsilon & Co', 'Karan Mehta', 'Jaipur'],
        ]);

        $service = app(BulkCaReferenceImportService::class);
        $first = $service->import($path, false, 1000);
        $second = $service->import($path, false, 1000);

        $this->assertSame(1, CaFirm::query()->count());
        $this->assertSame(1, CaPartner::query()->count());
        $this->assertSame(1, CaAddress::query()->count());
        $this->assertSame(1, $first['reconciliation']['imported_firms']);
        $this->assertSame(0, $second['reconciliation']['imported_firms']);
        $this->assertSame(0, $second['reconciliation']['imported_ca_names']);
        $this->assertSame(0, $second['reconciliation']['imported_cities']);
        $this->assertSame(1, $second['reconciliation']['duplicates']);
        $this->assertSame(\App\Models\CaReferenceImportRow::STATUS_DUPLICATE, $second['rows'][0]['status']);
    }

    public function test_chunk_import_of_1000_rows(): void
    {
        $rows = [['Firm Name', 'CA Name', 'City']];
        for ($i = 1; $i <= 1000; $i++) {
            $rows[] = [
                'Firm '.str_pad((string) $i, 4, '0', STR_PAD_LEFT).' & Co',
                'CA Person '.$i,
                'City '.(($i % 50) + 1),
            ];
        }
        $path = $this->writeCsv($rows);

        $result = app(BulkCaReferenceImportService::class)->import($path, false, 250);

        $this->assertSame(1000, $result['reconciliation']['source_rows']);
        $this->assertSame(1000, CaFirm::query()->count());
        $this->assertSame(1000, CaPartner::query()->count());
        $this->assertSame(1000, CaAddress::query()->count());
        $this->assertSame(1000, $result['reconciliation']['imported_firms']);
        $this->assertNotNull($result['batch_id']);
        $this->assertTrue(CaReferenceImportBatch::query()->whereKey($result['batch_id'])->exists());
    }

    public function test_dry_run_does_not_write_reference_rows(): void
    {
        $path = $this->writeCsv([
            ['Firm Name', 'CA Name', 'City'],
            ['Zeta & Co', 'Dry Run CA', 'Chennai'],
        ]);

        $result = app(BulkCaReferenceImportService::class)->import($path, true, 1000);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(0, CaFirm::query()->count());
        $this->assertSame(0, CaPartner::query()->count());
        $this->assertSame(0, CaAddress::query()->count());
        $this->assertSame(1, $result['reconciliation']['imported_firms']);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function writeCsv(array $rows): string
    {
        $path = $this->tempDir.'/import_'.uniqid('', true).'.csv';
        $handle = fopen($path, 'w');
        $this->assertNotFalse($handle);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $path;
    }

    private function resetReferenceData(): void
    {
        if (Schema::connection('ca_reference')->hasTable('ca_reference_import_rows')) {
            Schema::connection('ca_reference')->disableForeignKeyConstraints();
            \App\Models\CaReferenceImportRow::query()->delete();
            CaReferenceImportBatch::query()->delete();
            CaAddress::query()->delete();
            CaPartner::query()->delete();
            CaFirm::query()->delete();
            Schema::connection('ca_reference')->enableForeignKeyConstraints();
        }
    }

    private function ensureCaReferenceImportSchema(): void
    {
        try {
            $migrateOpts = [
                '--database' => 'ca_reference',
                '--path' => config('ca_reference.migrations_path', 'database/migrations/ca_reference'),
                '--force' => true,
            ];
            if (! Schema::connection('ca_reference')->hasTable('ca_firms')) {
                $this->artisan('migrate', $migrateOpts);
            } elseif (! Schema::connection('ca_reference')->hasColumn('ca_firms', 'normalized_firm_name')
                || ! Schema::connection('ca_reference')->hasTable('ca_reference_import_batches')) {
                $this->artisan('migrate', $migrateOpts);
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('ca_reference unavailable: '.$e->getMessage());
        }

        if (! Schema::connection('ca_reference')->hasTable('ca_firms')
            || ! Schema::connection('ca_reference')->hasColumn('ca_firms', 'normalized_firm_name')) {
            $this->markTestSkipped('ca_reference import schema not migrated');
        }
    }
}
