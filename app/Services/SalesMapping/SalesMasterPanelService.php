<?php

namespace App\Services\SalesMapping;

use App\Models\CaMaster;
use App\Models\SalesContact;
use App\Models\SalesHistory;
use App\Models\SalesImportRow;
use App\Models\SalesMappingReview;
use App\Models\SalesMasterLink;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only Sales Mapping panels for Master Data drawer.
 * Never mutates ca_masters.
 */
class SalesMasterPanelService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(CaMaster $master): array
    {
        $caId = (int) $master->ca_id;
        $links = Schema::hasTable('sales_master_links')
            ? SalesMasterLink::query()->where('ca_id', $caId)->orderByDesc('linked_at')->orderByDesc('id')->get()
            : collect();
        $histories = Schema::hasTable('sales_histories')
            ? SalesHistory::query()->where('ca_id', $caId)->orderByDesc('imported_at')->orderByDesc('id')->get()
            : collect();

        $employees = $histories->pluck('employee_name')
            ->merge($links->map(fn ($l) => $l->employee?->name))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $latest = $links->first();

        return [
            'ca_id' => $caId,
            'total_sales_links' => $links->count(),
            'total_sales_contacts' => Schema::hasTable('sales_contacts')
                ? SalesContact::query()->where('ca_id', $caId)->count()
                : 0,
            'total_sales_histories' => $histories->count(),
            'first_sales_import' => optional($links->sortBy('linked_at')->first())->linked_at?->toIso8601String()
                ?? optional($histories->sortBy('imported_at')->first())->imported_at?->toIso8601String(),
            'latest_sales_import' => optional($latest)->linked_at?->toIso8601String()
                ?? optional($histories->first())->imported_at?->toIso8601String(),
            'employees' => $employees,
            'latest_mapping_tier' => $latest?->match_tier,
            'latest_confidence' => $latest?->confidence,
            'verification_status' => $master->verification_status ?? null,
            'is_verified' => (bool) ($master->is_verified ?? false),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function contacts(CaMaster $master, int $limit = 50): array
    {
        if (! Schema::hasTable('sales_contacts')) {
            return [];
        }

        return SalesContact::query()
            ->with('employee:employee_id,name')
            ->where('ca_id', $master->ca_id)
            ->orderByDesc('id')
            ->limit(max(1, min(200, $limit)))
            ->get()
            ->map(fn (SalesContact $c) => [
                'id' => $c->id,
                'sales_mobile' => $c->sales_mobile,
                'sales_alternate_mobile' => $c->sales_alternate_mobile,
                'sales_email' => $c->sales_email,
                'sales_website' => $c->sales_website,
                'employee_id' => $c->employee_id,
                'employee_name' => $c->employee?->name,
                'import_batch_id' => $c->import_batch_id,
                'source_file' => optional(
                    SalesImportRow::query()->find($c->sales_import_row_id)
                )->source_file_name,
                'imported_at' => $c->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function histories(CaMaster $master, int $limit = 100): array
    {
        if (! Schema::hasTable('sales_histories')) {
            return [];
        }

        return SalesHistory::query()
            ->where('ca_id', $master->ca_id)
            ->orderByDesc('imported_at')
            ->orderByDesc('id')
            ->limit(max(1, min(500, $limit)))
            ->get()
            ->map(fn (SalesHistory $h) => [
                'id' => $h->id,
                'call_date' => $h->call_date?->format('Y-m-d'),
                'employee_id' => $h->employee_id,
                'employee_name' => $h->employee_name,
                'remarks' => $h->remarks,
                'remarks_2' => $h->remarks_2,
                'employee_notes' => $h->employee_notes,
                'call_status' => $h->call_status,
                'follow_up' => $h->follow_up,
                'software' => $h->software,
                'sales_source' => $h->sales_source,
                'csv_filename' => $h->csv_filename,
                'csv_row_number' => $h->csv_row_number,
                'imported_at' => $h->imported_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function importHistory(CaMaster $master, int $limit = 50): array
    {
        if (! Schema::hasTable('sales_master_links')) {
            return [];
        }

        return SalesMasterLink::query()
            ->with('employee:employee_id,name')
            ->where('ca_id', $master->ca_id)
            ->orderByDesc('linked_at')
            ->orderByDesc('id')
            ->limit(max(1, min(200, $limit)))
            ->get()
            ->map(fn (SalesMasterLink $l) => [
                'id' => $l->id,
                'import_batch_id' => $l->import_batch_id,
                'employee_id' => $l->employee_id,
                'employee_name' => $l->employee?->name,
                'csv_filename' => $l->csv_filename,
                'csv_row_number' => $l->csv_row_number,
                'match_tier' => $l->match_tier,
                'confidence' => $l->confidence,
                'linked_at' => $l->linked_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function reviews(CaMaster $master, int $limit = 50): array
    {
        if (! Schema::hasTable('sales_mapping_reviews')) {
            return [];
        }

        $rowIds = SalesImportRow::query()
            ->where('matched_ca_id', $master->ca_id)
            ->pluck('id');

        return SalesMappingReview::query()
            ->where(function ($q) use ($master, $rowIds) {
                $q->where('approved_ca_id', $master->ca_id);
                if ($rowIds->isNotEmpty()) {
                    $q->orWhereIn('sales_import_row_id', $rowIds);
                }
            })
            ->orderByDesc('id')
            ->limit(max(1, min(200, $limit)))
            ->get()
            ->map(fn (SalesMappingReview $r) => [
                'id' => $r->id,
                'status' => $r->status,
                'reason' => $r->reason,
                'match_tier' => $r->match_tier,
                'confidence' => $r->confidence,
                'approved_ca_id' => $r->approved_ca_id,
                'reviewed_by' => $r->reviewed_by,
                'review_notes' => $r->review_notes,
                'reviewed_at' => $r->reviewed_at?->toIso8601String(),
                'sales_import_row_id' => $r->sales_import_row_id,
            ])
            ->values()
            ->all();
    }
}
