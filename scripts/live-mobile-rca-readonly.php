<?php

/**
 * Read-only RCA: sales remarks present but mobile missing on ca_masters.
 * Also inspect mapping decisions mobile_action for Sales CSV Import.
 */
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "=== TABLES ===\n";
foreach ([
    'ca_masters', 'sales_import_rows', 'sales_list_entries', 'master_mapping_decisions',
    'source_leads', 'lead_phone_numbers', 'master_import_batches',
] as $t) {
    echo $t.'='.(Schema::hasTable($t) ? (string) DB::table($t)->count() : 'MISSING')."\n";
}

echo "\n=== CA_MASTERS: remarks present, mobile empty ===\n";
$remarksNotNull = Schema::hasColumn('ca_masters', 'sales_remarks');
if (! $remarksNotNull) {
    echo "sales_remarks column missing\n";
    exit(0);
}

$base = DB::table('ca_masters')->whereNull('deleted_at');

$totalWithRemarks = (clone $base)
    ->whereNotNull('sales_remarks')
    ->where('sales_remarks', '!=', '')
    ->count();

$remarksNoMobile = (clone $base)
    ->whereNotNull('sales_remarks')
    ->where('sales_remarks', '!=', '')
    ->where(function ($q) {
        $q->whereNull('mobile_no')->orWhere('mobile_no', '=', '');
    })
    ->count();

$remarksNoMobileNoAlt = (clone $base)
    ->whereNotNull('sales_remarks')
    ->where('sales_remarks', '!=', '')
    ->where(function ($q) {
        $q->whereNull('mobile_no')->orWhere('mobile_no', '=', '');
    })
    ->where(function ($q) {
        $q->whereNull('alternate_mobile_no')->orWhere('alternate_mobile_no', '=', '');
    })
    ->count();

$remarksNoPrimaryButAlt = (clone $base)
    ->whereNotNull('sales_remarks')
    ->where('sales_remarks', '!=', '')
    ->where(function ($q) {
        $q->whereNull('mobile_no')->orWhere('mobile_no', '=', '');
    })
    ->whereNotNull('alternate_mobile_no')
    ->where('alternate_mobile_no', '!=', '')
    ->count();

echo "total_with_sales_remarks={$totalWithRemarks}\n";
echo "remarks_and_empty_primary_mobile={$remarksNoMobile}\n";
echo "remarks_and_empty_primary_and_alt={$remarksNoMobileNoAlt}\n";
echo "remarks_empty_primary_but_has_alt={$remarksNoPrimaryButAlt}\n";

echo "\n=== SAMPLE rows (remarks, no primary mobile) ===\n";
$samples = (clone $base)
    ->whereNotNull('sales_remarks')
    ->where('sales_remarks', '!=', '')
    ->where(function ($q) {
        $q->whereNull('mobile_no')->orWhere('mobile_no', '=', '');
    })
    ->orderByDesc('ca_id')
    ->limit(25)
    ->get(['ca_id', 'firm_name', 'ca_name', 'mobile_no', 'alternate_mobile_no', 'normalized_mobile', 'email_id', 'source_id', 'sales_remarks', 'created_at', 'updated_at']);

foreach ($samples as $s) {
    $rm = preg_replace("/\s+/", ' ', substr((string) $s->sales_remarks, 0, 80));
    echo "ca_id={$s->ca_id}|firm=".substr((string) $s->firm_name, 0, 40)."|mobile=".($s->mobile_no ?: 'NULL')."|alt=".($s->alternate_mobile_no ?: 'NULL')."|email=".($s->email_id ?: 'NULL')."|src={$s->source_id}|remarks={$rm}\n";
}

echo "\n=== Source name for Sales CSV ===\n";
if (Schema::hasTable('source_leads')) {
    $src = DB::table('source_leads')->where('source_name', 'like', '%Sales%')->get(['source_id', 'source_name']);
    foreach ($src as $row) {
        echo "source_id={$row->source_id}|{$row->source_name}\n";
        $c = DB::table('ca_masters')->whereNull('deleted_at')->where('source_id', $row->source_id)->count();
        $emptyMob = DB::table('ca_masters')->whereNull('deleted_at')->where('source_id', $row->source_id)
            ->where(function ($q) {
                $q->whereNull('mobile_no')->orWhere('mobile_no', '=', '');
            })->count();
        $emptyMobRemarks = DB::table('ca_masters')->whereNull('deleted_at')->where('source_id', $row->source_id)
            ->whereNotNull('sales_remarks')->where('sales_remarks', '!=', '')
            ->where(function ($q) {
                $q->whereNull('mobile_no')->orWhere('mobile_no', '=', '');
            })->count();
        echo "  masters={$c}|empty_mobile={$emptyMob}|empty_mobile_with_remarks={$emptyMobRemarks}\n";
    }
}

echo "\n=== master_mapping_decisions mobile_action (Sales CSV) ===\n";
if (Schema::hasTable('master_mapping_decisions')) {
    $cols = Schema::getColumnListing('master_mapping_decisions');
    echo 'columns='.implode(',', $cols)."\n";

    $q = DB::table('master_mapping_decisions');
    if (in_array('source_name', $cols, true)) {
        $q->where('source_name', 'like', '%Sales%');
    } elseif (in_array('file_name', $cols, true)) {
        $q->where('file_name', 'like', '%sheet52%');
    }

    if (in_array('mobile_action', $cols, true)) {
        $actions = (clone $q)->select('mobile_action', DB::raw('COUNT(*) as c'))
            ->groupBy('mobile_action')->orderByDesc('c')->get();
        foreach ($actions as $a) {
            echo 'mobile_action='.($a->mobile_action ?: 'NULL')."|count={$a->c}\n";
        }
    } else {
        echo "no mobile_action column\n";
        // try JSON meta
        if (in_array('decision_meta', $cols, true) || in_array('meta', $cols, true) || in_array('notes', $cols, true)) {
            echo "checking recent decisions sample...\n";
        }
    }

    if (in_array('decision', $cols, true)) {
        $dec = (clone $q)->select('decision', DB::raw('COUNT(*) as c'))
            ->groupBy('decision')->orderByDesc('c')->get();
        foreach ($dec as $d) {
            echo "decision={$d->decision}|count={$d->c}\n";
        }
    }

    // Recent sales decisions with empty resulting mobile?
    $recent = DB::table('master_mapping_decisions')->orderByDesc('id')->limit(5)->get();
    echo "recent_decision_sample_count=".$recent->count()."\n";
    if ($recent->isNotEmpty()) {
        echo 'sample_keys='.implode(',', array_keys((array) $recent->first()))."\n";
    }
}

echo "\n=== master_import_batches ===\n";
if (Schema::hasTable('master_import_batches')) {
    $batches = DB::table('master_import_batches')->orderByDesc('id')->limit(10)->get();
    foreach ($batches as $b) {
        $arr = (array) $b;
        $name = $arr['file_name'] ?? $arr['source_file_name'] ?? $arr['label'] ?? '';
        echo 'id='.($arr['id'] ?? '?')."|status=".($arr['status'] ?? '')."|file={$name}|created=".($arr['created_at'] ?? '')."\n";
    }
}

echo "\n=== lead_phone_numbers vs ca_masters empty mobile ===\n";
if (Schema::hasTable('lead_phone_numbers')) {
    // masters with remarks, empty mobile, but phone in registry?
    $orphanPhones = DB::select("
        SELECT cm.ca_id, cm.firm_name, cm.mobile_no, cm.alternate_mobile_no,
               GROUP_CONCAT(lpn.normalized_number) as registry_phones
        FROM ca_masters cm
        INNER JOIN lead_phone_numbers lpn ON lpn.ca_id = cm.ca_id
        WHERE cm.deleted_at IS NULL
          AND cm.sales_remarks IS NOT NULL AND cm.sales_remarks != ''
          AND (cm.mobile_no IS NULL OR cm.mobile_no = '')
        GROUP BY cm.ca_id, cm.firm_name, cm.mobile_no, cm.alternate_mobile_no
        LIMIT 20
    ");
    echo 'remarks_empty_mobile_but_has_registry='.count($orphanPhones)."\n";
    foreach ($orphanPhones as $o) {
        echo "ca_id={$o->ca_id}|registry={$o->registry_phones}|alt=".($o->alternate_mobile_no ?: 'NULL')."|firm=".substr((string) $o->firm_name, 0, 40)."\n";
    }
}

echo "OK\n";
