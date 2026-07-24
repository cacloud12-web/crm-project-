<?php

namespace App\Console\Commands;

use App\Services\Ocr\OcrRepairRequiredMasterFieldsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Repair blank firm_name / ca_name / city_id on recovery-table Masters only.
 * Default: dry-run. Never overwrites non-empty values. Never flips verification.
 */
class OcrRepairRequiredMasterFieldsCommand extends Command
{
    protected $signature = 'ocr:repair-required-master-fields
        {--dry-run : Report only (default unless --apply)}
        {--apply : Persist safe field fills after confirmation}
        {--limit=0 : Stop after N recovery rows (0 = all)}
        {--ca-id= : Limit to one ca_id (must be in recovery table)}
        {--export= : CSV report path}
        {--chunk=500 : Apply/scan chunk size}';

    protected $description = 'Repair blank firm_name/ca_name/city_id on ca_masters_recovery_20260723 rows only (dry-run default)';

    public function handle(OcrRepairRequiredMasterFieldsService $service): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = ! $apply || (bool) $this->option('dry-run');
        if ((bool) $this->option('dry-run')) {
            $apply = false;
            $dryRun = true;
        }

        $export = $this->option('export');
        if ($export === null || $export === '') {
            $export = storage_path(
                'app/ocr-audits/repair-required-fields-'.now()->format('Ymd_His').'.csv'
            );
        }

        $this->info($dryRun
            ? 'OCR repair required Master fields — DRY-RUN (no writes)'
            : 'OCR repair required Master fields — APPLY');
        $this->comment('Scope: '.$service->recoveryTable().' only.');
        $this->comment('Never overwrites non-empty firm_name / ca_name / valid city_id.');
        $this->comment('Never changes verification_status / is_verified.');

        if ($apply) {
            if (! $this->confirm(
                'Apply safe repairs inside DB transactions? This updates only blank required fields.',
                false
            )) {
                $this->warn('Apply cancelled. No rows updated.');

                return self::SUCCESS;
            }
        }

        try {
            $report = $service->run([
                'dry_run' => $dryRun,
                'apply' => $apply && ! $dryRun,
                'limit' => (int) ($this->option('limit') ?? 0),
                'ca_id' => $this->option('ca-id') !== null && $this->option('ca-id') !== ''
                    ? (int) $this->option('ca-id')
                    : null,
                'chunk' => (int) ($this->option('chunk') ?? 500),
                'export' => (string) $export,
                'progress' => function (int $scanned, int $total): void {
                    $this->line(" … scanned={$scanned}/{$total}");
                },
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['dry_run', ($report['dry_run'] ?? true) ? 'yes' : 'no'],
                ['total scanned', $report['total_scanned'] ?? 0],
                ['firm names recoverable', $report['firm_names_recoverable'] ?? 0],
                ['CA names recoverable', $report['ca_names_recoverable'] ?? 0],
                ['cities recoverable', $report['cities_recoverable'] ?? 0],
                ['records becoming complete', $report['records_becoming_complete'] ?? 0],
                ['unresolved missing CA', $report['unresolved_missing_ca'] ?? 0],
                ['unresolved missing city', $report['unresolved_missing_city'] ?? 0],
                ['ambiguous CA candidates', $report['ambiguous_ca_candidates'] ?? 0],
                ['ambiguous city/locality candidates', $report['ambiguous_city_locality_candidates'] ?? 0],
                ['applied', $report['applied'] ?? 0],
            ]
        );

        if (! empty($report['export_path'])) {
            $this->info('CSV export: '.$report['export_path']);
        }

        if ($dryRun) {
            $this->comment('Dry-run complete. No Master writes.');
            $this->line('  php artisan ocr:repair-required-master-fields --apply --export="'.$export.'"');
        } else {
            $this->info('Apply complete. Applied '.$report['applied'].' update chunk row(s).');
        }

        return self::SUCCESS;
    }
}
