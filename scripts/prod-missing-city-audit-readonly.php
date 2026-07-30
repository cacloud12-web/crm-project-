<?php

/**
 * READ-ONLY production audit of OCR-linked Masters missing city_id.
 * No INSERT/UPDATE/DELETE. No repair. No apply.
 *
 * Usage: php -d memory_limit=2048M scripts/prod-missing-city-audit-readonly.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

$envPath = $root.'/.env';
$envLines = is_file($envPath) ? file($envPath, FILE_IGNORE_NEW_LINES) : [];
$host = null;
$database = null;
$username = null;
$password = null;
foreach ($envLines as $line) {
    $line = trim($line);
    if ($line === '' || ! str_starts_with($line, '#')) {
        continue;
    }
    $line = ltrim(substr($line, 1));
    if (str_starts_with($line, 'DB_HOST=')) {
        $host = trim(substr($line, 8), " \t\"'");
    } elseif (str_starts_with($line, 'DB_DATABASE=')) {
        $database = trim(substr($line, 12), " \t\"'");
    } elseif (str_starts_with($line, 'DB_USERNAME=')) {
        $username = trim(substr($line, 12), " \t\"'");
    } elseif (str_starts_with($line, 'DB_PASSWORD=')) {
        $password = trim(substr($line, 12), " \t\"'");
    }
}

if (! $host || ! $database || ! $username || $password === null) {
    fwrite(STDERR, "Unable to read Hostinger DB_* comments from .env\n");
    exit(1);
}

putenv('DB_CONNECTION=mysql');
putenv('DB_HOST='.$host);
putenv('DB_PORT=3306');
putenv('DB_DATABASE='.$database);
putenv('DB_USERNAME='.$username);
putenv('DB_PASSWORD='.$password);
$_ENV['DB_CONNECTION'] = 'mysql';
$_ENV['DB_HOST'] = $host;
$_ENV['DB_PORT'] = '3306';
$_ENV['DB_DATABASE'] = $database;
$_ENV['DB_USERNAME'] = $username;
$_ENV['DB_PASSWORD'] = $password;
$_SERVER['DB_CONNECTION'] = 'mysql';
$_SERVER['DB_HOST'] = $host;
$_SERVER['DB_PORT'] = '3306';
$_SERVER['DB_DATABASE'] = $database;
$_SERVER['DB_USERNAME'] = $username;
$_SERVER['DB_PASSWORD'] = $password;

$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Ocr\OcrMissingCityAuditService;
use Illuminate\Support\Facades\DB;

echo "READ-ONLY prod missing-city pipeline audit\n";
echo 'host='.$host.' db='.$database."\n";

$total = (int) DB::table('ca_masters')->whereNull('deleted_at')->count();
$missing = (int) DB::table('ca_masters')->whereNull('deleted_at')
    ->where(function ($q) {
        $q->whereNull('city_id')->orWhere('city_id', 0);
    })->count();
$missingOcr = (int) DB::table('ca_masters')->whereNull('deleted_at')
    ->where(function ($q) {
        $q->whereNull('city_id')->orWhere('city_id', 0);
    })->whereNotNull('source_ocr_row_id')->count();

echo "total={$total} missing={$missing} missing_ocr_linked={$missingOcr}\n";
if ($missingOcr !== 10055) {
    echo "WARN: expected 10055 OCR-linked missing; got {$missingOcr}\n";
}

$export = $root.'/storage/app/audits/ocr-linked-missing-cities-AE-prod.csv';
$audit = new OcrMissingCityAuditService;
$report = $audit->audit([
    'limit' => 0,
    'include_deleted' => false,
    'ocr_linked_only' => true,
    'export' => $export,
]);

$t = $report['totals'];
echo "======================================\n";
echo "MISSING CITIES — PIPELINE AUDIT\n";
echo "======================================\n";
echo 'Scanned: '.$t['missing_cities']."\n";
echo 'Recoverable automatically (A): '.$t['recoverable_automatic']."\n";
echo 'Manual review: '.$t['manual_review']."\n";
echo 'Absolutely no city in OCR (E-ish): '.$t['absolutely_no_city_in_ocr']."\n";
foreach (['A', 'B', 'C', 'D', 'E'] as $cls) {
    echo "Class {$cls}: ".($t['by_class'][$cls] ?? 0)."\n";
}
echo 'CSV: '.$report['export_path']."\n";

// Remap to user-facing categories + slim CSV.
// User A = recoverable
// User B = locality only (parser)
// User C = city present but mapping rejected (table gap / ambiguous)
// User D = no city in OCR
// User E = broken OCR linkage
$userCounts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];
$slim = $root.'/storage/app/audits/ocr-linked-missing-cities-categories-prod.csv';
$in = fopen($export, 'rb');
$out = fopen($slim, 'wb');
if ($in === false || $out === false) {
    fwrite(STDERR, "Unable to open CSV for remap\n");
    exit(1);
}
$header = fgetcsv($in);
fputcsv($out, ['CA ID', 'Firm Name', 'OCR City', 'Master City', 'Category']);

$codeA = OcrMissingCityAuditService::CLASS_A;
while (($row = fgetcsv($in)) !== false) {
    $map = array_combine($header ?: [], $row);
    if ($map === false) {
        continue;
    }
    $ae = (string) ($map['AE Class'] ?? '');
    $decision = (string) ($map['Decision'] ?? '');
    $stage = (string) ($map['Parser Stage'] ?? '');
    $rawCity = (string) ($map['Raw OCR City'] ?? '');
    $locality = (string) ($map['OCR Locality'] ?? '');
    $resolved = (string) ($map['Resolved City'] ?? '');
    $ocrCity = $resolved !== '' ? $resolved : ($rawCity !== '' ? $rawCity : $locality);

    if ($stage === 'lost_at_ocr_firm_link' || str_contains((string) ($map['Failure Reason'] ?? ''), 'no_source_ocr_row')) {
        $user = 'E';
    } elseif ($ae === $codeA || in_array($decision, [
        OcrMissingCityAuditService::DECISION_APPLY,
        OcrMissingCityAuditService::DECISION_ALIAS,
        OcrMissingCityAuditService::DECISION_APPLY_ADDRESS,
        OcrMissingCityAuditService::DECISION_APPLY_HEADING,
        OcrMissingCityAuditService::DECISION_APPLY_SECTION,
    ], true)) {
        $user = 'A';
    } elseif ($decision === OcrMissingCityAuditService::DECISION_SKIP_LOCALITY
        || ($locality !== '' && $ae === OcrMissingCityAuditService::CLASS_C)) {
        $user = 'B';
    } elseif ($decision === OcrMissingCityAuditService::DECISION_SKIP_CITY_TABLE_GAP
        || $decision === OcrMissingCityAuditService::DECISION_SKIP_AMBIGUOUS
        || $ae === OcrMissingCityAuditService::CLASS_D
        || $ae === OcrMissingCityAuditService::CLASS_B) {
        $user = 'C';
    } elseif ($decision === OcrMissingCityAuditService::DECISION_SKIP_NO_OCR
        || $ae === OcrMissingCityAuditService::CLASS_E) {
        $user = 'D';
    } elseif ($ae === OcrMissingCityAuditService::CLASS_C) {
        // Place evidence in address/heading but not staging city — treat as locality/parser.
        $user = 'B';
    } else {
        $user = 'D';
    }

    $userCounts[$user]++;
    fputcsv($out, [
        $map['Master CA ID'] ?? '',
        $map['Firm Name'] ?? '',
        $ocrCity,
        '', // Master City always empty for this population
        $user,
    ]);
}
fclose($in);
fclose($out);

echo "======================================\n";
echo "USER CATEGORIES (exclusive)\n";
echo "======================================\n";
foreach ($userCounts as $k => $v) {
    echo "Category {$k}: {$v}\n";
}
echo 'Automatically recoverable (Category A): '.$userCounts['A']."\n";
echo "Slim CSV: {$slim}\n";
echo "DONE\n";
