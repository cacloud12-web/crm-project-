<?php

/**
 * Diagnose / resume a stuck CA Master bulk import (status=Processing).
 *
 * Usage (from public_html):
 *   /opt/alt/php83/usr/bin/php scripts/resume-stuck-bulk-import-onserver.php
 *   /opt/alt/php83/usr/bin/php scripts/resume-stuck-bulk-import-onserver.php --id=123
 *   /opt/alt/php83/usr/bin/php scripts/resume-stuck-bulk-import-onserver.php --id=123 --all
 *   /opt/alt/php83/usr/bin/php scripts/resume-stuck-bulk-import-onserver.php --id=123 --mark-complete
 */

use App\Models\BulkAction;
use App\Services\Bulk\BulkCaMasterImportService;
use Illuminate\Support\Facades\Cache;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$opts = getopt('', ['id::', 'all', 'mark-complete', 'help']);
if (isset($opts['help'])) {
    echo "Diagnose/resume stuck ca_master_import bulk actions.\n";
    exit(0);
}

$id = isset($opts['id']) ? (int) $opts['id'] : null;
$query = BulkAction::query()
    ->where('action_type', 'ca_master_import')
    ->where('status', 'Processing')
    ->orderByDesc('bulk_action_id');

$bulk = $id
    ? BulkAction::query()->where('bulk_action_id', $id)->first()
    : $query->first();

if (! $bulk) {
    fwrite(STDERR, "No matching bulk action found.\n");
    exit(1);
}

$cacheKey = 'bulk_import_job:'.$bulk->bulk_action_id;
$payload = Cache::get($cacheKey);
$sessionId = is_array($payload) ? ($payload['session_id'] ?? null) : null;
$sessionOk = $sessionId && is_array(Cache::get('bulk_import_session:'.$sessionId));

echo "=== Stuck import ===\n";
echo "id:            {$bulk->bulk_action_id}\n";
echo "file:          {$bulk->file_name}\n";
echo "status:        {$bulk->status}\n";
echo "processed:     {$bulk->processed_records} / {$bulk->total_records}\n";
echo "inserted:      {$bulk->success_records}\n";
echo "duplicates:    {$bulk->duplicate_records}\n";
echo "failed:        {$bulk->failed_records}\n";
echo "queue cache:   ".($payload ? 'YES' : 'MISSING')."\n";
echo "session cache: ".($sessionOk ? 'YES' : 'MISSING')."\n";

if (isset($opts['mark-complete'])) {
    $bulk->update([
        'status' => ((int) $bulk->success_records > 0) ? 'Completed with errors' : 'Failed',
        'completed_at' => now(),
    ]);
    echo "Marked {$bulk->status}. Re-import remaining rows via wizard if needed.\n";
    exit(0);
}

if (! $sessionOk) {
    fwrite(STDERR, "\nSession expired — cannot safely resume.\n");
    fwrite(STDERR, "Re-run with --mark-complete, then re-upload only remaining/failed rows.\n");
    fwrite(STDERR, "Do NOT re-run the full original file (would duplicate the {$bulk->success_records} inserts).\n");
    exit(2);
}

$service = app(BulkCaMasterImportService::class);
$loops = 0;
$max = isset($opts['all']) ? 500 : 1;

do {
    $loops++;
    $result = $service->processQueuedImport((int) $bulk->bulk_action_id);
    $bulk->refresh();
    echo "batch {$loops}: status={$bulk->status} processed={$bulk->processed_records}/{$bulk->total_records} inserted={$bulk->success_records}\n";
    if (! ($result['continued'] ?? false)) {
        break;
    }
} while ($loops < $max && $bulk->status === 'Processing');

echo "Final status: {$bulk->fresh()->status}\n";
