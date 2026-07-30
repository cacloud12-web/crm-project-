<?php

/**
 * On-server READ-ONLY export — NO JOINS.
 * ca_masters with source_id=1, missing city, null OCR link columns.
 *
 * Usage (on Hostinger):
 *   /opt/alt/php83/usr/bin/php -d memory_limit=512M \
 *     /tmp/export-no-ocr-link-missing-city-onserver.php /path/to/public_html
 */

declare(strict_types=1);

$root = $argv[1] ?? getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

@mkdir($root.'/storage/app/audits', 0755, true);
$path = $root.'/storage/app/audits/no-ocr-link-missing-city.csv';

$fh = fopen($path, 'wb');
if ($fh === false) {
    fwrite(STDERR, "Cannot open {$path}\n");
    exit(1);
}

fputcsv($fh, [
    'ca_id',
    'firm_name',
    'ca_name',
    'city_id',
    'source_id',
    'source_ocr_row_id',
    'source_ocr_document_id',
    'ocr_city_text',
]);

$n = 0;
$query = DB::table('ca_masters')
    ->where(function ($q) {
        $q->whereNull('city_id')->orWhere('city_id', 0);
    })
    ->where('source_id', 1)
    ->whereNull('source_ocr_row_id');

if (Schema::hasColumn('ca_masters', 'source_ocr_document_id')) {
    $query->whereNull('source_ocr_document_id');
}
if (Schema::hasColumn('ca_masters', 'deleted_at')) {
    $query->whereNull('deleted_at');
}

$select = ['ca_id', 'firm_name', 'ca_name', 'city_id', 'source_id', 'source_ocr_row_id'];
if (Schema::hasColumn('ca_masters', 'source_ocr_document_id')) {
    $select[] = 'source_ocr_document_id';
}
if (Schema::hasColumn('ca_masters', 'ocr_city_text')) {
    $select[] = 'ocr_city_text';
}

$query->orderBy('ca_id')
    ->select($select)
    ->chunkById(500, function ($rows) use ($fh, &$n) {
        foreach ($rows as $r) {
            fputcsv($fh, [
                $r->ca_id,
                $r->firm_name,
                $r->ca_name ?? '',
                $r->city_id,
                $r->source_id,
                $r->source_ocr_row_id,
                $r->source_ocr_document_id ?? '',
                $r->ocr_city_text ?? '',
            ]);
            $n++;
        }
        echo "exported {$n}\n";
    }, 'ca_id');

fclose($fh);
echo "DONE rows={$n} file={$path}\n";
