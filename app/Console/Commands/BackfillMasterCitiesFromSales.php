<?php

namespace App\Console\Commands;

use App\Models\SalesImportRow;
use App\Services\SalesMapping\SalesEnrichmentWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Re-apply sales enrichment so Master Data picks up City (and empty email/remarks)
 * from already-imported matched sales CSV rows.
 */
class BackfillMasterCitiesFromSales extends Command
{
    protected $signature = 'sales:backfill-master-cities
                            {--batch= : Limit to one import_batch_id}
                            {--dry-run : Count eligible rows without writing}
                            {--chunk=500 : Chunk size}';

    protected $description = 'Backfill ca_masters.city_id / ocr_city_text from matched sales_import_rows.city_name';

    public function handle(SalesEnrichmentWriter $writer): int
    {
        if (! Schema::hasTable('sales_import_rows') || ! Schema::hasTable('ca_masters')) {
            $this->error('Required tables missing.');

            return self::FAILURE;
        }

        $batchId = $this->option('batch') !== null ? (int) $this->option('batch') : null;
        $chunk = max(50, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $query = SalesImportRow::query()
            ->where('mapping_status', 'matched')
            ->whereNotNull('matched_ca_id')
            ->whereNotNull('city_name')
            ->where('city_name', '!=', '')
            ->orderBy('id');

        if ($batchId) {
            $query->where('import_batch_id', $batchId);
        }

        $eligible = (clone $query)->count();
        $this->info("Eligible matched sales rows with city: {$eligible}");

        if ($dryRun || $eligible === 0) {
            return self::SUCCESS;
        }

        $applied = 0;
        $query->chunkById($chunk, function ($rows) use ($writer, &$applied) {
            foreach ($rows as $row) {
                $writer->applyForRow($row);
                $applied++;
            }
            $this->line("Processed {$applied}...");
        });

        $this->info("Done. Re-applied enrichment for {$applied} rows.");

        return self::SUCCESS;
    }
}
