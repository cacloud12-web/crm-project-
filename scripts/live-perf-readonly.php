<?php

/**
 * Read-only live performance snapshot. No writes (except this file is deleted after run).
 */
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "APP_ENV=".config('app.env').PHP_EOL;
echo "APP_DEBUG=".(config('app.debug') ? 'true' : 'false').PHP_EOL;
echo "CACHE=".config('cache.default').PHP_EOL;
echo "SESSION=".config('session.driver').PHP_EOL;
echo "QUEUE=".config('queue.default').PHP_EOL;
echo "DB=".config('database.default').PHP_EOL;

$tables = [
    'ca_masters', 'ocr_parsed_firms', 'sales_import_rows', 'employees',
    'activity_logs', 'call_logs', 'lead_assignment_engines', 'follow_ups',
    'email_logs', 'jobs', 'failed_jobs',
];

foreach ($tables as $t) {
    try {
        if (! Schema::hasTable($t)) {
            echo "{$t}=MISSING".PHP_EOL;
            continue;
        }
        echo "{$t}=".DB::table($t)->count().PHP_EOL;
    } catch (Throwable $e) {
        echo "{$t}=ERR".PHP_EOL;
    }
}

foreach (['ca_masters', 'sales_import_rows', 'call_logs', 'activity_logs', 'ocr_parsed_firms'] as $t) {
    if (! Schema::hasTable($t)) {
        continue;
    }
    echo "--- INDEX {$t} ---".PHP_EOL;
    try {
        $rows = DB::select('SHOW INDEX FROM '.$t);
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->Key_name][] = $row->Column_name;
        }
        foreach ($grouped as $name => $cols) {
            echo $name.': '.implode(',', $cols).PHP_EOL;
        }
    } catch (Throwable $e) {
        echo 'ERR'.PHP_EOL;
    }
}

echo "OPCACHE=".((function_exists('opcache_get_status') && ($s = @opcache_get_status(false))) ? ('enabled='.(($s['opcache_enabled'] ?? false) ? '1' : '0').'; mem_used_mb='.round((($s['memory_usage']['used_memory'] ?? 0) / 1048576), 1)) : 'n/a').PHP_EOL;
