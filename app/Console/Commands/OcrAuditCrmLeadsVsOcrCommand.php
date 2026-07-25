<?php

namespace App\Console\Commands;

use App\Services\Ocr\OcrCrmLeadsVsOcrAuditService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only audit: CRM Master leads vs OCR parsed firms/members.
 */
class OcrAuditCrmLeadsVsOcrCommand extends Command
{
    protected $signature = 'ocr:audit-crm-leads-vs-ocr
        {--limit=0 : Limit CRM leads scanned (0 = all)}
        {--documents= : Optional comma-separated OCR document ids to scope OCR index}
        {--include-deleted : Include soft-deleted ca_masters}
        {--export= : CSV path (default storage/app/audits/crm-leads-vs-ocr-audit.csv)}';

    protected $description = 'Read-only audit comparing CRM Master leads to OCR parsed firms/members (no peel inference)';

    public function handle(OcrCrmLeadsVsOcrAuditService $audit): int
    {
        $this->info('CRM leads vs OCR audit (READ-ONLY). No inserts/updates/deletes.');

        $documentIds = null;
        if ($this->option('documents') !== null && $this->option('documents') !== '') {
            $documentIds = [];
            foreach (explode(',', (string) $this->option('documents')) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $documentIds[] = (int) $part;
                }
            }
        }

        try {
            $report = $audit->audit([
                'limit' => (int) ($this->option('limit') ?? 0),
                'include_deleted' => (bool) $this->option('include-deleted'),
                'document_ids' => $documentIds,
                'export' => $this->option('export') ?: storage_path('app/audits/crm-leads-vs-ocr-audit.csv'),
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $c = $report['counts'];
        $p = $report['percentages'];
        $total = (int) $report['total'];

        $this->newLine();
        $this->line('======================================');
        $this->line('CRM LEADS vs OCR AUDIT');
        $this->line('======================================');
        $this->line('Total CRM Leads: '.$total);
        $this->line('Exact Match: '.$c[OcrCrmLeadsVsOcrAuditService::EXACT_MATCH].' ('.$p[OcrCrmLeadsVsOcrAuditService::EXACT_MATCH].')');
        $this->line('Firm Match Only: '.$c[OcrCrmLeadsVsOcrAuditService::FIRM_MATCH_ONLY].' ('.$p[OcrCrmLeadsVsOcrAuditService::FIRM_MATCH_ONLY].')');
        $this->line('CA Different: '.$c[OcrCrmLeadsVsOcrAuditService::CA_DIFFERENT].' ('.$p[OcrCrmLeadsVsOcrAuditService::CA_DIFFERENT].')');
        $this->line('City Different: '.$c[OcrCrmLeadsVsOcrAuditService::CITY_DIFFERENT].' ('.$p[OcrCrmLeadsVsOcrAuditService::CITY_DIFFERENT].')');
        $this->line('OCR Member Missing: '.$c[OcrCrmLeadsVsOcrAuditService::OCR_MEMBER_MISSING].' ('.$p[OcrCrmLeadsVsOcrAuditService::OCR_MEMBER_MISSING].')');
        $this->line('Not Found In OCR: '.$c[OcrCrmLeadsVsOcrAuditService::NOT_FOUND_IN_OCR].' ('.$p[OcrCrmLeadsVsOcrAuditService::NOT_FOUND_IN_OCR].')');
        $this->line('======================================');
        $this->newLine();
        $this->info('CSV: '.$report['export_path']);
        $this->comment('OCR firm keys indexed: '.$report['ocr_firm_keys']);
        $this->comment('CA names are never inferred from firm titles (no proprietor peel).');

        $exact = (int) $c[OcrCrmLeadsVsOcrAuditService::EXACT_MATCH];
        $firmOnly = (int) $c[OcrCrmLeadsVsOcrAuditService::FIRM_MATCH_ONLY];
        $caDiff = (int) $c[OcrCrmLeadsVsOcrAuditService::CA_DIFFERENT];
        $absent = (int) $c[OcrCrmLeadsVsOcrAuditService::NOT_FOUND_IN_OCR]
            + (int) $c[OcrCrmLeadsVsOcrAuditService::OCR_MEMBER_MISSING];

        $this->newLine();
        $this->info('Conclusion');
        $this->line('- Fully supported by OCR (Exact Match): '.$exact);
        $this->line('- Firm match only: '.$firmOnly);
        $this->line('- CA mismatches (CA Different): '.$caDiff);
        $this->line('- City mismatches: '.$c[OcrCrmLeadsVsOcrAuditService::CITY_DIFFERENT]);
        $this->line('- Completely absent / no OCR member+CA evidence: '.$absent
            .' (Not Found '.$c[OcrCrmLeadsVsOcrAuditService::NOT_FOUND_IN_OCR]
            .' + Member Missing '.$c[OcrCrmLeadsVsOcrAuditService::OCR_MEMBER_MISSING].')');

        return self::SUCCESS;
    }
}
