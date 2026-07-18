<?php

namespace Tests\Feature;

use App\Services\Bulk\BulkCaReferenceImportService;
use App\Services\CaReference\CaReferenceTableClassifier;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CaReferenceMigrateGuardTest extends TestCase
{
    public function test_migrate_without_path_against_ca_reference_is_aborted(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Refusing to run default migrations against ca_reference');

        Artisan::call('migrate', [
            '--database' => 'ca_reference',
            '--force' => true,
        ]);
    }

    public function test_migrate_fresh_against_ca_reference_is_aborted(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Refusing migrate:fresh on ca_reference');

        Artisan::call('migrate:fresh', [
            '--database' => 'ca_reference',
            '--force' => true,
        ]);
    }

    public function test_migrate_with_dedicated_path_is_allowed(): void
    {
        $path = config('ca_reference.migrations_path', 'database/migrations/ca_reference');

        $code = Artisan::call('migrate', [
            '--database' => 'ca_reference',
            '--path' => $path,
            '--force' => true,
        ]);

        $this->assertSame(0, $code);
        $this->assertTrue(Schema::connection('ca_reference')->hasTable('ca_firms'));
        $this->assertTrue(Schema::connection('ca_reference')->hasTable('ca_partners'));
        $this->assertTrue(Schema::connection('ca_reference')->hasTable('ca_addresses'));
    }

    public function test_importer_only_requires_core_reference_tables(): void
    {
        $source = file_get_contents(app_path('Services/Bulk/BulkCaReferenceImportService.php'));
        $this->assertIsString($source);
        $this->assertStringContainsString("'ca_firms'", $source);
        $this->assertStringContainsString("'ca_partners'", $source);
        $this->assertStringContainsString("'ca_addresses'", $source);
        $this->assertStringNotContainsString("'ca_masters'", $source);
        $this->assertStringNotContainsString("'users'", $source);

        $service = app(BulkCaReferenceImportService::class);
        $ref = new \ReflectionClass($service);
        $method = $ref->getMethod('assertReferenceSchema');
        $method->setAccessible(true);
        $method->invoke($service);
    }

    public function test_classifier_keep_allowlist_matches_config(): void
    {
        $classifier = app(CaReferenceTableClassifier::class);
        $keep = $classifier->keepTables();

        foreach (['ca_firms', 'ca_partners', 'ca_addresses', 'migrations', 'mapping_logs', 'ocr_import_logs', 'ocr_processing_logs'] as $table) {
            $this->assertContains($table, $keep);
            $this->assertTrue($classifier->isKeepTable($table));
        }

        $this->assertFalse($classifier->isKeepTable('users'));
        $this->assertFalse($classifier->isKeepTable('ca_masters'));
        $this->assertFalse($classifier->isKeepMigration('0001_01_01_000000_create_users_table'));
        $this->assertTrue($classifier->isKeepMigration('2026_07_16_150000_create_ca_firms_table'));
    }
}
