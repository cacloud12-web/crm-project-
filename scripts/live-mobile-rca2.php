<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$batchId = 1276; // sheet52

echo "=== Batch {$batchId} decisions ===\n";
$rows = DB::table('master_mapping_decisions')
    ->where('import_batch_id', $batchId)
    ->select('decision', DB::raw('COUNT(*) as c'))
    ->groupBy('decision')
    ->get();
foreach ($rows as $r) {
    echo "{$r->decision}={$r->c}\n";
}

$total = DB::table('master_mapping_decisions')->where('import_batch_id', $batchId)->count();
echo "total={$total}\n";

// Sample decision_meta / payload for empty mobile creates
$samples = DB::table('master_mapping_decisions')
    ->where('import_batch_id', $batchId)
    ->whereIn('decision', ['auto_create', 'auto_update'])
    ->orderByDesc('id')
    ->limit(500)
    ->get(['id', 'decision', 'matched_ca_id', 'decision_meta', 'payload_snapshot', 'new_values', 'old_values', 'remarks']);

$actions = [];
$emptyMobileCreates = 0;
$examples = [];
foreach ($samples as $s) {
    $meta = json_decode((string) $s->decision_meta, true) ?: [];
    $payload = json_decode((string) $s->payload_snapshot, true) ?: [];
    $newVals = json_decode((string) $s->new_values, true) ?: [];
    $action = $meta['mobile_action'] ?? ($payload['mobile_action'] ?? 'n/a');
    $actions[$action] = ($actions[$action] ?? 0) + 1;

    $phone = $payload['phone'] ?? $payload['mobile_no'] ?? $payload['normalized_mobile'] ?? null;
    $newMobile = $newVals['mobile_no'] ?? null;
    if (($phone === null || $phone === '') && is_array($newVals) && (($newVals['sales_remarks'] ?? null) || true)) {
        // look for creates with no phone in payload
    }
    if (($phone === null || $phone === '') && count($examples) < 15) {
        $examples[] = [
            'id' => $s->id,
            'decision' => $s->decision,
            'ca' => $s->matched_ca_id,
            'action' => $action,
            'firm' => $payload['firm_name'] ?? null,
            'phone' => $phone,
            'alt' => $payload['alternate_mobile_no'] ?? null,
            'new_mobile' => $newMobile,
            'new_alt' => $newVals['alternate_mobile_no'] ?? null,
            'meta_keys' => array_keys($meta),
        ];
    }
}
echo "\n=== mobile_action in last 500 of batch (from decision_meta) ===\n";
arsort($actions);
foreach ($actions as $k => $v) {
    echo "{$k}={$v}\n";
}
echo "\n=== example empty-phone payloads ===\n";
foreach ($examples as $e) {
    echo json_encode($e)."\n";
}

// Full batch: parse all decision_meta mobile_action
echo "\n=== ALL batch mobile_action ===\n";
$allActions = [];
$noPhonePayload = 0;
$phoneButEmptyNew = 0;
$skippedSlots = 0;
$addedAlt = 0;
$cursor = DB::table('master_mapping_decisions')->where('import_batch_id', $batchId)->orderBy('id');
$cursor->chunk(1000, function ($chunk) use (&$allActions, &$noPhonePayload, &$phoneButEmptyNew, &$skippedSlots, &$addedAlt) {
    foreach ($chunk as $s) {
        $meta = json_decode((string) $s->decision_meta, true) ?: [];
        $payload = json_decode((string) $s->payload_snapshot, true) ?: [];
        $newVals = json_decode((string) $s->new_values, true) ?: [];
        $action = $meta['mobile_action'] ?? 'missing';
        $allActions[$action] = ($allActions[$action] ?? 0) + 1;
        if ($action === 'skipped_slots_full') {
            $skippedSlots++;
        }
        if ($action === 'added_alternate') {
            $addedAlt++;
        }
        $phone = $payload['phone'] ?? $payload['mobile_no'] ?? null;
        if ($phone === null || $phone === '') {
            $noPhonePayload++;
        } elseif (in_array($s->decision, ['auto_create', 'auto_update'], true)) {
            $nm = $newVals['mobile_no'] ?? null;
            $na = $newVals['alternate_mobile_no'] ?? null;
            if (($nm === null || $nm === '') && ($na === null || $na === '') && $action !== 'already_present') {
                $phoneButEmptyNew++;
            }
        }
    }
});
arsort($allActions);
foreach ($allActions as $k => $v) {
    echo "{$k}={$v}\n";
}
echo "no_phone_in_payload={$noPhonePayload}\n";
echo "phone_in_payload_but_new_values_empty_both={$phoneButEmptyNew}\n";
echo "skipped_slots_full={$skippedSlots}\n";
echo "added_alternate={$addedAlt}\n";

// Cross-check: Sales source masters with empty mobile — does CSV alt exist and match?
echo "\n=== Sales source empty primary: alt present? ===\n";
$src = DB::table('source_leads')->where('source_name', 'Sales CSV Import')->value('source_id');
$empty = DB::table('ca_masters')->whereNull('deleted_at')->where('source_id', $src)
    ->where(function ($q) {
        $q->whereNull('mobile_no')->orWhere('mobile_no', '=', '');
    })
    ->get(['ca_id', 'firm_name', 'ca_name', 'mobile_no', 'alternate_mobile_no', 'sales_remarks']);
$withAlt = 0;
$without = 0;
foreach ($empty as $e) {
    if ($e->alternate_mobile_no) {
        $withAlt++;
    } else {
        $without++;
    }
}
echo "sales_empty_primary={$empty->count()} with_alt={$withAlt} without={$without}\n";

echo "OK\n";
