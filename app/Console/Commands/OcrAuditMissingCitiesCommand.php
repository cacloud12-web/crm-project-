<?php

namespace App\Console\Commands;

use App\Services\Ocr\OcrMissingCityAuditService;
use Illuminate\Console\Command;
use Throwable;

class OcrAuditMissingCitiesCommand extends Command
{
    protected $signature = 'ocr:audit-missing-cities
        {--limit=0 : Limit masters scanned (0 = all)}
        {--include-deleted : Include soft-deleted masters}
        {--ocr-linked-only : Only Masters with source_ocr_row_id (default true)}
        {--all-missing : Include Masters without OCR link (overrides --ocr-linked-only)}
        {--export= : CSV path (default storage/app/audits/missing-cities-pipeline-audit.csv)}';

    protected $description = 'Deep read-only audit of Master missing city_id through the full OCR pipeline';

    public function handle(OcrMissingCityAuditService $audit): int
    {
        $ocrLinkedOnly = ! (bool) $this->option('all-missing');
        if ($this->option('ocr-linked-only')) {
            $ocrLinkedOnly = true;
        }

        $this->info('Missing cities PIPELINE audit (READ-ONLY). No database modifications.');
        $this->comment($ocrLinkedOnly
            ? 'Scope: OCR-linked only (source_ocr_row_id IS NOT NULL)'
            : 'Scope: ALL missing city_id rows');

        try {
            $report = $audit->audit([
                'limit' => (int) ($this->option('limit') ?? 0),
                'include_deleted' => (bool) $this->option('include-deleted'),
                'ocr_linked_only' => $ocrLinkedOnly,
                'export' => $this->option('export')
                    ?: storage_path('app/audits/missing-cities-pipeline-audit.csv'),
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $t = $report['totals'];
        $this->newLine();
        $this->line('======================================');
        $this->line('MISSING CITIES — PIPELINE AUDIT');
        $this->line('======================================');
        $this->line('Scanned missing (scope): '.$t['missing_cities']);
        $this->line('OCR-linked in scan: '.($t['ocr_linked_missing'] ?? $t['missing_cities']));
        $this->line('Recoverable automatically (A): '.$t['recoverable_automatic']);
        $this->line('Requires manual review: '.$t['manual_review']);
        $this->line('Absolutely no city in stored OCR (E): '.$t['absolutely_no_city_in_ocr']);
        $this->line('Success % of scoped missing: '.($t['success_pct_of_ocr_linked'] ?? 0).'%');
        $this->line('--------------------------------------');
        $this->info('A–E classification (exclusive)');
        $classRows = [];
        foreach (['A', 'B', 'C', 'D', 'E'] as $cls) {
            $classRows[] = [$cls, $t['by_class'][$cls] ?? 0];
        }
        $this->table(['Class', 'Count'], $classRows);

        $this->info('Failure stages');
        $stageRows = [];
        foreach (($t['by_failure_stage'] ?? []) as $stage => $count) {
            $stageRows[] = [$stage, $count];
        }
        usort($stageRows, static fn ($a, $b) => $b[1] <=> $a[1]);
        $this->table(['Parser / pipeline stage', 'Count'], $stageRows);

        $this->info('Decisions');
        $decRows = [];
        foreach (($t['by_decision'] ?? []) as $dec => $count) {
            $decRows[] = [$dec, $count];
        }
        usort($decRows, static fn ($a, $b) => $b[1] <=> $a[1]);
        $this->table(['Decision', 'Count'], $decRows);

        $this->line('======================================');
        $this->info('CSV: '.$report['export_path']);
        $this->comment('cities indexed: '.$report['cities_indexed'].'; aliases: '.$report['aliases_configured']);

        return self::SUCCESS;
    }
}
