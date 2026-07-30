<?php

/**
 * MAXIMUM SAFE recovery for remaining missing cities.
 *
 * Recoverable automatically:
 *   D — OCR city text exists but was missing from cities table
 *       → insert city (if safe name) + set city_id on Masters still missing city
 *
 * NOT auto-recovered (exported for review only):
 *   C — locality only
 *   E — no city in OCR
 *   no-OCR-link Masters
 *
 * Usage (on Hostinger app root):
 *   /opt/alt/php83/usr/bin/php scripts/recover-remaining-missing-cities-onserver.php --dry-run
 *   /opt/alt/php83/usr/bin/php scripts/recover-remaining-missing-cities-onserver.php --apply
 */

declare(strict_types=1);

$root = getcwd();
foreach ($argv as $i => $arg) {
    if ($i > 0 && ! str_starts_with($arg, '--') && is_dir($arg)) {
        $root = $arg;
    }
}
$apply = in_array('--apply', $argv, true);
$dryRun = ! $apply;

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CaMaster;
use App\Services\Ocr\OcrCityResolverService;
use App\Services\Ocr\OcrMissingCityAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$auditCsv = $root.'/storage/app/audits/missing-cities-pipeline-audit-prod.csv';
if (! is_file($auditCsv)) {
    fwrite(STDERR, "Audit CSV not found: {$auditCsv}\n");
    exit(1);
}

@mkdir($root.'/storage/app/audits', 0755, true);
$stamp = date('Ymd_His');
$export = $root.'/storage/app/audits/recover-remaining-'.($dryRun ? 'dryrun' : 'apply').'-'.$stamp.'.csv';
$skipExport = $root.'/storage/app/audits/recover-remaining-skipped-'.$stamp.'.csv';

$resolver = new OcrCityResolverService;
$audit = new OcrMissingCityAuditService;
$cityIndex = $audit->buildCityNameIndex();
$aliases = $audit->localityAliases();

$fhIn = fopen($auditCsv, 'rb');
$header = fgetcsv($fhIn);
if ($header === false) {
    fwrite(STDERR, "Empty audit CSV\n");
    exit(1);
}

$out = fopen($export, 'wb');
fputcsv($out, [
    'ca_id', 'firm_name', 'ae_class', 'decision', 'city_name', 'city_id',
    'state_id', 'action', 'applied',
]);
$skipFh = fopen($skipExport, 'wb');
fputcsv($skipFh, ['ca_id', 'firm_name', 'ae_class', 'decision', 'raw_ocr_city', 'reason']);

$counts = [
    'scanned_audit' => 0,
    'class_c' => 0,
    'class_d' => 0,
    'class_e' => 0,
    'other' => 0,
    'd_would_update' => 0,
    'd_updated' => 0,
    'd_cities_created' => 0,
    'd_skipped_unsafe_name' => 0,
    'd_skipped_has_city' => 0,
    'd_skipped_master_missing' => 0,
    'd_skipped_ambiguous' => 0,
    'd_errors' => 0,
];

$createdCityNames = []; // lower => city_id created this run

while (($row = fgetcsv($fhIn)) !== false) {
    $m = array_combine($header, $row);
    if ($m === false) {
        continue;
    }
    $counts['scanned_audit']++;
    $cls = strtoupper(trim((string) ($m['AE Class'] ?? '')));
    $caId = (int) ($m['Master CA ID'] ?? 0);
    $firm = (string) ($m['Firm Name'] ?? '');
    $decision = (string) ($m['Decision'] ?? '');
    $rawCity = trim((string) ($m['Raw OCR City'] ?? ''));
    $resolved = trim((string) ($m['Resolved City'] ?? ''));
    $candidate = $resolved !== '' ? $resolved : $rawCity;

    if ($cls === 'C') {
        $counts['class_c']++;
        fputcsv($skipFh, [$caId, $firm, $cls, $decision, $rawCity, 'locality_only_not_auto']);
        continue;
    }
    if ($cls === 'E') {
        $counts['class_e']++;
        fputcsv($skipFh, [$caId, $firm, $cls, $decision, $rawCity, 'no_city_in_ocr_not_auto']);
        continue;
    }
    if ($cls !== 'D') {
        $counts['other']++;
        continue;
    }
    $counts['class_d']++;

    if ($caId <= 0 || $candidate === '') {
        $counts['d_skipped_unsafe_name']++;
        fputcsv($skipFh, [$caId, $firm, 'D', $decision, $candidate, 'empty_city_candidate']);
        continue;
    }

    // Never promote localities / roads into cities table.
    if ($resolver->isForbiddenLocalityShape($candidate)) {
        $counts['d_skipped_unsafe_name']++;
        fputcsv($skipFh, [$caId, $firm, 'D', $decision, $candidate, 'forbidden_locality_shape']);
        continue;
    }

    $hit = $audit->tryResolveToCityId($candidate, $cityIndex, $aliases);
    if ($hit['status'] === 'ambiguous') {
        $counts['d_skipped_ambiguous']++;
        fputcsv($skipFh, [$caId, $firm, 'D', $decision, $candidate, 'ambiguous_city_name']);
        continue;
    }

    $cityId = null;
    $cityName = $candidate;
    $created = false;

    if ($hit['status'] === 'unique' && ! empty($hit['city_id'])) {
        $cityId = (int) $hit['city_id'];
        $cityName = (string) ($hit['display'] ?? $candidate);
    } else {
        // Create city from OCR label if still missing from table.
        $key = $audit->normKey($candidate);
        if ($key === '' || isset($createdCityNames[$key])) {
            $cityId = $createdCityNames[$key] ?? null;
            $cityName = mb_strtoupper($candidate);
        } else {
            $existing = DB::table('cities')
                ->whereRaw('LOWER(TRIM(city_name)) = ?', [mb_strtolower(trim($candidate))])
                ->value('city_id');
            if ($existing) {
                $cityId = (int) $existing;
                $cityIndex[$key] = $cityId;
                $createdCityNames[$key] = $cityId;
            } else {
                $masterState = DB::table('ca_masters')->where('ca_id', $caId)->value('state_id');
                $stateId = $masterState !== null && (int) $masterState > 0 ? (int) $masterState : null;
                if ($stateId === null) {
                    // Fallback: first active state (required FK-ish column).
                    $stateId = (int) (DB::table('cities')->whereNotNull('state_id')->value('state_id')
                        ?? DB::table('states')->value('state_id')
                        ?? 0);
                }
                if ($stateId <= 0) {
                    $counts['d_errors']++;
                    fputcsv($skipFh, [$caId, $firm, 'D', $decision, $candidate, 'no_state_id_for_city_create']);
                    continue;
                }

                $cityName = mb_strtoupper(trim($candidate));
                if ($dryRun) {
                    $cityId = 900000 + count($createdCityNames) + 1; // synthetic id for dry-run planning only
                    $created = true;
                    $counts['d_cities_created']++;
                    $createdCityNames[$key] = $cityId;
                    $cityIndex[$key] = $cityId;
                } else {
                    $payload = [
                        'city_name' => $cityName,
                        'state_id' => $stateId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    if (Schema::hasColumn('cities', 'is_active')) {
                        $payload['is_active'] = 1;
                    }
                    $cityId = (int) DB::table('cities')->insertGetId($payload);
                    $created = true;
                    $counts['d_cities_created']++;
                    $createdCityNames[$key] = $cityId;
                    $cityIndex[$key] = $cityId;
                }
            }
        }
    }

    if ($cityId === null || $cityId === 0) {
        $counts['d_errors']++;
        fputcsv($skipFh, [$caId, $firm, 'D', $decision, $candidate, 'city_id_unresolved']);
        continue;
    }

    $master = DB::table('ca_masters')->where('ca_id', $caId)->first(['ca_id', 'city_id', 'state_id', 'deleted_at']);
    if (! $master || (Schema::hasColumn('ca_masters', 'deleted_at') && $master->deleted_at !== null)) {
        $counts['d_skipped_master_missing']++;
        fputcsv($skipFh, [$caId, $firm, 'D', $decision, $candidate, 'master_not_found']);
        continue;
    }
    if ($master->city_id !== null && (int) $master->city_id > 0) {
        $counts['d_skipped_has_city']++;
        fputcsv($out, [$caId, $firm, 'D', $decision, $cityName, $master->city_id, $master->state_id, 'skipped_has_city', 'no']);
        continue;
    }

    $counts['d_would_update']++;
    $action = $created ? 'create_city_and_set_city_id' : 'set_city_id';

    if ($dryRun) {
        fputcsv($out, [$caId, $firm, 'D', $decision, $cityName, $cityId > 0 ? $cityId : '', $master->state_id, $action, 'no']);
        continue;
    }

    try {
        DB::transaction(function () use ($caId, $cityId) {
            /** @var CaMaster|null $lead */
            $lead = CaMaster::query()->lockForUpdate()->find($caId);
            if (! $lead) {
                return;
            }
            if ($lead->city_id !== null && (int) $lead->city_id > 0) {
                return;
            }
            $lead->city_id = $cityId;
            $lead->saveQuietly();
        });
        $counts['d_updated']++;
        fputcsv($out, [$caId, $firm, 'D', $decision, $cityName, $cityId, $master->state_id, $action, 'yes']);
    } catch (Throwable $e) {
        $counts['d_errors']++;
        fputcsv($skipFh, [$caId, $firm, 'D', $decision, $candidate, 'error:'.$e->getMessage()]);
    }
}

fclose($fhIn);
fclose($out);
fclose($skipFh);

$missingNow = (int) DB::table('ca_masters')->where(function ($w) {
    $w->whereNull('city_id')->orWhere('city_id', 0);
})->when(Schema::hasColumn('ca_masters', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))->count();

echo ($dryRun ? "DRY-RUN" : "APPLY")." remaining missing-city recovery\n";
echo "Audit scanned: {$counts['scanned_audit']}\n";
echo "Class C (skipped locality): {$counts['class_c']}\n";
echo "Class D (cities-table gap): {$counts['class_d']}\n";
echo "Class E (skipped no OCR city): {$counts['class_e']}\n";
echo "D cities created: {$counts['d_cities_created']}\n";
echo "D would update: {$counts['d_would_update']}\n";
echo "D updated: {$counts['d_updated']}\n";
echo "D skipped unsafe name: {$counts['d_skipped_unsafe_name']}\n";
echo "D skipped ambiguous: {$counts['d_skipped_ambiguous']}\n";
echo "D skipped has city: {$counts['d_skipped_has_city']}\n";
echo "D errors: {$counts['d_errors']}\n";
echo "DB missing city now: {$missingNow}\n";
echo "Export: {$export}\n";
echo "Skipped export: {$skipExport}\n";
echo "\nNOT auto-recovered: Class C + Class E + Masters without OCR link.\n";
if ($dryRun) {
    echo "Apply: php scripts/recover-remaining-missing-cities-onserver.php --apply\n";
}
