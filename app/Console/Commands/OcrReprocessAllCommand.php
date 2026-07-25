<?php

namespace App\Console\Commands;

use App\Models\OcrDocument;
use App\Services\Ocr\OcrStructurePersistService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Re-parse every OCR document from stored Document AI lean JSON / extracted_text.
 * Does NOT call Google. Does NOT invent fields. Does NOT touch ca_masters unless asked.
 */
class OcrReprocessAllCommand extends Command
{
    protected $signature = 'ocr:reprocess-all
        {--dry-run : Report only (default unless --apply)}
        {--apply : Replace ocr_parsed_firms/members from stored layout}
        {--document= : Limit to one OCR document id}
        {--limit=0 : Stop after N documents (0 = all)}
        {--export= : CSV audit path for before/after completeness}
        {--force : Required with --apply in production}';

    protected $description = 'Reprocess all OCR documents from stored Document AI JSON (no Google call; dry-run default)';

    public function handle(OcrStructurePersistService $persist): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = ! $apply || (bool) $this->option('dry-run');
        if ((bool) $this->option('dry-run')) {
            $apply = false;
            $dryRun = true;
        }

        if ($apply && app()->environment('production') && ! (bool) $this->option('force')) {
            $this->error('Refusing production --apply without --force.');

            return self::FAILURE;
        }

        if ($apply && ! $this->confirm(
            'Replace ocr_parsed_firms/members from stored Document AI layout for selected documents?',
            false
        )) {
            $this->warn('Cancelled. No staging rows changed.');

            return self::SUCCESS;
        }

        $export = $this->option('export');
        if ($export === null || $export === '') {
            $export = storage_path('app/ocr-audits/reprocess-all-'.now()->format('Ymd_His').'.csv');
        }

        $query = OcrDocument::query()->orderBy('id');
        if ($this->option('document') !== null && $this->option('document') !== '') {
            $query->whereKey((int) $this->option('document'));
        }
        $limit = max(0, (int) ($this->option('limit') ?? 0));

        $docs = $query->get();
        if ($limit > 0) {
            $docs = $docs->take($limit);
        }

        $this->info($dryRun
            ? 'OCR reprocess-all — DRY-RUN (no staging writes)'
            : 'OCR reprocess-all — APPLY (replace staging from stored layout)');
        $this->comment('Google Document AI will NOT be called. Masters are NOT rewritten by this command.');

        $rows = [];
        $totals = [
            'documents' => 0,
            'skipped_no_layout' => 0,
            'before_firms' => 0,
            'after_firms' => 0,
            'before_missing_ca' => 0,
            'after_missing_ca' => 0,
            'before_missing_city' => 0,
            'after_missing_city' => 0,
            'errors' => 0,
        ];

        foreach ($docs as $document) {
            /** @var OcrDocument $document */
            $hasLayout = is_array($document->structured_data)
                && ! empty($document->structured_data['pages']);
            $hasText = trim((string) ($document->extracted_text ?? '')) !== '';
            if (! $hasLayout && ! $hasText && ! $document->isCompleted()) {
                $totals['skipped_no_layout']++;
                $rows[] = [
                    'document_id' => $document->id,
                    'filename' => $document->original_filename,
                    'decision' => 'skipped_no_layout',
                    'reason' => 'No structured_data pages / extracted_text',
                ];
                continue;
            }

            $before = $this->documentCompleteness((int) $document->id);
            $totals['documents']++;
            $totals['before_firms'] += $before['firms'];
            $totals['before_missing_ca'] += $before['missing_ca'];
            $totals['before_missing_city'] += $before['missing_city'];

            $after = $before;
            $decision = 'dry_run_measured';
            $reason = 'Would re-parse from stored Document AI layout';
            $peelableCa = $this->countPeelableBlankCa((int) $document->id);

            if ($apply) {
                try {
                    $out = $persist->parseAndPersist($document->fresh());
                    $after = $this->documentCompleteness((int) $document->id);
                    $decision = 'reparsed';
                    $reason = 'firms='.(int) ($out->parsed_firm_count ?? $after['firms']);
                } catch (Throwable $e) {
                    $totals['errors']++;
                    $decision = 'error';
                    $reason = $e->getMessage();
                    $this->error("#{$document->id}: ".$e->getMessage());
                }
            } else {
                // Conservative estimate: firm-title peel recovers blank CA without calling Google.
                // Full after_* counts require --apply (rebuild from stored layout).
                $after['missing_ca'] = max(0, $before['missing_ca'] - $peelableCa);
                $reason = 'peelable_blank_ca='.$peelableCa.'; full rebuild needs --apply';
            }

            $totals['after_firms'] += $after['firms'];
            $totals['after_missing_ca'] += $after['missing_ca'];
            $totals['after_missing_city'] += $after['missing_city'];

            $rows[] = [
                'document_id' => $document->id,
                'filename' => $document->original_filename,
                'before_firms' => $before['firms'],
                'before_missing_ca' => $before['missing_ca'],
                'before_missing_city' => $before['missing_city'],
                'after_firms' => $after['firms'],
                'after_missing_ca' => $after['missing_ca'],
                'after_missing_city' => $after['missing_city'],
                'decision' => $decision,
                'reason' => $reason,
            ];

            $this->line(sprintf(
                ' #%d %s firms=%d miss_ca=%d miss_city=%d → %s',
                $document->id,
                $document->original_filename,
                $before['firms'],
                $before['missing_ca'],
                $before['missing_city'],
                $decision
            ));
        }

        $this->writeCsv((string) $export, $rows);
        $this->newLine();
        $this->table(['Metric', 'Value'], [
            ['documents processed', $totals['documents']],
            ['skipped no layout', $totals['skipped_no_layout']],
            ['before firms', $totals['before_firms']],
            ['after firms', $apply ? $totals['after_firms'] : $totals['after_firms'].' (unchanged until --apply)'],
            ['before missing CA', $totals['before_missing_ca']],
            ['after missing CA', $apply
                ? $totals['after_missing_ca']
                : $totals['after_missing_ca'].' (est. after firm-title peel; full rebuild needs --apply)'],
            ['before missing city', $totals['before_missing_city']],
            ['after missing city', $apply
                ? $totals['after_missing_city']
                : $totals['after_missing_city'].' (unchanged by peel; needs heading/layout rebuild)'],
            ['errors', $totals['errors']],
            ['export', $export],
        ]);

        $this->comment('Master remap is intentionally separate. After staging reprocess, run import only for blank Master fields.');
        if ($dryRun) {
            $this->line('  php artisan ocr:reprocess-all --apply --force --export="'.$export.'"');
        }

        return $totals['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Count blank-CA staging rows whose firm_name deterministically peels to a person.
     */
    private function countPeelableBlankCa(int $documentId): int
    {
        $extractor = app(\App\Services\Ocr\OcrFirmCaCityExtractorService::class);
        $n = 0;
        foreach (DB::table('ocr_parsed_firms')->where('ocr_document_id', $documentId)->cursor() as $row) {
            $sd = is_string($row->source_data)
                ? (json_decode($row->source_data, true) ?: [])
                : (array) ($row->source_data ?? []);
            $ca = trim((string) (($sd['parsed']['ca_name'] ?? '') ?: ($sd['raw']['ca_name'] ?? '') ?: ($sd['ca_name'] ?? '')));
            if ($ca !== '') {
                continue;
            }
            $firm = trim((string) ($row->firm_name ?? ''));
            if ($firm === '') {
                continue;
            }
            if ($extractor->suggestCaFromFirmName($firm) !== null) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @return array{firms: int, missing_ca: int, missing_city: int}
     */
    private function documentCompleteness(int $documentId): array
    {
        $firms = DB::table('ocr_parsed_firms')->where('ocr_document_id', $documentId)->count();
        $missingCity = DB::table('ocr_parsed_firms')
            ->where('ocr_document_id', $documentId)
            ->where(function ($q) {
                $q->whereNull('city')->orWhere('city', '');
            })
            ->count();

        $missingCa = 0;
        foreach (DB::table('ocr_parsed_firms')->where('ocr_document_id', $documentId)->cursor() as $row) {
            $sd = is_string($row->source_data)
                ? (json_decode($row->source_data, true) ?: [])
                : (array) ($row->source_data ?? []);
            $ca = trim((string) (($sd['parsed']['ca_name'] ?? '') ?: ($sd['raw']['ca_name'] ?? '') ?: ($sd['ca_name'] ?? '')));
            if ($ca === '') {
                $missingCa++;
            }
        }

        return [
            'firms' => $firms,
            'missing_ca' => $missingCa,
            'missing_city' => $missingCity,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeCsv(string $path, array $rows): void
    {
        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && ! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            return;
        }
        fputcsv($fh, [
            'document_id', 'filename', 'before_firms', 'before_missing_ca', 'before_missing_city',
            'after_firms', 'after_missing_ca', 'after_missing_city', 'decision', 'reason',
        ]);
        foreach ($rows as $row) {
            fputcsv($fh, [
                $row['document_id'] ?? '',
                $row['filename'] ?? '',
                $row['before_firms'] ?? '',
                $row['before_missing_ca'] ?? '',
                $row['before_missing_city'] ?? '',
                $row['after_firms'] ?? '',
                $row['after_missing_ca'] ?? '',
                $row['after_missing_city'] ?? '',
                $row['decision'] ?? '',
                $row['reason'] ?? '',
            ]);
        }
        fclose($fh);
    }
}
