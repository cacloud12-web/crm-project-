<?php

namespace App\Services\Ocr;

use App\Http\Resources\OcrParsedFirmResource;
use App\Models\CaMaster;
use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Services\Mapping\DataNormalizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Golden-dataset field audit + document reconciliation for the three-field OCR workflow.
 * Does not invent expected values from parser output.
 */
class OcrGoldenAuditService
{
    public function __construct(
        private readonly DataNormalizationService $normalizer,
    ) {}

    /**
     * @param  list<array{page_number?:int,row_number?:int,firm_name:string,ca_name:string,city:string}>  $expected
     * @param  list<array{firm_name:?string,ca_name:?string,city:?string,raw_firm_name?:?string,raw_ca_name?:?string,raw_city?:?string,page_number?:?int,sequence_no?:?int,status?:?string,match_status?:?string,match_type?:?string,validation_errors?:?array}>  $actual
     * @return array<string, mixed>
     */
    public function compareGolden(array $expected, array $actual): array
    {
        $byFirm = [];
        foreach ($actual as $row) {
            $key = $this->firmKey((string) ($row['firm_name'] ?? ''));
            if ($key === '') {
                continue;
            }
            $byFirm[$key] = $row;
        }

        $mismatches = [];
        $exactComplete = 0;
        $firmExact = 0;
        $caExact = 0;
        $cityExact = 0;
        $criticalWrong = 0;
        $missingRequired = 0;
        $neighbor = 0;
        $silentLoss = 0;

        foreach ($expected as $exp) {
            $key = $this->firmKey($exp['firm_name']);
            $got = $byFirm[$key] ?? null;
            if ($got === null) {
                $silentLoss++;
                $mismatches[] = $this->mismatch($exp, null, 'SILENTLY_DROPPED_ROW', 'critical');
                continue;
            }

            $firmCmp = $this->classifyFieldDiff($exp['firm_name'], $got['firm_name'] ?? null, $got['raw_firm_name'] ?? null, 'firm_name');
            $caCmp = $this->classifyFieldDiff($exp['ca_name'], $got['ca_name'] ?? null, $got['raw_ca_name'] ?? null, 'ca_name');
            $cityCmp = $this->classifyFieldDiff($exp['city'], $got['city'] ?? null, $got['raw_city'] ?? null, 'city');

            if ($firmCmp['class'] === 'EXACT_MATCH' || $firmCmp['class'] === 'APPROVED_NORMALIZATION_DIFFERENCE'
                || $firmCmp['class'] === 'CASE_ONLY_DIFFERENCE' || $firmCmp['class'] === 'WHITESPACE_ONLY_DIFFERENCE') {
                $firmExact++;
            } else {
                $criticalWrong++;
                $mismatches[] = $this->fieldMismatch($exp, $got, $firmCmp, 'firm_name');
            }
            if ($caCmp['class'] === 'EXACT_MATCH' || $caCmp['class'] === 'APPROVED_NORMALIZATION_DIFFERENCE'
                || $caCmp['class'] === 'CASE_ONLY_DIFFERENCE' || $caCmp['class'] === 'WHITESPACE_ONLY_DIFFERENCE') {
                $caExact++;
            } else {
                $criticalWrong++;
                if ($caCmp['class'] === 'MISSING_FIELD') {
                    $missingRequired++;
                }
                $mismatches[] = $this->fieldMismatch($exp, $got, $caCmp, 'ca_name');
            }
            if ($cityCmp['class'] === 'EXACT_MATCH' || $cityCmp['class'] === 'APPROVED_NORMALIZATION_DIFFERENCE'
                || $cityCmp['class'] === 'CASE_ONLY_DIFFERENCE' || $cityCmp['class'] === 'WHITESPACE_ONLY_DIFFERENCE') {
                $cityExact++;
            } else {
                $criticalWrong++;
                if ($cityCmp['class'] === 'MISSING_FIELD') {
                    $missingRequired++;
                }
                $mismatches[] = $this->fieldMismatch($exp, $got, $cityCmp, 'city');
            }

            $allOk = in_array($firmCmp['class'], ['EXACT_MATCH', 'APPROVED_NORMALIZATION_DIFFERENCE', 'CASE_ONLY_DIFFERENCE', 'WHITESPACE_ONLY_DIFFERENCE'], true)
                && in_array($caCmp['class'], ['EXACT_MATCH', 'APPROVED_NORMALIZATION_DIFFERENCE', 'CASE_ONLY_DIFFERENCE', 'WHITESPACE_ONLY_DIFFERENCE'], true)
                && in_array($cityCmp['class'], ['EXACT_MATCH', 'APPROVED_NORMALIZATION_DIFFERENCE', 'CASE_ONLY_DIFFERENCE', 'WHITESPACE_ONLY_DIFFERENCE'], true);
            if ($allOk) {
                $exactComplete++;
            }

            unset($byFirm[$key]);
        }

        $n = max(1, count($expected));

        return [
            'expected_count' => count($expected),
            'matched_actual_count' => count($expected) - $silentLoss,
            'firm_name_exact_accuracy' => round(($firmExact / $n) * 100, 2),
            'ca_name_exact_accuracy' => round(($caExact / $n) * 100, 2),
            'city_exact_accuracy' => round(($cityExact / $n) * 100, 2),
            'complete_record_exact_accuracy' => round(($exactComplete / $n) * 100, 2),
            'critical_wrong_field_count' => $criticalWrong,
            'missing_required_field_count' => $missingRequired,
            'neighbor_row_contamination_count' => $neighbor,
            'silent_loss_count' => $silentLoss,
            'mismatches' => $mismatches,
            'pass' => $silentLoss === 0 && $criticalWrong === 0 && $missingRequired === 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reconcileDocument(OcrDocument $document): array
    {
        $firms = OcrParsedFirm::query()
            ->where('ocr_document_id', $document->id)
            ->orderBy('sequence_no')
            ->get();

        $structured = is_array($document->structured_data) ? $document->structured_data : [];
        $quality = is_array($structured['parsed']['quality_report'] ?? null)
            ? $structured['parsed']['quality_report']
            : [];
        $detected = (int) ($quality['total_rows_detected']
            ?? $quality['total_source_rows']
            ?? $document->parsed_firm_count
            ?? $firms->count());

        $verified = 0;
        $ready = 0;
        $review = 0;
        $conflicts = 0;
        $invalid = 0;
        $rejected = 0;
        $failed = 0;
        $merged = 0;
        $split = 0;
        $request = Request::create('/');

        foreach ($firms as $firm) {
            $payload = (new OcrParsedFirmResource($firm))->toArray($request);
            $status = (string) ($payload['status'] ?? 'Needs Review');
            $matchType = (string) ($payload['match_type'] ?? '');
            $source = is_array($firm->source_data) ? $firm->source_data : [];
            if (! empty($source['row_merge_suspected']) || in_array('ROW_MERGE_SUSPECTED', $firm->validation_errors ?? [], true)) {
                $merged++;
            }
            if (! empty($source['row_split_suspected'])) {
                $split++;
            }

            if ($status === 'Verified') {
                $verified++;
            } elseif ($status === 'Conflict') {
                $conflicts++;
            } elseif ($status === 'Invalid') {
                $invalid++;
            } elseif ($status === 'Rejected') {
                $rejected++;
            } elseif ($matchType === 'Ready to accept' || $matchType === 'No Exact Match') {
                $ready++;
            } elseif (($firm->match_status ?? '') === 'failed') {
                $failed++;
            } else {
                $review++;
            }
        }

        $parsed = $firms->count();
        $accounted = $verified + $ready + $review + $conflicts + $invalid + $rejected + $failed;
        $coverage = $detected > 0 ? round(($parsed / $detected) * 100, 2) : 0.0;

        return [
            'detected_source_rows' => $detected,
            'parsed_rows' => $parsed,
            'verified_rows' => $verified,
            'ready_to_accept' => $ready,
            'needs_review' => $review,
            'conflicts' => $conflicts,
            'invalid' => $invalid,
            'rejected' => $rejected,
            'failed' => $failed,
            'missing_rows' => max(0, $detected - $parsed),
            'duplicate_rows' => 0,
            'merged_rows' => $merged,
            'split_rows' => $split,
            'row_coverage_percent' => $coverage,
            'equation_balances' => $accounted === $parsed,
            'parsed_equals_detected' => $parsed === $detected,
            'pass' => $parsed === $detected && $accounted === $parsed && max(0, $detected - $parsed) === 0,
        ];
    }

    /**
     * @return array{connection: string, database: string, table: string, master_count: int}
     */
    public function matchingConnectionInfo(): array
    {
        $conn = CaMaster::query()->getConnection();

        return [
            'connection' => $conn->getName(),
            'database' => (string) $conn->getDatabaseName(),
            'table' => $conn->getTablePrefix().(new CaMaster)->getTable(),
            'master_count' => (int) CaMaster::query()->count(),
        ];
    }

    /**
     * @param  list<string>  $forbidden
     * @param  list<array{ca_name?:?string}>  $actual
     * @return list<array{ca_name: string, reason: string}>
     */
    public function findForbiddenCaNames(array $forbidden, array $actual): array
    {
        $hits = [];
        $set = array_map(static fn ($v) => mb_strtoupper(trim($v)), $forbidden);
        foreach ($actual as $row) {
            $ca = trim((string) ($row['ca_name'] ?? ''));
            if ($ca !== '' && in_array(mb_strtoupper($ca), $set, true)) {
                $hits[] = ['ca_name' => $ca, 'reason' => 'WRONG_FIELD'];
            }
        }

        return $hits;
    }

    /**
     * Scale smoke: N synthetic exact matches via indexed normalized columns.
     *
     * @return array{rows: int, matching_ms: float, query_count: int, peak_memory_mb: float}
     */
    public function scaleMatchSmoke(int $rows): array
    {
        $startMem = memory_get_usage(true);
        $before = count(DB::getQueryLog());
        DB::enableQueryLog();
        $t0 = microtime(true);
        $hits = 0;
        for ($i = 0; $i < $rows; $i++) {
            $firm = $this->normalizer->firmName('SCALE FIRM '.$i.' & ASSOCIATES');
            $ca = $this->normalizer->caName('SCALE PERSON '.$i);
            // Indexed path: normalized_firm_name whereIn-style exact lookup (bounded).
            $q = CaMaster::query()->where('normalized_firm_name', $firm);
            if (\Illuminate\Support\Facades\Schema::hasColumn('ca_masters', 'normalized_ca_name')) {
                $q->where('normalized_ca_name', $ca);
            }
            $hits += $q->limit(2)->count();
        }
        $ms = (microtime(true) - $t0) * 1000;
        $queries = count(DB::getQueryLog()) - $before;
        DB::disableQueryLog();

        return [
            'rows' => $rows,
            'matching_ms' => round($ms, 2),
            'query_count' => max(0, $queries),
            'peak_memory_mb' => round(max(memory_get_usage(true), $startMem) / 1048576, 2),
            'hits' => $hits,
        ];
    }

    private function firmKey(string $firm): string
    {
        $n = $this->normalizer->firmName($firm);

        return $n !== null ? mb_strtoupper($n) : mb_strtoupper(trim($firm));
    }

    /**
     * @return array{class: string, expected: string, parsed: ?string, raw: ?string, normalized: ?string}
     */
    private function classifyFieldDiff(string $expected, ?string $parsed, ?string $raw, string $field): array
    {
        $expected = trim($expected);
        $parsed = $parsed !== null ? trim($parsed) : null;
        $raw = $raw !== null ? trim($raw) : null;
        $normFn = match ($field) {
            'firm_name' => fn ($v) => $this->normalizer->firmName($v),
            'ca_name' => fn ($v) => $this->normalizer->caName($v),
            default => fn ($v) => $this->normalizer->city($v),
        };
        $expNorm = $normFn($expected);
        $parsedNorm = $parsed !== null && $parsed !== '' ? $normFn($parsed) : null;

        if ($parsed === null || $parsed === '') {
            return ['class' => 'MISSING_FIELD', 'expected' => $expected, 'parsed' => $parsed, 'raw' => $raw, 'normalized' => $parsedNorm];
        }
        if ($expected === $parsed) {
            return ['class' => 'EXACT_MATCH', 'expected' => $expected, 'parsed' => $parsed, 'raw' => $raw, 'normalized' => $parsedNorm];
        }
        if (mb_strtoupper($expected) === mb_strtoupper($parsed)) {
            return ['class' => 'CASE_ONLY_DIFFERENCE', 'expected' => $expected, 'parsed' => $parsed, 'raw' => $raw, 'normalized' => $parsedNorm];
        }
        if (preg_replace('/\s+/', ' ', $expected) === preg_replace('/\s+/', ' ', $parsed)) {
            return ['class' => 'WHITESPACE_ONLY_DIFFERENCE', 'expected' => $expected, 'parsed' => $parsed, 'raw' => $raw, 'normalized' => $parsedNorm];
        }
        if ($expNorm !== null && $parsedNorm !== null && $expNorm === $parsedNorm) {
            return ['class' => 'APPROVED_NORMALIZATION_DIFFERENCE', 'expected' => $expected, 'parsed' => $parsed, 'raw' => $raw, 'normalized' => $parsedNorm];
        }
        $wrong = match ($field) {
            'firm_name' => 'WRONG_FIRM_NAME',
            'ca_name' => 'WRONG_CA_NAME',
            default => 'WRONG_CITY',
        };

        return ['class' => $wrong, 'expected' => $expected, 'parsed' => $parsed, 'raw' => $raw, 'normalized' => $parsedNorm];
    }

    /**
     * @param  array<string, mixed>  $exp
     * @param  array<string, mixed>|null  $got
     * @return array<string, mixed>
     */
    private function mismatch(array $exp, ?array $got, string $reason, string $severity): array
    {
        return [
            'page' => $exp['page_number'] ?? null,
            'row' => $exp['row_number'] ?? null,
            'expected' => $exp,
            'raw_ocr' => null,
            'parsed' => $got,
            'normalized' => null,
            'reason' => $reason,
            'severity' => $severity,
            'recommended_fix' => $reason === 'SILENTLY_DROPPED_ROW'
                ? 'Investigate segmentation skip / C/O attach / empty block drop'
                : 'Inspect parser assignment for this field',
        ];
    }

    /**
     * @param  array<string, mixed>  $exp
     * @param  array<string, mixed>  $got
     * @param  array{class: string, expected: string, parsed: ?string, raw: ?string, normalized: ?string}  $cmp
     * @return array<string, mixed>
     */
    private function fieldMismatch(array $exp, array $got, array $cmp, string $field): array
    {
        return [
            'page' => $exp['page_number'] ?? ($got['page_number'] ?? null),
            'row' => $exp['row_number'] ?? ($got['sequence_no'] ?? null),
            'field' => $field,
            'expected' => $cmp['expected'],
            'raw_ocr' => $cmp['raw'],
            'parsed' => $cmp['parsed'],
            'normalized' => $cmp['normalized'],
            'reason' => $cmp['class'],
            'severity' => in_array($cmp['class'], ['MISSING_FIELD', 'WRONG_CA_NAME', 'WRONG_CITY', 'WRONG_FIRM_NAME', 'SILENTLY_DROPPED_ROW'], true)
                ? 'critical'
                : 'medium',
            'recommended_fix' => 'Align parser/token classification for '.$field,
        ];
    }
}
