<?php

namespace App\Console\Commands;

use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Services\Ocr\OcrGoldenAuditService;
use Illuminate\Console\Command;

class OcrAuditGoldenCommand extends Command
{
    protected $signature = 'ocr:audit-golden
        {documentId? : OCR document ID (optional if --fixture-only)}
        {--golden= : Path to golden JSON (default: tests/Fixtures/ocr/golden_northprop_three_field.json)}
        {--fixture-only : Skip document reconciliation; audit extractor tokens only}';

    protected $description = 'Audit OCR three-field output against a manually curated golden dataset';

    public function handle(OcrGoldenAuditService $audit): int
    {
        $goldenPath = $this->option('golden')
            ?: base_path('tests/Fixtures/ocr/golden_northprop_three_field.json');
        if (! is_file($goldenPath)) {
            $this->error('Golden file not found: '.$goldenPath);

            return self::FAILURE;
        }
        $golden = json_decode((string) file_get_contents($goldenPath), true);
        if (! is_array($golden) || ! is_array($golden['records'] ?? null)) {
            $this->error('Invalid golden JSON structure.');

            return self::FAILURE;
        }

        $this->info('=== Matching connection (no passwords) ===');
        foreach ($audit->matchingConnectionInfo() as $k => $v) {
            $this->line($k.': '.$v);
        }
        $this->newLine();

        $docId = $this->argument('documentId');
        $actual = [];
        $recon = null;
        if ($docId && ! $this->option('fixture-only')) {
            $document = OcrDocument::withTrashed()->find((int) $docId);
            if (! $document) {
                $this->error('Document not found.');

                return self::FAILURE;
            }
            if ($document->trashed()) {
                $document->restore();
            }
            $recon = $audit->reconcileDocument($document);
            $this->info('=== Document reconciliation #'.$document->id.' ===');
            foreach ($recon as $k => $v) {
                $this->line($k.': '.(is_bool($v) ? ($v ? 'true' : 'false') : json_encode($v)));
            }
            $this->newLine();

            foreach (OcrParsedFirm::query()->where('ocr_document_id', $document->id)->orderBy('sequence_no')->get() as $firm) {
                $src = is_array($firm->source_data) ? $firm->source_data : [];
                $parsed = is_array($src['parsed'] ?? null) ? $src['parsed'] : [];
                $raw = is_array($src['raw'] ?? null) ? $src['raw'] : [];
                $actual[] = [
                    'firm_name' => $firm->firm_name ?: ($parsed['firm_name'] ?? null),
                    'ca_name' => $parsed['ca_name'] ?? ($src['ca_name'] ?? null),
                    'city' => $firm->city ?: ($parsed['city'] ?? null),
                    'raw_firm_name' => $raw['firm_name'] ?? $firm->raw_firm_name,
                    'raw_ca_name' => $raw['ca_name'] ?? null,
                    'raw_city' => $raw['city'] ?? null,
                    'page_number' => $firm->page_number,
                    'sequence_no' => $firm->sequence_no,
                    'match_status' => $firm->match_status,
                    'validation_errors' => $firm->validation_errors,
                ];
            }
        }

        if ($actual === []) {
            $this->warn('No document rows loaded — golden comparison skipped (pass -- documentId).');
            $this->info('Golden expected records: '.count($golden['records']));

            return self::SUCCESS;
        }

        $cmp = $audit->compareGolden($golden['records'], $actual);
        $this->info('=== Golden field accuracy ===');
        foreach ([
            'expected_count', 'firm_name_exact_accuracy', 'ca_name_exact_accuracy',
            'city_exact_accuracy', 'complete_record_exact_accuracy',
            'critical_wrong_field_count', 'missing_required_field_count',
            'silent_loss_count', 'pass',
        ] as $k) {
            $this->line($k.': '.json_encode($cmp[$k]));
        }
        if (($cmp['mismatches'] ?? []) !== []) {
            $this->newLine();
            $this->warn('Mismatches:');
            foreach ($cmp['mismatches'] as $m) {
                $this->line(json_encode($m));
            }
        }

        $forbidden = $audit->findForbiddenCaNames(
            $golden['must_never_be_ca_name'] ?? [],
            $actual,
        );
        $this->newLine();
        $this->info('Forbidden CA names found: '.count($forbidden));
        foreach ($forbidden as $hit) {
            $this->line(json_encode($hit));
        }

        $pass = ($cmp['pass'] ?? false)
            && ($recon['pass'] ?? true)
            && $forbidden === [];
        $this->newLine();
        $this->info($pass ? 'OVERALL: PASS (golden subset + reconciliation)' : 'OVERALL: FAIL');

        return $pass ? self::SUCCESS : self::FAILURE;
    }
}
