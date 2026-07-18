<?php

namespace App\Console\Commands;

use App\Models\CaMaster;
use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Services\Mapping\DataNormalizationService;
use App\Services\Mapping\FirmCaCityMatchingProfile;
use App\Services\Ocr\OcrDirectoryRecordParser;
use App\Services\Ocr\OcrEntityClassificationService;
use App\Services\Ocr\OcrLayoutDirectoryParser;
use App\Services\Ocr\OcrRecordSegmentationService;
use Illuminate\Console\Command;
use ReflectionMethod;

/**
 * Safe debug report for Invalid / incomplete three-field OCR rows.
 * Does not print credentials or passwords.
 */
class OcrDebugInvalidCommand extends Command
{
    protected $signature = 'ocr:debug-invalid {documentId : OCR document ID}';

    protected $description = 'List invalid OCR staging rows with tokens, classifications, and match diagnostics';

    public function handle(
        DataNormalizationService $normalizer,
        FirmCaCityMatchingProfile $matcher,
    ): int {
        $docId = (int) $this->argument('documentId');
        $document = OcrDocument::withTrashed()->find($docId);
        if (! $document) {
            $this->error("OCR document {$docId} not found.");

            return self::FAILURE;
        }

        $conn = CaMaster::query()->getConnection();
        $this->info('=== Database / matching connection ===');
        $this->line('Laravel connection: '.$conn->getName());
        $this->line('Database name: '.$conn->getDatabaseName());
        $this->line('Table: '.$conn->getTablePrefix().(new CaMaster)->getTable());
        $this->line('Master row count: '.CaMaster::query()->count());
        $this->line('OCR workflow mode: '.config('ocr_workflow.mode', 'firm_ca_city'));
        $this->newLine();

        $firms = OcrParsedFirm::query()
            ->where('ocr_document_id', $document->id)
            ->orderBy('sequence_no')
            ->get();

        $invalid = [];
        foreach ($firms as $firm) {
            $source = is_array($firm->source_data) ? $firm->source_data : [];
            $parsed = is_array($source['parsed'] ?? null) ? $source['parsed'] : [];
            $raw = is_array($source['raw'] ?? null) ? $source['raw'] : [];
            $ca = trim((string) ($parsed['ca_name'] ?? ($source['ca_name'] ?? '')));
            $city = trim((string) ($firm->city ?? ($parsed['city'] ?? '')));
            $firmName = trim((string) ($firm->firm_name ?? ($parsed['firm_name'] ?? '')));
            $errs = is_array($firm->validation_errors) ? $firm->validation_errors : [];
            $codes = is_array($source['validation']['collision_codes'] ?? null)
                ? $source['validation']['collision_codes']
                : [];
            $missing = [];
            if ($firmName === '') {
                $missing[] = 'firm_name';
            }
            if ($ca === '') {
                $missing[] = 'ca_name';
            }
            if ($city === '') {
                $missing[] = 'city';
            }
            $isInvalid = $missing !== []
                || array_intersect($codes, ['MISSING_FIRM_NAME', 'MISSING_CA_NAME', 'MISSING_CITY', 'MISSING_REQUIRED_FIELD']) !== []
                || array_filter($errs, static fn ($e) => is_string($e) && (str_contains(mb_strtolower($e), 'required') || str_starts_with((string) $e, 'MISSING_')));
            if (! $isInvalid) {
                continue;
            }
            $invalid[] = compact('firm', 'source', 'parsed', 'raw', 'ca', 'city', 'firmName', 'errs', 'codes', 'missing');
        }

        $this->info('Document #'.$document->id.' ('.($document->original_filename ?? 'n/a').') — invalid/incomplete: '.count($invalid).' / '.$firms->count());
        $this->newLine();

        $tokenIndex = $this->buildTokenIndex($document);

        foreach ($invalid as $i => $row) {
            /** @var OcrParsedFirm $firm */
            $firm = $row['firm'];
            $nFirm = $normalizer->firmName($row['firmName'] ?: null);
            $nCa = $normalizer->caName($row['ca'] !== '' ? $row['ca'] : null);
            $nCity = $normalizer->city($row['city'] !== '' ? $row['city'] : null);
            $match = $matcher->match([
                'firm_name' => $row['firmName'] !== '' ? $row['firmName'] : null,
                'ca_name' => $row['ca'] !== '' ? $row['ca'] : null,
                'city' => $row['city'] !== '' ? $row['city'] : null,
            ]);
            $tokens = $tokenIndex[$firm->sequence_no] ?? ($tokenIndex['page:'.$firm->page_number.':'.$row['firmName']] ?? []);

            $this->warn('--- Invalid #'.($i + 1).' ---');
            $this->line('OCR parsed firm ID: '.$firm->id);
            $this->line('page_number: '.($firm->page_number ?? 'null').'  row_number/sequence: '.($firm->row_number ?? $firm->sequence_no));
            $this->line('raw_firm_name: '.json_encode($row['raw']['firm_name'] ?? $firm->raw_firm_name));
            $this->line('raw_ca_name: '.json_encode($row['raw']['ca_name'] ?? null));
            $this->line('raw_city: '.json_encode($row['raw']['city'] ?? null));
            $this->line('parsed firm_name: '.json_encode($row['firmName']));
            $this->line('parsed ca_name: '.json_encode($row['ca'] !== '' ? $row['ca'] : null));
            $this->line('parsed city: '.json_encode($row['city'] !== '' ? $row['city'] : null));
            $this->line('normalized: firm='.json_encode($nFirm).' ca='.json_encode($nCa).' city='.json_encode($nCity));
            $this->line('missing_field: '.($row['missing'] !== [] ? implode(',', $row['missing']) : '(none — other validation)'));
            $this->line('validation_errors: '.json_encode($row['errs']));
            $this->line('scoped layout / collision codes: '.json_encode($row['codes']));
            $this->line('match_status: '.json_encode($firm->match_status).' reason='.json_encode($firm->match_reason));
            $this->line('CA Reference candidates: '.count($match->candidates ?? []).' status='.$match->status.' reason='.$match->reason);
            if (($match->candidates ?? []) !== []) {
                $this->line('candidate IDs: '.implode(',', array_column($match->candidates, 'ca_id')));
            }
            $this->line('visual record tokens:');
            $entities = new OcrEntityClassificationService;
            foreach ($tokens as $tok) {
                $text = (string) ($tok['text'] ?? '');
                $c = $entities->classify($text);
                $this->line('  - '.json_encode($text)
                    .' type='.($c['entity_type'] ?? '?')
                    .' bbox='.json_encode([
                        'x_min' => $tok['x_min'] ?? null,
                        'y_min' => $tok['y_min'] ?? null,
                        'x_max' => $tok['x_max'] ?? null,
                        'y_max' => $tok['y_max'] ?? null,
                    ]));
            }
            $ignored = $row['source']['ignored_tokens'] ?? [];
            if ($ignored) {
                $this->line('ignored_tokens: '.json_encode($ignored));
            }
            $this->newLine();
        }

        if ($invalid === []) {
            $this->info('No invalid/incomplete three-field rows found.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int|string, list<array<string, mixed>>>
     */
    private function buildTokenIndex(OcrDocument $document): array
    {
        $structured = is_array($document->structured_data) ? $document->structured_data : [];
        if ($structured === []) {
            return [];
        }
        try {
            $parser = app(OcrLayoutDirectoryParser::class);
            $method = new ReflectionMethod($parser, 'collectTokens');
            $method->setAccessible(true);
            $tokens = $method->invoke($parser, $structured);
            $seg = new OcrRecordSegmentationService(new OcrEntityClassificationService);
            $recordParser = new OcrDirectoryRecordParser(new OcrEntityClassificationService);
            $index = [];
            $seq = 1;
            $byPage = [];
            foreach ($tokens as $t) {
                $byPage[(int) ($t['page'] ?? 1)][] = $t;
            }
            ksort($byPage);
            $carry = null;
            foreach ($byPage as $page => $pageTokens) {
                $blocks = $seg->segmentPage($pageTokens, $carry);
                $carry = $seg->lastSectionCityFromBlocks($blocks) ?? $carry;
                foreach ($blocks as $block) {
                    if (! empty($block['is_section_heading'])) {
                        continue;
                    }
                    $all = $block['all_tokens'] ?? [];
                    if ($all === []) {
                        continue;
                    }
                    $parsed = $recordParser->parseBlock($all, [
                        'sequence_no' => $seq,
                        'page' => $page,
                        'section_city' => $block['section_city'] ?? null,
                    ]);
                    if ($parsed === null) {
                        continue;
                    }
                    $index[$seq] = $all;
                    $firmName = (string) ($parsed['firm_name'] ?? '');
                    if ($firmName !== '') {
                        $index['page:'.$page.':'.$firmName] = $all;
                    }
                    $seq++;
                }
            }

            return $index;
        } catch (\Throwable $e) {
            $this->warn('Token re-parse failed: '.$e->getMessage());

            return [];
        }
    }
}
