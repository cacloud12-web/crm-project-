<?php

/**
 * READ-ONLY final validation of Category A dry-run CSV.
 * No DB writes. No --apply. No production connection.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Ocr\OcrCityResolverService;
use App\Services\Ocr\OcrMissingCityAuditService;
use Illuminate\Support\Facades\DB;

$dryPath = $root.'/storage/app/audits/repair-category-a-missing-cities-dryrun.csv';
$detailPath = $root.'/storage/app/audits/ocr-linked-missing-cities-categories-prod-detail.csv';
$citiesPath = $root.'/storage/app/audits/prod-cities.csv';
$reportPath = $root.'/storage/app/audits/repair-category-a-preapply-validation-report.json';
$reportMd = $root.'/storage/app/audits/repair-category-a-preapply-validation-report.md';

$errors = [];
$warnings = [];
$checks = [];

function fail(array &$errors, string $code, string $message, array $meta = []): void
{
    $errors[] = ['code' => $code, 'message' => $message, 'meta' => $meta];
}

function loadCsv(string $path): array
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        throw new RuntimeException('Cannot open '.$path);
    }
    $header = fgetcsv($fh);
    $rows = [];
    while (($row = fgetcsv($fh)) !== false) {
        $map = array_combine($header ?: [], $row);
        if ($map !== false) {
            $rows[] = $map;
        }
    }
    fclose($fh);

    return $rows;
}

function firmKey(string $s): string
{
    return strtoupper(preg_replace('/\s+/u', ' ', trim($s)) ?? trim($s));
}

echo "Loading inputs...\n";
$dry = loadCsv($dryPath);
$detail = loadCsv($detailPath);
$citiesRows = loadCsv($citiesPath);

$audit = new OcrMissingCityAuditService;
$resolver = new OcrCityResolverService;

// Production cities index
$citiesById = [];
$citiesByNorm = [];
foreach ($citiesRows as $c) {
    $id = (int) ($c['city_id'] ?? 0);
    $name = trim((string) ($c['city_name'] ?? ''));
    if ($id <= 0 || $name === '') {
        continue;
    }
    $citiesById[$id] = $name;
    $k = $audit->normKey($name);
    if ($k === '') {
        continue;
    }
    if (! array_key_exists($k, $citiesByNorm)) {
        $citiesByNorm[$k] = $id;
    } elseif ($citiesByNorm[$k] !== $id) {
        $citiesByNorm[$k] = null; // ambiguous name
    }
}

$detailByCa = [];
foreach ($detail as $d) {
    if (strtoupper(trim((string) ($d['Category'] ?? ''))) !== 'A') {
        continue;
    }
    $detailByCa[(int) $d['CA ID']] = $d;
}

$recoverable = [
    OcrMissingCityAuditService::DECISION_APPLY,
    OcrMissingCityAuditService::DECISION_ALIAS,
    OcrMissingCityAuditService::DECISION_APPLY_ADDRESS,
    OcrMissingCityAuditService::DECISION_APPLY_HEADING,
    OcrMissingCityAuditService::DECISION_APPLY_SECTION,
];

echo 'dry_rows='.count($dry).' cities='.count($citiesById).' detail_A='.count($detailByCa)."\n";

// -------------------------------------------------------------------------
// 1. No duplicate CA IDs
// -------------------------------------------------------------------------
$caCounts = [];
foreach ($dry as $r) {
    $id = (int) ($r['CA ID'] ?? 0);
    $caCounts[$id] = ($caCounts[$id] ?? 0) + 1;
}
$dupCas = array_filter($caCounts, static fn ($n) => $n > 1);
if ($dupCas !== []) {
    fail($errors, 'DUP_CA_ID', 'Duplicate CA IDs in dry-run CSV', [
        'count' => count($dupCas),
        'examples' => array_slice(array_keys($dupCas), 0, 10),
    ]);
}
$checks['1_no_duplicate_ca_ids'] = [
    'pass' => $dupCas === [],
    'unique_ca_ids' => count($caCounts),
    'duplicate_ca_ids' => count($dupCas),
];

// -------------------------------------------------------------------------
// 2. No duplicate / conflicting city mappings
//    - each CA → exactly one After city_id
//    - each After city_id maps to a single Resolved City (normalized)
// -------------------------------------------------------------------------
$conflicts = [];
$idToNames = [];
foreach ($dry as $r) {
    $ca = (int) $r['CA ID'];
    $cid = (int) ($r['After city_id'] ?? 0);
    $resolved = $audit->normKey((string) ($r['Resolved City'] ?? ''));
    $idToNames[$cid][$resolved] = ($idToNames[$cid][$resolved] ?? 0) + 1;
}
$conflictingIds = [];
foreach ($idToNames as $cid => $names) {
    if (count($names) > 1) {
        $conflictingIds[$cid] = $names;
    }
}
if ($conflictingIds !== []) {
    fail($errors, 'CONFLICTING_CITY_ID_NAMES', 'Same city_id mapped to multiple resolved names', [
        'count' => count($conflictingIds),
        'examples' => array_slice($conflictingIds, 0, 5, true),
    ]);
}
// Also: CA present more than once with different city_ids (covered by dup CA, but check)
$checks['2_no_duplicate_city_mappings'] = [
    'pass' => $conflictingIds === [] && $dupCas === [],
    'city_ids_with_conflicting_names' => count($conflictingIds),
    'distinct_proposed_city_ids' => count($idToNames),
];

// -------------------------------------------------------------------------
// 3. Every proposed city_id exists in production cities
// -------------------------------------------------------------------------
$missingCityIds = [];
foreach ($dry as $r) {
    $cid = (int) ($r['After city_id'] ?? 0);
    if ($cid <= 0 || ! isset($citiesById[$cid])) {
        $missingCityIds[] = [
            'ca_id' => (int) $r['CA ID'],
            'after_city_id' => $cid,
            'resolved' => $r['Resolved City'] ?? '',
        ];
    }
}
if ($missingCityIds !== []) {
    fail($errors, 'CITY_ID_NOT_IN_PROD_CITIES', 'Proposed city_id missing from production cities export', [
        'count' => count($missingCityIds),
        'examples' => array_slice($missingCityIds, 0, 10),
    ]);
}
$checks['3_city_id_exists_in_prod_cities'] = [
    'pass' => $missingCityIds === [],
    'checked' => count($dry),
    'missing' => count($missingCityIds),
];

// -------------------------------------------------------------------------
// 4–6. OCR evidence, no guessing, no locality-as-city
// -------------------------------------------------------------------------
$noEvidence = [];
$guessed = [];
$localityAsCity = [];
$nameMismatch = [];
$notInDetail = [];
$badDecision = [];
$resolvedNotInCities = [];
$cityIdNameMismatch = [];

foreach ($dry as $r) {
    $ca = (int) $r['CA ID'];
    $cid = (int) ($r['After city_id'] ?? 0);
    $resolved = trim((string) ($r['Resolved City'] ?? ''));
    $decision = (string) ($r['Decision'] ?? '');
    $evidence = trim((string) ($r['Evidence Sources'] ?? ''));
    $status = (string) ($r['Status'] ?? '');
    $category = (string) ($r['Category'] ?? '');
    $applied = (string) ($r['Applied'] ?? '');

    if ($category !== 'A') {
        fail($errors, 'NON_CATEGORY_A', 'Dry-run row is not Category A', ['ca_id' => $ca, 'category' => $category]);
    }
    if ($applied !== 'no') {
        fail($errors, 'APPLIED_FLAG_SET', 'Dry-run row has Applied!=no', ['ca_id' => $ca, 'applied' => $applied]);
    }
    if ($status !== 'would_update') {
        fail($errors, 'UNEXPECTED_STATUS', 'Dry-run status is not would_update', ['ca_id' => $ca, 'status' => $status]);
    }

    if ($evidence === '') {
        $noEvidence[] = $ca;
    }

    if (! in_array($decision, $recoverable, true)) {
        $badDecision[] = ['ca_id' => $ca, 'decision' => $decision];
        $guessed[] = $ca;
    }

    // Proposed city must uniquely exist in cities master (not a locality).
    $rk = $audit->normKey($resolved);
    if ($rk === '' || ! array_key_exists($rk, $citiesByNorm) || $citiesByNorm[$rk] === null) {
        $resolvedNotInCities[] = ['ca_id' => $ca, 'resolved' => $resolved];
    } elseif ((int) $citiesByNorm[$rk] !== $cid) {
        $cityIdNameMismatch[] = [
            'ca_id' => $ca,
            'resolved' => $resolved,
            'after_city_id' => $cid,
            'expected_city_id' => $citiesByNorm[$rk],
        ];
    }

    // Forbidden locality shape must not be the proposed city.
    if ($resolved !== '' && $resolver->isForbiddenLocalityShape($resolved)) {
        $localityAsCity[] = ['ca_id' => $ca, 'resolved' => $resolved];
    }
    // sanitizeCity nulls localities
    if ($resolver->sanitizeCity($resolved) === null) {
        $localityAsCity[] = ['ca_id' => $ca, 'resolved' => $resolved, 'via' => 'sanitizeCity'];
    }

    // Cross-check classification detail (source of OCR evidence)
    $d = $detailByCa[$ca] ?? null;
    if ($d === null) {
        $notInDetail[] = $ca;
    } else {
        $dResolved = trim((string) ($d['Resolved City'] ?? ''));
        $dOcr = trim((string) ($d['OCR City'] ?? ''));
        $dHeading = trim((string) ($d['Heading City'] ?? ''));
        $dEvidence = trim((string) ($d['Evidence Sources'] ?? ''));
        $dDecision = (string) ($d['Decision'] ?? '');

        if ($dEvidence === '') {
            $noEvidence[] = $ca;
        }
        // Proposed city must appear in OCR-derived fields from classification
        $candidates = array_filter([
            $audit->normKey($dResolved),
            $audit->normKey($dOcr),
            $audit->normKey($dHeading),
        ]);
        if ($rk !== '' && ! in_array($rk, $candidates, true)) {
            // Heading/sibling recovery: OCR City in dry-run equals resolved; detail should match
            $nameMismatch[] = [
                'ca_id' => $ca,
                'dry_resolved' => $resolved,
                'detail_resolved' => $dResolved,
                'detail_ocr' => $dOcr,
                'detail_heading' => $dHeading,
            ];
        }
        if ($dDecision !== $decision) {
            $guessed[] = $ca;
            $badDecision[] = ['ca_id' => $ca, 'dry' => $decision, 'detail' => $dDecision];
        }
    }
}

$localityAsCity = array_values(array_unique($localityAsCity, SORT_REGULAR));
$noEvidence = array_values(array_unique($noEvidence));
$guessed = array_values(array_unique($guessed));

if ($noEvidence !== []) {
    fail($errors, 'NO_OCR_EVIDENCE', 'Rows missing Evidence Sources', [
        'count' => count($noEvidence),
        'examples' => array_slice($noEvidence, 0, 10),
    ]);
}
if ($guessed !== [] || $badDecision !== []) {
    fail($errors, 'GUESSED_OR_BAD_DECISION', 'Non-recoverable or inconsistent decision', [
        'guessed_count' => count($guessed),
        'bad_decision_count' => count($badDecision),
        'examples' => array_slice($badDecision, 0, 10),
    ]);
}
if ($localityAsCity !== []) {
    fail($errors, 'LOCALITY_AS_CITY', 'Proposed city is a locality/forbidden shape', [
        'count' => count($localityAsCity),
        'examples' => array_slice($localityAsCity, 0, 10),
    ]);
}
if ($resolvedNotInCities !== []) {
    fail($errors, 'RESOLVED_NOT_IN_CITIES', 'Resolved City not uniquely in production cities', [
        'count' => count($resolvedNotInCities),
        'examples' => array_slice($resolvedNotInCities, 0, 10),
    ]);
}
if ($cityIdNameMismatch !== []) {
    fail($errors, 'CITY_ID_NAME_MISMATCH', 'After city_id does not match Resolved City in cities table', [
        'count' => count($cityIdNameMismatch),
        'examples' => array_slice($cityIdNameMismatch, 0, 10),
    ]);
}
if ($notInDetail !== []) {
    fail($errors, 'NOT_IN_CLASSIFICATION_DETAIL', 'Dry-run CA ID not in Category A classification detail', [
        'count' => count($notInDetail),
        'examples' => array_slice($notInDetail, 0, 10),
    ]);
}
if ($nameMismatch !== []) {
    fail($errors, 'RESOLVED_NOT_FROM_OCR_DETAIL', 'Resolved city not present in classification OCR fields', [
        'count' => count($nameMismatch),
        'examples' => array_slice($nameMismatch, 0, 10),
    ]);
}

$checks['4_proposed_city_from_ocr_evidence'] = [
    'pass' => $noEvidence === [] && $notInDetail === [] && $nameMismatch === [],
    'missing_evidence' => count($noEvidence),
    'not_in_detail' => count($notInDetail),
    'name_mismatch' => count($nameMismatch),
];
$checks['5_no_guessed_data'] = [
    'pass' => $guessed === [] && $badDecision === [],
    'bad_decisions' => count($badDecision),
];
$checks['6_no_locality_as_city'] = [
    'pass' => $localityAsCity === [] && $resolvedNotInCities === [],
    'locality_as_city' => count($localityAsCity),
    'resolved_not_in_cities' => count($resolvedNotInCities),
];

// -------------------------------------------------------------------------
// 7. No overwrite of existing valid city_id
// -------------------------------------------------------------------------
$overwrite = [];
foreach ($dry as $r) {
    $before = trim((string) ($r['Before city_id'] ?? ''));
    if ($before !== '' && $before !== '0') {
        $overwrite[] = ['ca_id' => (int) $r['CA ID'], 'before' => $before];
    }
}
if ($overwrite !== []) {
    fail($errors, 'WOULD_OVERWRITE_CITY', 'Dry-run proposes overwrite of existing city_id', [
        'count' => count($overwrite),
        'examples' => array_slice($overwrite, 0, 10),
    ]);
}
$checks['7_no_overwrite_existing_city_id'] = [
    'pass' => $overwrite === [],
    'rows_with_before_city_id' => count($overwrite),
];

// -------------------------------------------------------------------------
// 8. Randomly validate >= 50 records against stored OCR evidence
// -------------------------------------------------------------------------
$sampleSize = min(50, count($dry));
mt_srand(20260725); // reproducible sample
$indexes = range(0, count($dry) - 1);
shuffle($indexes);
$sampleIdx = array_slice($indexes, 0, $sampleSize);

$sampleResults = [];
$sampleErrors = 0;

foreach ($sampleIdx as $i) {
    $r = $dry[$i];
    $ca = (int) $r['CA ID'];
    $d = $detailByCa[$ca] ?? null;
    $localFirmId = $d ? (int) ($d['Local Firm ID'] ?? 0) : 0;
    $localDocId = $d ? (int) ($d['Local Doc ID'] ?? 0) : 0;
    $resolved = trim((string) ($r['Resolved City'] ?? ''));
    $rk = $audit->normKey($resolved);
    $ok = true;
    $notes = [];

    if (! $d) {
        $ok = false;
        $notes[] = 'missing_detail';
    }

    $firm = $localFirmId > 0
        ? DB::table('ocr_parsed_firms')->where('id', $localFirmId)->first()
        : null;

    $foundInOcr = false;
    $sourcesHit = [];

    if ($firm) {
        $firmCity = $audit->normKey((string) ($firm->city ?? ''));
        if ($firmCity !== '' && $firmCity === $rk) {
            $foundInOcr = true;
            $sourcesHit[] = 'ocr_parsed_firms.city';
        }
        // Sibling section city on same page
        if (! $foundInOcr && $localDocId > 0 && (int) ($firm->page_number ?? 0) > 0) {
            $sib = DB::table('ocr_parsed_firms')
                ->where('ocr_document_id', $localDocId)
                ->where('page_number', (int) $firm->page_number)
                ->whereNotNull('city')
                ->where('city', '!=', '')
                ->limit(200)
                ->pluck('city');
            foreach ($sib as $sc) {
                if ($audit->normKey((string) $sc) === $rk) {
                    $foundInOcr = true;
                    $sourcesHit[] = 'sibling_ocr_parsed_firms.city';
                    break;
                }
            }
        }
    }

    // Document extracted_text
    if (! $foundInOcr && $localDocId > 0) {
        $text = (string) (DB::table('ocr_documents')->where('id', $localDocId)->value('extracted_text') ?? '');
        if ($text !== '' && $resolved !== '') {
            if (str_contains(mb_strtoupper($text), mb_strtoupper($resolved))) {
                $foundInOcr = true;
                $sourcesHit[] = 'ocr_documents.extracted_text';
            }
        }
    }

    // Classification heading/resolved already OCR-derived
    if (! $foundInOcr && $d) {
        foreach (['Resolved City', 'OCR City', 'Heading City'] as $col) {
            if ($audit->normKey((string) ($d[$col] ?? '')) === $rk && $rk !== '') {
                $foundInOcr = true;
                $sourcesHit[] = 'classification:'.$col;
                break;
            }
        }
    }

    if (! $foundInOcr) {
        $ok = false;
        $notes[] = 'resolved_city_not_found_in_stored_ocr';
        $sampleErrors++;
        fail($errors, 'SAMPLE_OCR_MISMATCH', 'Sampled row resolved city not found in stored OCR', [
            'ca_id' => $ca,
            'resolved' => $resolved,
            'local_firm_id' => $localFirmId,
            'local_doc_id' => $localDocId,
        ]);
    }

    // Re-resolve city_id
    $hit = $audit->tryResolveToCityId($resolved, $citiesByNorm, []);
    if ($hit['status'] !== 'unique' || (int) ($hit['city_id'] ?? 0) !== (int) $r['After city_id']) {
        $ok = false;
        $notes[] = 'city_id_reresolve_failed';
        $sampleErrors++;
        fail($errors, 'SAMPLE_CITY_ID_RERESOLVE', 'Sample city_id does not re-resolve uniquely', [
            'ca_id' => $ca,
            'hit' => $hit,
            'after_city_id' => $r['After city_id'],
        ]);
    }

    $sampleResults[] = [
        'ca_id' => $ca,
        'firm_name' => $r['Firm Name'] ?? '',
        'resolved_city' => $resolved,
        'after_city_id' => (int) $r['After city_id'],
        'decision' => $r['Decision'] ?? '',
        'local_firm_id' => $localFirmId,
        'local_doc_id' => $localDocId,
        'sources_hit' => $sourcesHit,
        'ok' => $ok,
        'notes' => $notes,
    ];
}

$checks['8_random_sample_ocr_validation'] = [
    'pass' => $sampleErrors === 0,
    'sample_size' => $sampleSize,
    'sample_errors' => $sampleErrors,
    'seed' => 20260725,
];

// Deduplicate error codes count
$errorCount = count($errors);
$pass = $errorCount === 0;

$report = [
    'validated_at' => date('c'),
    'dry_run_csv' => $dryPath,
    'classification_detail_csv' => $detailPath,
    'prod_cities_csv' => $citiesPath,
    'rows_validated' => count($dry),
    'error_count' => $errorCount,
    'safe_to_apply' => $pass,
    'checks' => $checks,
    'errors' => $errors,
    'sample_results' => $sampleResults,
    'decision_breakdown' => array_count_values(array_map(static fn ($r) => $r['Decision'], $dry)),
];

file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT));

$md = [];
$md[] = '# Category A Pre-Apply Validation Report';
$md[] = '';
$md[] = 'Validated at: '.$report['validated_at'];
$md[] = 'Dry-run CSV rows: **'.count($dry).'**';
$md[] = 'Errors: **'.$errorCount.'**';
$md[] = '';
$md[] = '| Check | Result |';
$md[] = '|-------|--------|';
foreach ($checks as $name => $info) {
    $md[] = '| '.$name.' | '.(($info['pass'] ?? false) ? 'PASS' : 'FAIL').' |';
}
$md[] = '';
if ($pass) {
    $md[] = '## Verdict';
    $md[] = '';
    $md[] = 'The Category A repair is safe to apply.';
} else {
    $md[] = '## Verdict';
    $md[] = '';
    $md[] = 'STOP — validation errors found. Do not apply.';
    $md[] = '';
    $md[] = '### Errors';
    foreach ($errors as $e) {
        $md[] = '- `'.$e['code'].'`: '.$e['message'];
    }
}
$md[] = '';
$md[] = '### Sample validation ('.$sampleSize.' rows)';
$md[] = '';
$okN = count(array_filter($sampleResults, static fn ($s) => $s['ok']));
$md[] = "Passed: {$okN}/{$sampleSize}";
$md[] = '';
$md[] = '### Decision breakdown';
foreach ($report['decision_breakdown'] as $dec => $n) {
    $md[] = "- {$dec}: {$n}";
}

file_put_contents($reportMd, implode("\n", $md)."\n");

echo json_encode([
    'error_count' => $errorCount,
    'safe_to_apply' => $pass,
    'checks' => array_map(static fn ($c) => $c['pass'] ?? false, $checks),
    'sample_ok' => $sampleSize - $sampleErrors,
    'sample_size' => $sampleSize,
    'report_json' => $reportPath,
    'report_md' => $reportMd,
], JSON_PRETTY_PRINT)."\n";

if (! $pass) {
    echo "STOP\n";
    foreach (array_slice($errors, 0, 20) as $e) {
        echo $e['code'].': '.$e['message'].' '.json_encode($e['meta'])."\n";
    }
    exit(1);
}

echo "The Category A repair is safe to apply.\n";
exit(0);
