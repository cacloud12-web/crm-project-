<?php

/**
 * Export local ocr_parsed_firms to CSV for offline matching (read-only).
 * Usage: php scripts/export-local-ocr-firms-csv.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$out = $root.'/storage/app/audits/ocr-parsed-firms-offline.csv';
@mkdir(dirname($out), 0755, true);
$fh = fopen($out, 'wb');
fputcsv($fh, ['id', 'firm_name', 'city', 'ocr_document_id', 'page_number']);
$n = 0;
foreach (DB::table('ocr_parsed_firms')->select(['id', 'firm_name', 'city', 'ocr_document_id', 'page_number'])->orderBy('id')->cursor() as $r) {
    fputcsv($fh, [$r->id, $r->firm_name, $r->city, $r->ocr_document_id, $r->page_number]);
    $n++;
}
fclose($fh);
echo "DONE firms={$n} file={$out}\n";
