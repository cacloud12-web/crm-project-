<?php
/**
 * READ-ONLY production audit — run on Hostinger only.
 * Trace: ca_masters → source_ocr_row_id → ocr_parsed_firms → members → ocr_documents
 * SELECT only. Writes CSV under storage/app/audits.
 *
 * Categories (exclusive):
 * A = valid city in OCR, uniquely recoverable now
 * B = locality only (needs parser correction)
 * C = city present but mapping rejected (cities table / ambiguous)
 * D = no city in stored OCR
 * E = broken OCR linkage
 */
declare(strict_types=1);

$root = $argv[1] ?? getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Ocr\OcrCityResolverService;
use App\Services\Ocr\OcrMissingCityAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$export = $root.'/storage/app/audits/ocr-linked-missing-cities-categories-prod.csv';
$pipeline = $root.'/storage/app/audits/ocr-linked-missing-cities-AE-prod.csv';
$countsPath = $root.'/storage/app/audits/ocr-linked-missing-cities-categories-prod.counts.json';

echo "READ-ONLY audit starting...\n";

if (! class_exists(OcrMissingCityAuditService::class)) {
    fwrite(STDERR, "OcrMissingCityAuditService not on server — using inline classifier.\n");
    // Inline path below still works with resolver if present.
}

$useService = class_exists(OcrMissingCityAuditService::class);

$userCounts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];
$out = fopen($export, 'wb');
fputcsv($out, ['CA ID', 'Firm Name', 'OCR City', 'Master City', 'Category']);

if ($useService) {
    $audit = new OcrMissingCityAuditService;
    $report = $audit->audit([
        'ocr_linked_only' => true,
        'include_deleted' => false,
        'export' => $pipeline,
    ]);
    $in = fopen($pipeline, 'rb');
    $header = fgetcsv($in);
    while (($row = fgetcsv($in)) !== false) {
        $map = array_combine($header, $row);
        $ae = (string) ($map['AE Class'] ?? '');
        $decision = (string) ($map['Decision'] ?? '');
        $stage = (string) ($map['Parser Stage'] ?? '');
        $raw = (string) ($map['Raw OCR City'] ?? '');
        $loc = (string) ($map['OCR Locality'] ?? '');
        $resolved = (string) ($map['Resolved City'] ?? '');
        $ocrCity = $resolved !== '' ? $resolved : ($raw !== '' ? $raw : $loc);

        if ($stage === 'lost_at_ocr_firm_link' || str_contains((string) ($map['Failure Reason'] ?? ''), 'no_source_ocr_row')) {
            $cat = 'E';
        } elseif (in_array($decision, [
            'apply_exact_unique', 'apply_locality_alias', 'apply_from_address_context',
            'apply_from_page_heading', 'apply_from_section_sibling',
        ], true) || $ae === 'A') {
            $cat = 'A';
        } elseif ($decision === 'skip_locality_only' || ($loc !== '' && $ae === 'C')) {
            $cat = 'B';
        } elseif (in_array($decision, ['skip_city_not_in_cities_table', 'skip_ambiguous'], true) || $ae === 'D' || $ae === 'B') {
            $cat = 'C';
        } elseif ($decision === 'skip_no_city_information' || $ae === 'E') {
            $cat = 'D';
        } elseif ($ae === 'C') {
            $cat = 'B';
        } else {
            $cat = 'D';
        }
        $userCounts[$cat]++;
        fputcsv($out, [$map['Master CA ID'] ?? '', $map['Firm Name'] ?? '', $ocrCity, '', $cat]);
    }
    fclose($in);
    $serviceTotals = $report['totals'];
} else {
    // Minimal inline: requires OcrCityResolverService at least.
    $resolver = new OcrCityResolverService;
    $cityIndex = [];
    $idCol = Schema::hasColumn('cities', 'city_id') ? 'city_id' : 'id';
    $nameCol = Schema::hasColumn('cities', 'city_name') ? 'city_name' : 'name';
    foreach (DB::table('cities')->select([$idCol.' as id', $nameCol.' as name'])->cursor() as $c) {
        $k = strtoupper(preg_replace('/\s+/', ' ', trim((string) $c->name)));
        if ($k === '') {
            continue;
        }
        if (! isset($cityIndex[$k])) {
            $cityIndex[$k] = (int) $c->id;
        } else {
            $cityIndex[$k] = null; // ambiguous
        }
    }
    $aliases = (array) (config('ocr_locality_aliases.aliases') ?? []);

    $q = DB::table('ca_masters')
        ->whereNull('deleted_at')
        ->where(function ($w) {
            $w->whereNull('city_id')->orWhere('city_id', 0);
        })
        ->whereNotNull('source_ocr_row_id')
        ->orderBy('ca_id')
        ->select(['ca_id', 'firm_name', 'city_id', 'ocr_city_text', 'source_ocr_row_id', 'source_ocr_document_id']);

    $n = 0;
    $q->chunkById(200, function ($rows) use (&$n, &$userCounts, $out, $resolver, $cityIndex, $aliases) {
        foreach ($rows as $m) {
            $n++;
            $firm = DB::table('ocr_parsed_firms')->where('id', (int) $m->source_ocr_row_id)->first();
            if (! $firm) {
                $userCounts['E']++;
                fputcsv($out, [$m->ca_id, $m->firm_name, '', '', 'E']);
                continue;
            }
            $raw = trim((string) ($firm->city ?? ''));
            $addr = trim((string) ($firm->address ?? ''));
            $ocrText = trim((string) ($m->ocr_city_text ?? ''));
            $candidate = $raw !== '' ? $raw : $ocrText;
            $ocrCity = $candidate;

            $resolve = function (string $text) use ($cityIndex, $aliases, $resolver): array {
                $text = trim($text);
                if ($text === '') {
                    return ['status' => 'none'];
                }
                if (method_exists($resolver, 'sanitizeCity')) {
                    $san = $resolver->sanitizeCity($text);
                    if ($san === null || $san === '') {
                        // may be locality
                    }
                }
                $k = strtoupper(preg_replace('/\s+/', ' ', $text));
                if (isset($aliases[$k]) || isset($aliases[strtolower($text)])) {
                    $alias = $aliases[$k] ?? $aliases[strtolower($text)];
                    if (is_numeric($alias)) {
                        return ['status' => 'unique', 'display' => $text, 'via' => 'alias'];
                    }
                    $ak = strtoupper(preg_replace('/\s+/', ' ', (string) $alias));
                    if (array_key_exists($ak, $cityIndex) && $cityIndex[$ak] !== null) {
                        return ['status' => 'unique', 'display' => (string) $alias, 'via' => 'alias'];
                    }
                }
                if (! array_key_exists($k, $cityIndex)) {
                    return ['status' => 'none'];
                }
                if ($cityIndex[$k] === null) {
                    return ['status' => 'ambiguous'];
                }

                return ['status' => 'unique', 'display' => $text, 'via' => 'exact'];
            };

            $isLocality = $candidate !== '' && (
                (method_exists($resolver, 'isForbiddenLocalityShape') && $resolver->isForbiddenLocalityShape($candidate))
                || (bool) preg_match('/\b(NAGAR|COLONY|VIHAR|SOCIETY|ROAD|STREET|LANE|MARG|PUR|BAGH)\b/i', $candidate)
            );

            // Try candidate, address extract, sibling later skipped for speed in fallback.
            $hit = $candidate !== '' ? $resolve($candidate) : ['status' => 'none'];
            if ($hit['status'] === 'none' && $addr !== '' && method_exists($resolver, 'extractCityFromAddressLine')) {
                $ex = $resolver->extractCityFromAddressLine($addr);
                if (is_array($ex) && ! empty($ex['canonical_city'])) {
                    $ocrCity = (string) $ex['canonical_city'];
                    $hit = $resolve($ocrCity);
                }
            }

            if ($hit['status'] === 'unique') {
                $cat = 'A';
                $ocrCity = (string) $hit['display'];
            } elseif ($hit['status'] === 'ambiguous') {
                $cat = 'C';
            } elseif ($candidate !== '' && $isLocality) {
                $cat = 'B';
            } elseif ($candidate !== '') {
                $cat = 'C'; // present but not in cities table
            } else {
                // Check extracted_text for any city token (light).
                $docId = (int) ($firm->ocr_document_id ?? $m->source_ocr_document_id ?? 0);
                $found = false;
                if ($docId > 0) {
                    $text = (string) (DB::table('ocr_documents')->where('id', $docId)->value('extracted_text') ?? '');
                    if ($text !== '') {
                        foreach ($cityIndex as $name => $id) {
                            if ($id === null) {
                                continue;
                            }
                            if ($name !== '' && str_contains(strtoupper($text), $name)) {
                                // weak presence — treat as recoverable only if unique heading-like; else B/parser
                                $found = true;
                                $ocrCity = $name;
                                break;
                            }
                        }
                    }
                }
                $cat = $found ? 'B' : 'D';
            }

            $userCounts[$cat]++;
            fputcsv($out, [$m->ca_id, $m->firm_name, $ocrCity, '', $cat]);
            if ($n % 500 === 0) {
                echo "classified {$n}\n";
            }
        }
    }, 'ca_id');
    $serviceTotals = ['missing_cities' => $n];
}

fclose($out);

$sum = array_sum($userCounts);
$payload = [
    'scanned' => $sum,
    'categories' => $userCounts,
    'automatically_recoverable' => $userCounts['A'],
    'service_totals' => $serviceTotals ?? null,
    'export' => $export,
];
file_put_contents($countsPath, json_encode($payload, JSON_PRETTY_PRINT));

echo "======================================\n";
echo "Category A: {$userCounts['A']}\n";
echo "Category B: {$userCounts['B']}\n";
echo "Category C: {$userCounts['C']}\n";
echo "Category D: {$userCounts['D']}\n";
echo "Category E: {$userCounts['E']}\n";
echo "Total: {$sum}\n";
echo "Automatically recoverable: {$userCounts['A']}\n";
echo "CSV: {$export}\n";
echo "COUNTS: {$countsPath}\n";
echo "DONE\n";
