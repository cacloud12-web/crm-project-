<?php

/**
 * FORCE missing city_id → 0 by assigning a PLACEHOLDER city.
 *
 * This is NOT real OCR recovery. Class C/E and no-OCR Masters have no unique
 * city evidence. To reach literal zero missing city_id, every remaining row
 * is set to cities.city_name = "UNKNOWN - CITY NOT IN SOURCE".
 *
 * Usage (on Hostinger app root):
 *   /opt/alt/php83/usr/bin/php scripts/force-zero-missing-cities-placeholder-onserver.php --dry-run
 *   /opt/alt/php83/usr/bin/php scripts/force-zero-missing-cities-placeholder-onserver.php --apply --i-accept-placeholder-city
 */

declare(strict_types=1);

$root = getcwd();
foreach ($argv as $i => $arg) {
    if ($i > 0 && ! str_starts_with($arg, '--') && is_dir($arg)) {
        $root = $arg;
    }
}
$apply = in_array('--apply', $argv, true);
$accept = in_array('--i-accept-placeholder-city', $argv, true);
$dryRun = ! $apply;
$chunk = 500;
$placeholderName = 'UNKNOWN - CITY NOT IN SOURCE';

if ($apply && ! $accept) {
    fwrite(STDERR, "Refusing --apply without --i-accept-placeholder-city\n");
    fwrite(STDERR, "This invents city_id for every remaining missing Master.\n");
    exit(1);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

@mkdir($root.'/storage/app/audits', 0755, true);
$stamp = date('Ymd_His');
$export = $root.'/storage/app/audits/force-zero-missing-'.($dryRun ? 'dryrun' : 'apply').'-'.$stamp.'.csv';

$missingQuery = DB::table('ca_masters')->where(function ($q) {
    $q->whereNull('city_id')->orWhere('city_id', 0);
});
if (Schema::hasColumn('ca_masters', 'deleted_at')) {
    $missingQuery->whereNull('deleted_at');
}
$before = (int) $missingQuery->count();

$existing = DB::table('cities')
    ->whereRaw('UPPER(TRIM(city_name)) = ?', [strtoupper($placeholderName)])
    ->first();

$placeholderCityId = $existing ? (int) $existing->city_id : null;
$createdCity = false;

if ($placeholderCityId === null) {
    if ($dryRun) {
        $placeholderCityId = -1; // synthetic for dry-run export
        $createdCity = true;
    } else {
        $stateId = (int) (DB::table('states')->orderBy('state_id')->value('state_id') ?? 0);
        if ($stateId <= 0) {
            fwrite(STDERR, "Cannot create placeholder city: states table is empty.\n");
            exit(1);
        }
        $insert = [
            'city_name' => $placeholderName,
            'state_id' => $stateId,
        ];
        if (Schema::hasColumn('cities', 'created_at')) {
            $now = now();
            $insert['created_at'] = $now;
            $insert['updated_at'] = $now;
        }
        $placeholderCityId = (int) DB::table('cities')->insertGetId($insert, 'city_id');
        $createdCity = true;
    }
}

$out = fopen($export, 'wb');
fputcsv($out, [
    'ca_id', 'firm_name', 'before_city_id', 'after_city_id', 'placeholder_city',
    'has_ocr_link', 'action', 'applied',
]);

$would = 0;
$updated = 0;
$errors = 0;
$pending = [];

$select = ['ca_id', 'firm_name', 'city_id'];
if (Schema::hasColumn('ca_masters', 'source_ocr_row_id')) {
    $select[] = 'source_ocr_row_id';
}

$q = DB::table('ca_masters')->where(function ($w) {
    $w->whereNull('city_id')->orWhere('city_id', 0);
});
if (Schema::hasColumn('ca_masters', 'deleted_at')) {
    $q->whereNull('deleted_at');
}

$q->orderBy('ca_id')->select($select)->chunkById(300, function ($rows) use (
    &$would, &$updated, &$errors, &$pending, $dryRun, $placeholderCityId, $placeholderName, $out, $chunk
) {
    foreach ($rows as $row) {
        $would++;
        $hasOcr = ! empty($row->source_ocr_row_id ?? null) ? 'yes' : 'no';
        if ($dryRun) {
            fputcsv($out, [
                $row->ca_id,
                $row->firm_name,
                $row->city_id,
                $placeholderCityId > 0 ? $placeholderCityId : '',
                $placeholderName,
                $hasOcr,
                'would_set_placeholder',
                'no',
            ]);
            continue;
        }

        $pending[] = [
            'ca_id' => (int) $row->ca_id,
            'firm_name' => (string) $row->firm_name,
            'before' => $row->city_id,
            'has_ocr' => $hasOcr,
        ];
        if (count($pending) >= $chunk) {
            flushChunk($pending, $placeholderCityId, $placeholderName, $out, $updated, $errors);
            $pending = [];
        }
    }
}, 'ca_id');

if (! $dryRun && $pending !== []) {
    flushChunk($pending, $placeholderCityId, $placeholderName, $out, $updated, $errors);
}

fclose($out);

$afterQ = DB::table('ca_masters')->where(function ($q) {
    $q->whereNull('city_id')->orWhere('city_id', 0);
});
if (Schema::hasColumn('ca_masters', 'deleted_at')) {
    $afterQ->whereNull('deleted_at');
}
$after = $dryRun ? $before : (int) $afterQ->count();

echo "FORCE ZERO MISSING CITIES — ".($dryRun ? 'DRY-RUN' : 'APPLY')."\n";
echo "WARNING: Placeholder city is NOT a real city from OCR.\n";
echo "Placeholder name: {$placeholderName}\n";
echo "Placeholder city_id: ".($placeholderCityId > 0 ? $placeholderCityId : '(would create)')."\n";
echo "Created placeholder city: ".($createdCity ? 'yes' : 'no')."\n";
echo "DB missing before: {$before}\n";
echo "Would update: {$would}\n";
echo "Updated: {$updated}\n";
echo "Errors: {$errors}\n";
echo "DB missing after: {$after}\n";
echo "Export: {$export}\n";

if ($dryRun) {
    echo "\nTo apply:\n";
    echo "  php scripts/force-zero-missing-cities-placeholder-onserver.php --apply --i-accept-placeholder-city\n";
}

if (! $dryRun && $after !== 0) {
    fwrite(STDERR, "FAILED: missing city_id still {$after} after apply.\n");
    exit(1);
}

exit(0);

/**
 * @param  list<array{ca_id:int,firm_name:string,before:mixed,has_ocr:string}>  $pending
 */
function flushChunk(array $pending, int $cityId, string $cityName, $out, int &$updated, int &$errors): void
{
    try {
        DB::transaction(function () use ($pending, $cityId) {
            $ids = array_column($pending, 'ca_id');
            DB::table('ca_masters')
                ->whereIn('ca_id', $ids)
                ->where(function ($q) {
                    $q->whereNull('city_id')->orWhere('city_id', 0);
                })
                ->update(['city_id' => $cityId]);
        });
        foreach ($pending as $p) {
            $updated++;
            fputcsv($out, [
                $p['ca_id'],
                $p['firm_name'],
                $p['before'],
                $cityId,
                $cityName,
                $p['has_ocr'],
                'set_placeholder',
                'yes',
            ]);
        }
    } catch (Throwable $e) {
        $errors += count($pending);
        foreach ($pending as $p) {
            fputcsv($out, [
                $p['ca_id'],
                $p['firm_name'],
                $p['before'],
                '',
                $cityName,
                $p['has_ocr'],
                'error: '.$e->getMessage(),
                'no',
            ]);
        }
    }
}
