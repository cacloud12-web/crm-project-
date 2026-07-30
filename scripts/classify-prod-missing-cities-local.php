<?php

/**
 * READ-ONLY classification of production OCR-linked missing-city masters.
 *
 * Inputs:
 *  - storage/app/audits/prod-ocr-linked-missing-masters.csv
 *  - storage/app/audits/prod-cities.csv
 *  - local ocr_parsed_firms / ocr_parsed_members / ocr_documents
 *
 * No production access. No DB writes. No repair.
 *
 * Categories (exclusive):
 *  A — city in OCR, uniquely mappable now (auto-recoverable)
 *  B — city evidence in OCR but parser dropped / stored locality only
 *  C — city text present but mapping rejected (cities gap / ambiguous)
 *  D — no city evidence in stored OCR
 *  E — broken OCR linkage (cannot locate matching local OCR firm/doc)
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Ocr\OcrMissingCityAuditService;
use Illuminate\Support\Facades\DB;

/** Firm-name join key (case-insensitive, whitespace-collapsed). */
function firmKey(?string $s): string
{
    $s = trim((string) $s);
    if ($s === '') {
        return '';
    }

    return strtoupper(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

$mastersPath = $root.'/storage/app/audits/prod-ocr-linked-missing-masters.csv';
$citiesPath = $root.'/storage/app/audits/prod-cities.csv';
$outPath = $root.'/storage/app/audits/ocr-linked-missing-cities-categories-prod.csv';
$detailPath = $root.'/storage/app/audits/ocr-linked-missing-cities-categories-prod-detail.csv';
$summaryPath = $root.'/storage/app/audits/ocr-linked-missing-cities-categories-prod-summary.json';

if (! is_file($mastersPath) || ! is_file($citiesPath)) {
    fwrite(STDERR, "Missing production export CSVs\n");
    exit(1);
}

$audit = new OcrMissingCityAuditService;

echo "Loading production cities index (audit normKey)...\n";
$cityIndex = [];
$fh = fopen($citiesPath, 'rb');
$header = fgetcsv($fh);
while (($row = fgetcsv($fh)) !== false) {
    $map = array_combine($header ?: [], $row);
    if ($map === false) {
        continue;
    }
    $name = trim((string) ($map['city_name'] ?? ''));
    $id = (int) ($map['city_id'] ?? 0);
    if ($name === '' || $id <= 0) {
        continue;
    }
    // MUST match OcrMissingCityAuditService::normKey (mb_strtolower).
    $k = $audit->normKey($name);
    if ($k === '') {
        continue;
    }
    if (! array_key_exists($k, $cityIndex)) {
        $cityIndex[$k] = $id;
    } elseif ($cityIndex[$k] !== $id) {
        $cityIndex[$k] = null;
    }
}
fclose($fh);
echo 'cities_indexed='.count($cityIndex)."\n";
echo 'ahmedabad='.json_encode($cityIndex[$audit->normKey('Ahmedabad')] ?? null)."\n";

$aliases = $audit->localityAliases();

echo "Indexing local OCR firms...\n";
$localByName = [];
foreach (DB::table('ocr_parsed_firms')->select([
    'id', 'firm_name', 'city', 'address', 'ocr_document_id', 'page_number', 'column_number',
])->cursor() as $f) {
    $k = firmKey($f->firm_name);
    if ($k === '') {
        continue;
    }
    $localByName[$k][] = $f;
}
echo 'firm_name_keys='.count($localByName)."\n";

// Deterministic prod_document_id → local_document_id from firm-name votes.
echo "Deriving document map...\n";
$namesByProdDoc = [];
$mh = fopen($mastersPath, 'rb');
$mHeader = fgetcsv($mh);
$masters = [];
while (($row = fgetcsv($mh)) !== false) {
    $map = array_combine($mHeader ?: [], $row);
    if ($map === false) {
        continue;
    }
    $masters[] = $map;
    $d = (int) ($map['source_ocr_document_id'] ?? 0);
    $namesByProdDoc[$d][] = firmKey($map['firm_name'] ?? '');
}
fclose($mh);

$prodToLocalDoc = [];
foreach ($namesByProdDoc as $prodDoc => $names) {
    $votes = [];
    $checked = 0;
    foreach (array_unique($names) as $name) {
        if ($name === '' || ! isset($localByName[$name])) {
            continue;
        }
        foreach ($localByName[$name] as $hit) {
            $votes[(int) $hit->ocr_document_id] = ($votes[(int) $hit->ocr_document_id] ?? 0) + 1;
        }
        if (++$checked >= 100) {
            break;
        }
    }
    arsort($votes);
    $prodToLocalDoc[(int) $prodDoc] = $votes !== [] ? (int) array_key_first($votes) : 0;
}
echo 'doc_map='.json_encode($prodToLocalDoc)."\n";

/**
 * Resolve local OCR firm without guessing across distinct cities.
 *
 * @param  array<string, string>  $map
 * @return array{firm:?object, local_doc:int, link:string}
 */
function resolveLocalFirm(array $map, array $prodToLocalDoc, array $localByName): array
{
    $k = firmKey($map['firm_name'] ?? '');
    $city = firmKey($map['ocr_city_text'] ?? '');
    $prodDoc = (int) ($map['source_ocr_document_id'] ?? 0);
    $localDoc = $prodToLocalDoc[$prodDoc] ?? 0;
    $hits = $localByName[$k] ?? [];

    if ($hits === []) {
        return ['firm' => null, 'local_doc' => $localDoc, 'link' => 'broken_no_name_match'];
    }

    $inDoc = $localDoc > 0
        ? array_values(array_filter($hits, static fn ($f) => (int) $f->ocr_document_id === $localDoc))
        : [];

    if (count($inDoc) === 1) {
        return ['firm' => $inDoc[0], 'local_doc' => $localDoc, 'link' => 'doc_name_unique'];
    }

    if (count($inDoc) > 1) {
        if ($city !== '') {
            $cHits = array_values(array_filter($inDoc, static fn ($f) => firmKey($f->city) === $city));
            if (count($cHits) === 1) {
                return ['firm' => $cHits[0], 'local_doc' => $localDoc, 'link' => 'doc_name_city'];
            }
        }
        $cities = array_values(array_unique(array_filter(array_map(
            static fn ($f) => firmKey($f->city),
            $inDoc
        ))));
        if (count($cities) === 1) {
            // All duplicates agree on the same city — safe.
            return ['firm' => $inDoc[0], 'local_doc' => $localDoc, 'link' => 'doc_name_same_city'];
        }

        // Ambiguous firm rows; keep document context only.
        return ['firm' => null, 'local_doc' => $localDoc, 'link' => 'doc_name_ambiguous_use_document'];
    }

    // Name exists but not in mapped doc.
    if ($city !== '') {
        $cHits = array_values(array_filter($hits, static fn ($f) => firmKey($f->city) === $city));
        if (count($cHits) === 1) {
            return ['firm' => $cHits[0], 'local_doc' => (int) $cHits[0]->ocr_document_id, 'link' => 'global_name_city'];
        }
    }
    if (count($hits) === 1) {
        return ['firm' => $hits[0], 'local_doc' => (int) $hits[0]->ocr_document_id, 'link' => 'global_name_unique'];
    }

    return ['firm' => null, 'local_doc' => $localDoc, 'link' => 'broken_unresolvable'];
}

$userCounts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];
$byDecision = [];
$byStage = [];
$byLink = [];
$byEvidence = [];

$out = fopen($outPath, 'wb');
$detail = fopen($detailPath, 'wb');
fputcsv($out, ['CA ID', 'Firm Name', 'OCR City', 'Master City', 'Category']);
fputcsv($detail, [
    'CA ID', 'Firm Name', 'OCR City', 'Master City', 'Category',
    'Decision', 'Parser Stage', 'Raw OCR City', 'Heading City', 'Resolved City',
    'OCR Locality', 'Evidence Sources', 'Link Method', 'Local Firm ID', 'Local Doc ID',
    'Prod source_ocr_row_id', 'Prod source_ocr_document_id', 'Failure Reason',
]);

$n = 0;
foreach ($masters as $map) {
    $n++;
    $resolved = resolveLocalFirm($map, $prodToLocalDoc, $localByName);
    $link = $resolved['link'];
    $byLink[$link] = ($byLink[$link] ?? 0) + 1;

    $localFirm = $resolved['firm'];
    $localDoc = (int) $resolved['local_doc'];

    // Broken linkage: cannot place the master onto local OCR firm or document evidence.
    if ($localFirm === null && ($localDoc <= 0 || str_starts_with($link, 'broken_'))) {
        $userCounts['E']++;
        fputcsv($out, [
            $map['ca_id'] ?? '',
            $map['firm_name'] ?? '',
            $map['ocr_city_text'] ?? '',
            '',
            'E',
        ]);
        fputcsv($detail, [
            $map['ca_id'] ?? '', $map['firm_name'] ?? '', $map['ocr_city_text'] ?? '', '', 'E',
            'broken_ocr_linkage', 'lost_at_ocr_firm_link', '', '', '',
            '', '', $link, '', $localDoc,
            $map['source_ocr_row_id'] ?? '', $map['source_ocr_document_id'] ?? '',
            'cannot_resolve_local_ocr_firm_for_prod_source_ocr_row_id',
        ]);
        continue;
    }

    $master = (object) [
        'ca_id' => (int) $map['ca_id'],
        'firm_name' => (string) ($map['firm_name'] ?? ''),
        'city_id' => null,
        'ocr_city_text' => $map['ocr_city_text'] !== '' ? $map['ocr_city_text'] : null,
        'source_ocr_row_id' => $localFirm ? (int) $localFirm->id : null,
        'source_ocr_document_id' => $localDoc > 0 ? $localDoc : ($localFirm ? (int) $localFirm->ocr_document_id : null),
    ];

    $classified = $audit->classifyMaster($master, $cityIndex, $aliases);
    $decision = (string) ($classified['decision'] ?? '');
    $stage = (string) ($classified['parser_stage'] ?? '');
    $ae = (string) ($classified['ae_class'] ?? '');
    $raw = (string) ($classified['raw_ocr_city'] ?? '');
    $loc = (string) ($classified['ocr_locality'] ?? '');
    $resolvedCity = (string) ($classified['resolved_city'] ?? '');
    $heading = (string) ($classified['heading_city'] ?? '');
    $evidence = (string) ($classified['evidence_sources'] ?? '');
    $ocrCity = $resolvedCity !== '' ? $resolvedCity : ($raw !== '' ? $raw : ($loc !== '' ? $loc : (string) ($map['ocr_city_text'] ?? '')));

    // Map audit outcome → user categories A–E.
    if ($audit->isRecoverableDecision($decision)) {
        $cat = 'A';
    } elseif (in_array($decision, [
        OcrMissingCityAuditService::DECISION_SKIP_CITY_TABLE_GAP,
        OcrMissingCityAuditService::DECISION_SKIP_AMBIGUOUS,
    ], true)) {
        // City/locality string exists but cities master mapping rejected it.
        $cat = 'C';
    } elseif ($decision === OcrMissingCityAuditService::DECISION_SKIP_LOCALITY) {
        // Locality stored; parent city not assigned by parser.
        $cat = 'B';
    } elseif ($decision === OcrMissingCityAuditService::DECISION_SKIP_NO_OCR) {
        $cat = 'D';
    } elseif ($ae === 'C' || (! empty($classified['has_any_place_text']) && $raw === '' && $resolvedCity === '')) {
        // Place evidence in address/heading/siblings but parser did not store firm.city.
        $cat = 'B';
    } elseif ($ae === 'D' || $ae === 'B') {
        $cat = 'C';
    } elseif ($ae === 'E') {
        $cat = 'D';
    } else {
        $cat = 'D';
    }

    $userCounts[$cat]++;
    $byDecision[$decision] = ($byDecision[$decision] ?? 0) + 1;
    $byStage[$stage] = ($byStage[$stage] ?? 0) + 1;
    foreach (explode('|', $evidence) as $evPart) {
        $evPart = trim($evPart);
        if ($evPart !== '') {
            $byEvidence[$evPart] = ($byEvidence[$evPart] ?? 0) + 1;
        }
    }

    fputcsv($out, [
        $map['ca_id'] ?? '',
        $map['firm_name'] ?? '',
        $ocrCity,
        '',
        $cat,
    ]);
    fputcsv($detail, [
        $map['ca_id'] ?? '',
        $map['firm_name'] ?? '',
        $ocrCity,
        '',
        $cat,
        $decision,
        $stage,
        $raw,
        $heading,
        $resolvedCity,
        $loc,
        $evidence,
        $link,
        $localFirm->id ?? '',
        $localDoc,
        $map['source_ocr_row_id'] ?? '',
        $map['source_ocr_document_id'] ?? '',
        (string) ($classified['failure_reason'] ?? ''),
    ]);

    if ($n % 1000 === 0) {
        echo "classified {$n}/".count($masters)."\n";
    }
}

fclose($out);
fclose($detail);

arsort($byDecision);
arsort($byStage);
arsort($byLink);

$missingAfterAuto = max(0, $n - $userCounts['A']);
$parserFix = $userCounts['B'];
$manualReview = $userCounts['C'] + $userCounts['D'] + $userCounts['E'];

$summary = [
    'scanned' => $n,
    'categories' => $userCounts,
    'automatically_recoverable' => $userCounts['A'],
    'requiring_parser_fixes' => $parserFix,
    'requiring_manual_review' => $manualReview,
    'estimated_missing_city_after_automatic_recovery' => $missingAfterAuto,
    'doc_map_prod_to_local' => $prodToLocalDoc,
    'link_methods' => $byLink,
    'by_decision' => $byDecision,
    'by_parser_stage' => $byStage,
    'csv' => $outPath,
    'detail_csv' => $detailPath,
];
file_put_contents($summaryPath, json_encode($summary, JSON_PRETTY_PRINT));

echo "======================================\n";
echo "OCR-linked missing city classified: {$n}\n";
echo "Category A: {$userCounts['A']}\n";
echo "Category B: {$userCounts['B']}\n";
echo "Category C: {$userCounts['C']}\n";
echo "Category D: {$userCounts['D']}\n";
echo "Category E: {$userCounts['E']}\n";
echo 'Sum: '.array_sum($userCounts)."\n";
echo "Auto-recoverable (A): {$userCounts['A']}\n";
echo "Parser fixes (B): {$parserFix}\n";
echo "Manual review (C+D+E): {$manualReview}\n";
echo "Missing after auto recovery: {$missingAfterAuto}\n";
echo "CSV: {$outPath}\n";
echo "DONE\n";
