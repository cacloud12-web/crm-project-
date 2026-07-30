<?php

namespace App\Services\SalesMapping;

use App\Models\CaMaster;
use App\Models\MasterImportBatch;
use App\Models\SalesContact;
use App\Models\SalesHistory;
use App\Models\SalesImportRow;
use App\Models\SalesMappingReview;
use App\Models\SalesMasterLink;
use App\Services\Mapping\DataNormalizationService;
use App\Services\Mapping\SalesEmployeeListImportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Writes Sales Mapping enrichment rows only.
 * Never updates ca_masters identity names, verification, OCR match flags, or Google fields.
 * May fill empty email_id, sales_remarks, and city_id (or ocr_city_text display fallback).
 */
class SalesEnrichmentWriter
{
    public function __construct(
        private readonly DataNormalizationService $normalizer,
        private readonly \App\Services\Master\LookupResolverService $lookups,
    ) {}

    /**
     * Apply enrichment for one imported/mapped sales row based on mapping_status.
     * Idempotent for unique-per-row link/history/review constraints.
     */
    public function applyForRow(SalesImportRow $row): void
    {
        $status = (string) ($row->mapping_status ?? '');

        if ($status === 'matched' && $row->matched_ca_id) {
            $this->assertMasterExists((int) $row->matched_ca_id);
            $this->writeMatchedEnrichment($row, (int) $row->matched_ca_id);

            return;
        }

        if (in_array($status, ['needs_review', 'unmatched'], true)) {
            $this->writeReview($row);

            return;
        }
    }

    /**
     * Chunked apply for an entire import batch.
     */
    public function applyForBatch(?int $batchId, int $chunk = 500): int
    {
        if ($batchId === null || ! Schema::hasTable('sales_import_rows')) {
            return 0;
        }

        $applied = 0;
        SalesImportRow::query()
            ->where('import_batch_id', $batchId)
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use (&$applied) {
                foreach ($rows as $row) {
                    $this->applyForRow($row);
                    $applied++;
                }
            });

        return $applied;
    }

    private function writeMatchedEnrichment(SalesImportRow $row, int $caId): void
    {
        // Snapshot verification / OCR / identity fields — Sales Mapping must never change them.
        // email_id / sales_remarks / city may be filled only when currently empty (never overwrite real values).
        $before = null;
        if (Schema::hasTable('ca_masters')) {
            $cols = [
                'ca_id', 'ca_name', 'firm_name', 'mobile_no', 'email_id', 'city_id', 'state_id',
                'is_verified', 'verification_status', 'google_place_id',
                'source_ocr_document_id', 'source_ocr_row_id', 'ocr_match_status',
            ];
            if (Schema::hasColumn('ca_masters', 'sales_remarks')) {
                $cols[] = 'sales_remarks';
            }
            if (Schema::hasColumn('ca_masters', 'ocr_city_text')) {
                $cols[] = 'ocr_city_text';
            }
            $before = CaMaster::query()->where('ca_id', $caId)->first($cols);
        }

        if (Schema::hasTable('sales_master_links')) {
            SalesMasterLink::query()->updateOrCreate(
                ['sales_import_row_id' => $row->id],
                [
                    'ca_id' => $caId,
                    'import_batch_id' => $this->resolveBatchId($row),
                    'employee_id' => $row->employee_id,
                    'match_tier' => $row->confidence_tier ?: $row->matched_on,
                    'confidence' => $row->match_score,
                    'sales_source' => $row->sales_source,
                    'csv_filename' => $row->source_file_name,
                    'csv_row_number' => $row->source_row_number,
                    'linked_at' => $row->mapped_at ?: now(),
                ]
            );
        }

        if (Schema::hasTable('sales_contacts')) {
            $this->appendContact($row, $caId);
        }

        if (Schema::hasTable('sales_histories')) {
            $this->appendHistory($row, $caId);
        }

        $this->fillEmptyMasterEmailRemarksAndCity($row, $caId);

        if ($before) {
            $cols = array_keys($before->getAttributes());
            $after = CaMaster::query()->where('ca_id', $caId)->first($cols);
            foreach ([
                'ca_name', 'firm_name', 'mobile_no',
                'is_verified', 'verification_status', 'google_place_id',
                'source_ocr_document_id', 'source_ocr_row_id', 'ocr_match_status',
            ] as $col) {
                if (! array_key_exists($col, $before->getAttributes())) {
                    continue;
                }
                $beforeVal = $before->getAttributes()[$col] ?? null;
                $afterVal = $after?->getAttributes()[$col] ?? null;
                if ($beforeVal != $afterVal) {
                    report(new \RuntimeException(
                        "Sales enrichment unexpectedly changed ca_masters.{$col} for ca_id={$caId}"
                    ));
                }
            }
            // email_id / sales_remarks: only empty→value is allowed.
            foreach (['email_id', 'sales_remarks'] as $col) {
                if (! array_key_exists($col, $before->getAttributes())) {
                    continue;
                }
                $beforeVal = $before->getAttributes()[$col] ?? null;
                $afterVal = $after?->getAttributes()[$col] ?? null;
                $beforeEmpty = $beforeVal === null || trim((string) $beforeVal) === '';
                if (! $beforeEmpty && $beforeVal != $afterVal) {
                    report(new \RuntimeException(
                        "Sales enrichment overwrote ca_masters.{$col} for ca_id={$caId}"
                    ));
                }
            }
        }
    }

    /**
     * Populate Master email_id / sales_remarks / city only when currently empty
     * (or city is the UNKNOWN placeholder).
     */
    private function fillEmptyMasterEmailRemarksAndCity(SalesImportRow $row, int $caId): void
    {
        if (! Schema::hasTable('ca_masters')) {
            return;
        }

        DB::transaction(function () use ($row, $caId) {
            $lead = CaMaster::query()->where('ca_id', $caId)->lockForUpdate()->first();
            if (! $lead) {
                return;
            }

            $dirty = false;
            $email = Schema::hasColumn('sales_import_rows', 'email') ? $row->email : null;
            if ($email && trim((string) $email) !== ''
                && ($lead->email_id === null || trim((string) $lead->email_id) === '')) {
                $lead->email_id = trim((string) $email);
                if (Schema::hasColumn('ca_masters', 'normalized_email')) {
                    $lead->normalized_email = $this->normalizer->email($lead->email_id);
                }
                $dirty = true;
            }

            if (Schema::hasColumn('ca_masters', 'sales_remarks')) {
                $merged = $this->mergedSalesRemarksFromRow($row);
                if ($merged !== null
                    && ($lead->sales_remarks === null || trim((string) $lead->sales_remarks) === '')) {
                    $lead->sales_remarks = $merged;
                    $dirty = true;
                }
            }

            if ($this->applySalesCityToMaster($lead, $row)) {
                $dirty = true;
            }

            if ($dirty) {
                $lead->saveQuietly();
            }
        });
    }

    /**
     * Link / display sales CSV city on Master when Master has no real city yet.
     */
    private function applySalesCityToMaster(CaMaster $lead, SalesImportRow $row): bool
    {
        $cityRaw = trim((string) ($row->city_name ?? ''));
        if ($cityRaw === '') {
            return false;
        }

        if (! $this->masterNeedsCity($lead)) {
            return false;
        }

        $dirty = false;
        $stateId = $lead->state_id ? (int) $lead->state_id : null;
        $cityId = $this->lookups->resolveCityId($cityRaw, $stateId);
        if ($cityId === null) {
            $cityId = $this->lookups->resolveCityId($cityRaw, null);
        }
        if ($cityId === null) {
            $cityId = $this->lookups->ensureCityId($cityRaw, $stateId);
        }

        if ($cityId !== null) {
            $lead->city_id = $cityId;
            $cityStateId = \App\Models\City::query()->where('city_id', $cityId)->value('state_id');
            if ($cityStateId && (! $lead->state_id || (int) $lead->state_id !== (int) $cityStateId)) {
                // Prefer the city's real state over a missing / mismatched Master state.
                if (! $lead->state_id || ! $this->lookups->cityBelongsToState($cityId, (int) $lead->state_id)) {
                    $lead->state_id = (int) $cityStateId;
                }
            }
            foreach (\App\Support\Ocr\CaMasterCityQuality::attributesAfterRealCityLinked($lead) as $key => $value) {
                $lead->{$key} = $value;
            }
            $dirty = true;
        }

        // Always keep a displayable text fallback when city name is known from Sales.
        if (Schema::hasColumn('ca_masters', 'ocr_city_text')) {
            $existingText = trim((string) ($lead->ocr_city_text ?? ''));
            if ($existingText === '' || \App\Support\Ocr\CaMasterCityQuality::isPlaceholderCityName($existingText)) {
                $lead->ocr_city_text = $cityRaw;
                $dirty = true;
            }
        }

        return $dirty;
    }

    private function masterNeedsCity(CaMaster $lead): bool
    {
        $cityId = $lead->city_id !== null ? (int) $lead->city_id : 0;
        if ($cityId < 1) {
            return true;
        }

        return ! \App\Support\Ocr\CaMasterCityQuality::hasLinkedRealCity($lead);
    }

    private function mergedSalesRemarksFromRow(SalesImportRow $row): ?string
    {
        $parts = [];
        if (is_string($row->remarks_1) && trim($row->remarks_1) !== '') {
            $parts[] = trim($row->remarks_1);
        }
        if (is_string($row->remarks_2) && trim($row->remarks_2) !== '') {
            $parts[] = trim($row->remarks_2);
        }

        $extra = Schema::hasColumn('sales_import_rows', 'extra_columns') ? $row->extra_columns : null;
        if (is_array($extra)) {
            if (! empty($extra['_sales_remarks']) && is_string($extra['_sales_remarks'])) {
                return trim($extra['_sales_remarks']) !== '' ? trim($extra['_sales_remarks']) : null;
            }
            foreach ($extra as $key => $value) {
                if ($key === '_sales_remarks' || $value === null) {
                    continue;
                }
                if (is_string($key) && preg_match('/^remarks?\s*\d*$/i', $key)) {
                    $text = trim((string) $value);
                    if ($text !== '') {
                        $parts[] = $text;
                    }
                }
            }
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    private function appendContact(SalesImportRow $row, int $caId): void
    {
        $linkId = Schema::hasTable('sales_master_links')
            ? SalesMasterLink::query()->where('sales_import_row_id', $row->id)->value('id')
            : null;

        $mobile = $row->mobile_no;
        $alt = $row->alternate_mobile_no;
        $email = Schema::hasColumn('sales_import_rows', 'email') ? $row->email : null;
        $website = Schema::hasColumn('sales_import_rows', 'website') ? $row->website : null;

        if ($mobile === null && $alt === null && $email === null && $website === null) {
            return;
        }

        $existing = SalesContact::query()
            ->where('sales_import_row_id', $row->id)
            ->first();

        $payload = [
            'ca_id' => $caId,
            'sales_master_link_id' => $linkId,
            'import_batch_id' => $this->resolveBatchId($row),
            'sales_import_row_id' => $row->id,
            'employee_id' => $row->employee_id,
            'sales_mobile' => $mobile,
            'normalized_sales_mobile' => $this->normalizer->phone($mobile),
            'sales_alternate_mobile' => $alt,
            'sales_email' => $email,
            'normalized_sales_email' => $this->normalizer->email($email),
            'sales_website' => $website,
            'is_primary_sales' => true,
        ];

        if ($existing) {
            $existing->fill($payload)->save();
        } else {
            SalesContact::query()->create($payload);
        }
    }

    private function appendHistory(SalesImportRow $row, int $caId): void
    {
        if (SalesHistory::query()->where('sales_import_row_id', $row->id)->exists()) {
            return; // append-only: never overwrite an existing history for the same row
        }

        $extra = null;
        if (Schema::hasColumn('sales_import_rows', 'extra_columns')) {
            $extra = $row->extra_columns;
        }

        $merged = $this->mergedSalesRemarksFromRow($row);

        SalesHistory::query()->create([
            'ca_id' => $caId,
            'import_batch_id' => $this->resolveBatchId($row),
            'sales_import_row_id' => $row->id,
            'employee_id' => $row->employee_id,
            'employee_name' => $row->employee_name,
            'remarks' => $row->remarks_1,
            'remarks_2' => $row->remarks_2,
            'employee_notes' => $merged,
            'call_status' => Schema::hasColumn('sales_import_rows', 'call_status') ? $row->call_status : null,
            'follow_up' => Schema::hasColumn('sales_import_rows', 'follow_up') ? $row->follow_up : null,
            'software' => Schema::hasColumn('sales_import_rows', 'software') ? $row->software : null,
            'sales_source' => Schema::hasColumn('sales_import_rows', 'sales_source') ? $row->sales_source : null,
            'csv_filename' => $row->source_file_name,
            'csv_row_number' => $row->source_row_number,
            'call_date' => $row->call_date,
            'extra_columns' => $extra,
            'imported_at' => $row->mapped_at ?: now(),
        ]);
    }

    private function writeReview(SalesImportRow $row): void
    {
        if (! Schema::hasTable('sales_mapping_reviews')) {
            return;
        }

        $candidates = is_array($row->match_candidates) ? $row->match_candidates : [];
        $candidateIds = [];
        foreach ($candidates as $candidate) {
            if (is_array($candidate) && isset($candidate['ca_id']) && (int) $candidate['ca_id'] > 0) {
                $candidateIds[] = (int) $candidate['ca_id'];
            }
        }
        $candidateIds = array_values(array_unique($candidateIds));

        $reason = $row->mapping_status === 'unmatched'
            ? 'no_match'
            : (count($candidateIds) > 1 ? 'multiple_candidates' : 'needs_review');

        $batchId = $this->resolveBatchId($row);

        SalesMappingReview::query()->updateOrCreate(
            ['sales_import_row_id' => $row->id],
            [
                'import_batch_id' => $batchId,
                'candidate_ca_ids' => $candidateIds,
                'confidence' => $row->match_score,
                'match_tier' => $row->confidence_tier ?: $row->matched_on,
                'reason' => $reason,
                'status' => SalesMappingReview::STATUS_PENDING,
                'approved_ca_id' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_notes' => $row->review_reason,
            ]
        );
    }

    /**
     * Ensure a valid master_import_batches.id for FK-safe enrichment.
     */
    private function resolveBatchId(SalesImportRow $row): int
    {
        $existing = $this->safeBatchId($row->import_batch_id);
        if ($existing !== null) {
            return $existing;
        }

        $batch = MasterImportBatch::query()->create([
            'source_type' => SalesEmployeeListImportService::SOURCE_TYPE,
            'source_ref' => 'sales-enrichment-row-'.$row->id,
            'file_name' => $row->source_file_name ?: 'manual-enrichment',
            'status' => MasterImportBatch::STATUS_COMPLETED,
            'total_records' => 1,
            'progress_stage' => 'completed',
            'progress_pct' => 100,
            'remarks' => json_encode([
                'purpose' => 'sales_mapping_enrichment',
                'sales_import_row_id' => $row->id,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        if ($row->import_batch_id === null || (int) $row->import_batch_id <= 0) {
            $row->forceFill(['import_batch_id' => $batch->id])->save();
        }

        return (int) $batch->id;
    }

    private function safeBatchId(mixed $batchId): ?int
    {
        if ($batchId === null || (int) $batchId <= 0) {
            return null;
        }
        if (! Schema::hasTable('master_import_batches')) {
            return null;
        }
        if (! MasterImportBatch::query()->whereKey((int) $batchId)->exists()) {
            return null;
        }

        return (int) $batchId;
    }

    /**
     * Mark an existing pending review as manually approved.
     * Never called from auto-match — only from human review actions.
     */
    public function approveReviewForRow(SalesImportRow $row, ?int $reviewedBy = null): void
    {
        if (! Schema::hasTable('sales_mapping_reviews') || ! $row->matched_ca_id) {
            return;
        }

        $review = SalesMappingReview::query()
            ->where('sales_import_row_id', $row->id)
            ->where('status', SalesMappingReview::STATUS_PENDING)
            ->first();

        if (! $review) {
            return;
        }

        $review->fill([
            'status' => SalesMappingReview::STATUS_APPROVED,
            'approved_ca_id' => $row->matched_ca_id,
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => now(),
            'review_notes' => $row->review_reason,
        ])->save();
    }

    private function assertMasterExists(int $caId): void
    {
        if (! CaMaster::query()->where('ca_id', $caId)->exists()) {
            throw new \RuntimeException("Cannot enrich Sales row: ca_masters.ca_id={$caId} not found.");
        }
    }
}
