<?php

/**
 * On-server READ-ONLY export. SELECT only. Writes CSV under storage/app/audits.
 * Usage: php export-prod-missing-city-onserver.php /path/to/laravel
 */

declare(strict_types=1);

$root = $argv[1] ?? getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

@mkdir($root.'/storage/app/audits', 0755, true);

$mastersPath = $root.'/storage/app/audits/prod-ocr-linked-missing-masters.csv';
$citiesPath = $root.'/storage/app/audits/prod-cities.csv';

$o = fopen($mastersPath, 'wb');
fputcsv($o, ['ca_id', 'firm_name', 'city_id', 'ocr_city_text', 'source_ocr_row_id', 'source_ocr_document_id']);
$n = 0;
DB::table('ca_masters')
    ->whereNull('deleted_at')
    ->where(function ($q) {
        $q->whereNull('city_id')->orWhere('city_id', 0);
    })
    ->whereNotNull('source_ocr_row_id')
    ->orderBy('ca_id')
    ->select(['ca_id', 'firm_name', 'city_id', 'ocr_city_text', 'source_ocr_row_id', 'source_ocr_document_id'])
    ->chunkById(500, function ($rows) use ($o, &$n) {
        foreach ($rows as $r) {
            fputcsv($o, [
                $r->ca_id,
                $r->firm_name,
                $r->city_id,
                $r->ocr_city_text,
                $r->source_ocr_row_id,
                $r->source_ocr_document_id,
            ]);
            $n++;
        }
    }, 'ca_id');
fclose($o);

$c = fopen($citiesPath, 'wb');
fputcsv($c, ['city_id', 'city_name', 'state_id']);
$idCol = Schema::hasColumn('cities', 'city_id') ? 'city_id' : 'id';
$nameCol = Schema::hasColumn('cities', 'city_name') ? 'city_name' : 'name';
$cn = 0;
foreach (DB::table('cities')->select([$idCol.' as city_id', $nameCol.' as city_name', 'state_id'])->cursor() as $x) {
    fputcsv($c, [$x->city_id, $x->city_name, $x->state_id ?? '']);
    $cn++;
}
fclose($c);

echo "MASTERS={$n} CITIES={$cn}\n";
echo "EXPORT_OK\n";
