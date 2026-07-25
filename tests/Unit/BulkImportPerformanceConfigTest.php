<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the bulk-import performance optimization against accidental regression
 * back to per-row processBatch calls.
 */
class BulkImportPerformanceConfigTest extends TestCase
{
    #[Test]
    public function crm_queue_exposes_batch_and_engine_config_keys(): void
    {
        $config = require base_path('config/crm_queue.php');

        $this->assertArrayHasKey('import_batch_rows', $config);
        $this->assertArrayHasKey('import_engine_batch_rows', $config);
        $this->assertArrayHasKey('import_queue_row_threshold', $config);
        $this->assertSame(800, (int) $config['import_batch_rows']);
        $this->assertSame(250, (int) $config['import_engine_batch_rows']);
        $this->assertSame(5000, (int) $config['import_queue_row_threshold']);
    }

    #[Test]
    public function bulk_import_service_buffers_engine_batches_and_logs_timings(): void
    {
        $source = file_get_contents(base_path('app/Services/Bulk/BulkCaMasterImportService.php'));

        $this->assertStringContainsString("config('crm_queue.import_engine_batch_rows'", $source);
        $this->assertStringContainsString('$engineBuffer', $source);
        $this->assertStringContainsString('prefetchLookupsFromEvaluation', $source);
        $this->assertStringContainsString("Warming lookups", $source);
        $this->assertStringContainsString("'defer_cache_bust' => true", $source);
        $this->assertStringContainsString("Log::info('bulk_import.timings'", $source);
        $this->assertStringContainsString('importRowsViaMappingEngineBatch', $source);
        $this->assertStringContainsString('setImportStage', $source);
    }

    #[Test]
    public function mapping_service_honours_deferred_cache_bust_flag(): void
    {
        $source = file_get_contents(base_path('app/Services/Mapping/MasterDataMappingService.php'));

        $this->assertStringContainsString("empty(\$meta['defer_cache_bust'])", $source);
    }

    #[Test]
    public function env_example_documents_import_batch_tuning_keys(): void
    {
        $env = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('CRM_IMPORT_BATCH_ROWS', $env);
        $this->assertStringContainsString('CRM_IMPORT_ENGINE_BATCH_ROWS', $env);
        $this->assertStringContainsString('CRM_IMPORT_QUEUE_ROW_THRESHOLD', $env);
        $this->assertStringContainsString('CRM_IMPORT_PROCESS_INLINE', $env);
    }
}
