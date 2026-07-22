<?php

namespace App\Console\Commands;

use App\Services\Mapping\SalesImportRemapService;
use Illuminate\Console\Command;
use Throwable;

class RemapSalesEmployeeList extends Command
{
    protected $signature = 'sales-list:remap
                            {--all : Remap eligible rows from every imported file}
                            {--file= : Remap one source_file_name}
                            {--batch= : Remap one import_batch_id}
                            {--employee= : Remap one employee_name}
                            {--status= : Limit to a mapping_status}
                            {--dry-run : Calculate results without updating rows}
                            {--chunk=500 : Rows per chunk}
                            {--include-auto-matched : Also remap automatic matched rows}
                            {--include-manual-unmatched : Also remap mark_unmatched rows (never manual_confirmed)}';

    protected $description = 'Remap existing employee sales-import rows against CA Reference (no re-import, no CA writes)';

    public function __construct(
        private readonly SalesImportRemapService $remap,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('CA Reference preflight + Auto Match (exact normalized firm + city).');
        $this->info('No CSV re-import. No CA Master / CA Reference create/update/delete.');
        $this->info('Manual Confirm / Accept / Ignore stay protected.');

        $scopeBits = [];
        if ($this->option('all')) {
            $scopeBits[] = 'all files';
        }
        if ($this->option('file')) {
            $scopeBits[] = 'file='.$this->option('file');
        }
        if ($this->option('batch')) {
            $scopeBits[] = 'batch='.$this->option('batch');
        }
        if ($this->option('employee')) {
            $scopeBits[] = 'employee='.$this->option('employee');
        }
        if ($this->option('status')) {
            $scopeBits[] = 'status='.$this->option('status');
        }
        $this->line('Scope: '.(implode(', ', $scopeBits) ?: '(invalid — need --all/--file/--batch/--employee)'));

        try {
            $result = $this->remap->run([
                'all' => (bool) $this->option('all'),
                'file' => $this->option('file'),
                'batch' => $this->option('batch'),
                'employee' => $this->option('employee'),
                'status' => $this->option('status'),
                'dry_run' => (bool) $this->option('dry-run'),
                'chunk' => (int) $this->option('chunk'),
                'include_auto_matched' => (bool) $this->option('include-auto-matched'),
                'include_manual_unmatched' => (bool) $this->option('include-manual-unmatched'),
                'progress' => function (int $processed, int $total) {
                    if ($total <= 0) {
                        return;
                    }
                    if ($processed === 1 || $processed % 500 === 0 || $processed >= $total) {
                        $this->output->write("\rProcessed {$processed} / {$total}");
                    }
                },
            ]);
        } catch (Throwable $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine(2);

        $preflight = $result['preflight'] ?? [];
        $this->table(
            ['Check', 'Value'],
            [
                ['OK', ($preflight['ok'] ?? false) ? 'yes' : 'no'],
                ['ca_firms', ($preflight['has_ca_firms'] ?? false) ? 'yes' : 'no'],
                ['ca_partners', ($preflight['has_ca_partners'] ?? false) ? 'yes' : 'no'],
                ['ca_addresses', ($preflight['has_ca_addresses'] ?? false) ? 'yes' : 'no'],
                ['firm_count', (string) ($preflight['firm_count'] ?? 0)],
                ['normalized_firm', ($preflight['has_normalized_firm'] ?? false) ? 'yes' : 'no'],
                ['normalized_city', ($preflight['has_normalized_city'] ?? false) ? 'yes' : 'no'],
                ['error', (string) ($preflight['error'] ?? $result['error'] ?? '—')],
            ]
        );

        if (! ($result['ok'] ?? false)) {
            $this->error('Remap aborted: '.($result['error'] ?? 'CA Reference unavailable'));
            $this->warn('Zero sales_import_rows updated. Zero audit rows written.');

            return self::FAILURE;
        }

        $mode = ($result['dry_run'] ?? false) ? 'DRY-RUN' : 'APPLIED';
        $this->info("Mode: {$mode}");
        if (! empty($result['mapping_run_id'])) {
            $this->line('Mapping run: '.$result['mapping_run_id']);
        }

        $transitions = $result['status_transitions'] ?? [];
        if ($transitions !== []) {
            $this->newLine();
            $this->info('Current Status → Proposed Status');
            $this->table(
                ['Current Status', 'Proposed Status', 'Count'],
                collect($transitions)->map(function ($count, $key) {
                    [$from, $to] = array_pad(explode(' → ', (string) $key, 2), 2, '—');

                    return [$from, $to, $count];
                })->values()->all()
            );
        }

        $rows = [];
        foreach ($result['files'] ?? [] as $file) {
            $rows[] = [
                $file['file'] ?? '—',
                $file['employee'] ?? '—',
                $file['eligible'] ?? 0,
                $file['skipped_protected'] ?? 0,
                $file['would_match'] ?? 0,
                $file['would_need_review'] ?? 0,
                $file['would_stay_unmatched'] ?? 0,
                $file['errors'] ?? 0,
            ];
        }

        $this->newLine();
        $this->table(
            ['File', 'Employee', 'Eligible', 'Skipped Protected', 'Would Match', 'Would Need Review', 'Would Stay Unmatched', 'Errors'],
            $rows
        );

        $samples = $result['samples'] ?? [];
        if ($samples !== []) {
            $this->newLine();
            $this->info('Sample proposed matches (max 15)');
            $this->table(
                ['Sales Row ID', 'Sales Firm', 'Sales City', 'Proposed Reference Firm ID', 'Proposed CA Master ID', 'Match Reason'],
                collect($samples)->map(fn ($s) => [
                    $s['id'] ?? '—',
                    mb_substr((string) ($s['firm_name'] ?? ''), 0, 40),
                    $s['city_name'] ?? '—',
                    $s['proposed_reference_firm_id'] ?? '—',
                    $s['proposed_ca_id'] ?? '—',
                    $s['match_reason'] ?? '—',
                ])->all()
            );
        }

        $t = $result['totals'] ?? [];
        $this->newLine();
        $this->info('Overall totals');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Eligible rows', $t['eligible'] ?? 0],
                ['Protected rows skipped', $t['skipped_protected'] ?? 0],
                ['Processed', $t['processed'] ?? 0],
                ['Changed', $t['changed'] ?? 0],
                ['New matched', $t['new_matched'] ?? 0],
                ['New needs review', $t['new_needs_review'] ?? 0],
                ['Still unmatched', $t['still_unmatched'] ?? 0],
                ['Unchanged', $t['unchanged'] ?? 0],
                ['Errors', $t['errors'] ?? 0],
                ['Audit rows created', $t['audit_rows_created'] ?? 0],
            ]
        );

        if (! empty($result['errors'])) {
            $this->warn('Row errors (first 20):');
            foreach (array_slice($result['errors'], 0, 20) as $err) {
                $this->line(sprintf(
                    '  id=%s row=%s file=%s — %s',
                    $err['id'] ?? '—',
                    $err['source_row_number'] ?? '—',
                    $err['source_file_name'] ?? '—',
                    $err['message'] ?? ''
                ));
            }
        }

        $this->warn('No CA master/reference record was created, updated, or deleted.');
        $this->warn('No sales_import_rows were inserted or deleted.');

        return self::SUCCESS;
    }
}
