<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\MasterImportBatch;
use App\Models\SalesContact;
use App\Models\SalesHistory;
use App\Models\SalesImportRow;
use App\Models\SalesMappingReview;
use App\Models\SalesMasterLink;
use App\Services\Mapping\SalesEmployeeListImportService;
use App\Services\Mapping\SalesImportRemapProtection;
use App\Services\Mapping\SalesImportRemapService;
use App\Services\Mapping\SalesImportReviewService;
use App\Services\SalesMapping\SalesBatchCounterService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CrmTestAccounts;
use Tests\TestCase;

class SalesMappingManualReviewPhase3Test extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(\App\Services\Rbac\RbacDatabaseService::class)->ensureConfigDefaultGrants();
        app(\App\Services\Rbac\RbacMatrixService::class)->flushCache();
    }

    private function skipUnlessReady(): void
    {
        if (! Schema::hasTable('sales_import_rows')
            || ! Schema::hasTable('ca_masters')
            || ! Schema::hasTable('sales_master_links')) {
            $this->markTestSkipped('Sales Mapping schema incomplete');
        }
    }

    private function makeBatch(array $overrides = []): MasterImportBatch
    {
        $label = $overrides['file_name'] ?? ('phase3-test-'.uniqid().'.csv');
        unset($overrides['source_label']);

        return MasterImportBatch::query()->create(array_merge([
            'source_type' => SalesEmployeeListImportService::SOURCE_TYPE,
            'source_ref' => 'phase3-'.uniqid(),
            'file_name' => $label,
            'status' => MasterImportBatch::STATUS_COMPLETED,
            'total_records' => 0,
            'matched_count' => 0,
            'review_count' => 0,
            'unmatched_count' => 0,
            'rejected_count' => 0,
            'skipped_count' => 0,
            'duplicate_count' => 0,
            'failed_count' => 0,
            'conflict_count' => 0,
            'created_count' => 0,
            'started_at' => now(),
            'finished_at' => now(),
        ], $overrides));
    }

    private function makeCa(array $overrides = []): CaMaster
    {
        $suffix = uniqid();
        $payload = array_merge([
            'firm_name' => 'Phase3 Firm '.$suffix,
            'ca_name' => 'Phase3 CA '.$suffix,
            'status' => 'New',
            'rating' => 1,
        ], $overrides);

        if (Schema::hasColumn('ca_masters', 'normalized_firm_name') && empty($payload['normalized_firm_name'])) {
            $payload['normalized_firm_name'] = mb_strtoupper(preg_replace('/\s+/', ' ', trim($payload['firm_name'])) ?? $payload['firm_name']);
        }
        if (Schema::hasColumn('ca_masters', 'normalized_ca_name') && empty($payload['normalized_ca_name'])) {
            $payload['normalized_ca_name'] = mb_strtoupper($payload['ca_name']);
        }

        return CaMaster::query()->create($payload);
    }

    private function makeRow(MasterImportBatch $batch, array $overrides = []): SalesImportRow
    {
        return SalesImportRow::query()->create(array_merge([
            'import_batch_id' => $batch->id,
            'source_file_name' => $batch->file_name,
            'source_row_number' => 1,
            'employee_name' => 'ANKIT',
            'call_date' => now()->toDateString(),
            'ca_name' => 'Phase3 Sales CA',
            'firm_name' => 'Phase3 Sales Firm',
            'city_name' => 'Jaipur',
            'mobile_no' => '9876500011',
            'alternate_mobile_no' => '9876500012',
            'remarks_1' => 'Called',
            'remarks_2' => 'Follow later',
            'normalized_firm_name' => 'PHASE3 SALES FIRM',
            'normalized_city' => 'JAIPUR',
            'normalized_ca_name' => 'PHASE3 SALES CA',
            'mapping_status' => 'needs_review',
            'review_reason' => 'Multiple candidates',
            'match_candidates' => [],
            'raw_payload' => ['source' => 'phase3-test'],
        ], $overrides));
    }

    public function test_files_dashboard_returns_batches(): void
    {
        $this->skipUnlessReady();
        $this->actingAs(CrmTestAccounts::admin());
        $batch = $this->makeBatch(['file_name' => 'CA CloudDesk Leads - phase3-'.uniqid().'.csv']);
        $this->makeRow($batch);

        $response = $this->getJson('/employee-imports/files?per_page=100');
        $response->assertOk();
        $items = $response->json('data.data') ?? [];
        $ids = collect($items)->pluck('import_batch_id')->all();
        $this->assertContains($batch->id, $ids);
    }

    public function test_per_file_rows_scoped_by_import_batch_id(): void
    {
        $this->skipUnlessReady();
        $this->actingAs(CrmTestAccounts::admin());
        $batchA = $this->makeBatch();
        $batchB = $this->makeBatch();
        $rowA = $this->makeRow($batchA, ['employee_name' => 'SIMRAN', 'source_row_number' => 10]);
        $this->makeRow($batchB, ['employee_name' => 'ANKIT', 'source_row_number' => 20]);

        $response = $this->getJson('/employee-imports/data?import_batch_id='.$batchA->id.'&per_page=50');
        $response->assertOk();
        $ids = collect($response->json('data.data') ?? [])->pluck('id')->all();
        $this->assertContains($rowA->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_employee_and_status_and_search_filters(): void
    {
        $this->skipUnlessReady();
        $this->actingAs(CrmTestAccounts::admin());
        $batch = $this->makeBatch();
        $target = $this->makeRow($batch, [
            'employee_name' => 'FILTEREMP',
            'mapping_status' => 'unmatched',
            'firm_name' => 'UniqueFilterFirmXYZ',
            'source_row_number' => 3,
        ]);
        $this->makeRow($batch, [
            'employee_name' => 'OTHER',
            'mapping_status' => 'matched',
            'firm_name' => 'Other Firm',
            'source_row_number' => 4,
        ]);

        $byEmployee = $this->getJson('/employee-imports/data?import_batch_id='.$batch->id.'&employee=FILTEREMP');
        $byEmployee->assertOk();
        $this->assertSame([$target->id], collect($byEmployee->json('data.data'))->pluck('id')->all());

        $byStatus = $this->getJson('/employee-imports/data?import_batch_id='.$batch->id.'&status=unmatched');
        $byStatus->assertOk();
        $this->assertSame([$target->id], collect($byStatus->json('data.data'))->pluck('id')->all());

        $bySearch = $this->getJson('/employee-imports/data?import_batch_id='.$batch->id.'&search=UniqueFilterFirmXYZ');
        $bySearch->assertOk();
        $this->assertSame([$target->id], collect($bySearch->json('data.data'))->pluck('id')->all());
    }

    public function test_review_api_returns_sales_row_and_candidates(): void
    {
        $this->skipUnlessReady();
        $this->actingAs(CrmTestAccounts::admin());
        $batch = $this->makeBatch();
        $ca = $this->makeCa();
        $row = $this->makeRow($batch, [
            'match_candidates' => [[
                'ca_id' => $ca->ca_id,
                'firm_name' => $ca->firm_name,
                'ca_name' => $ca->ca_name,
                'match_tier' => 'firm_ca_city',
                'confidence' => 0.91,
                'candidate_reason' => 'Stored candidate',
            ]],
        ]);

        $response = $this->getJson('/employee-imports/'.$row->id.'/review');
        $response->assertOk()
            ->assertJsonPath('data.row.id', $row->id)
            ->assertJsonPath('data.row.firm_name', $row->firm_name);
        $this->assertNotEmpty($response->json('data.candidates'));
    }

    public function test_manual_confirm_creates_one_link_history_and_contact_idempotently(): void
    {
        $this->skipUnlessReady();
        $this->actingAs(CrmTestAccounts::admin());
        $batch = $this->makeBatch();
        $ca = $this->makeCa([
            'verification_status' => Schema::hasColumn('ca_masters', 'verification_status') ? 'needs_verification' : null,
            'is_verified' => false,
            'google_place_id' => Schema::hasColumn('ca_masters', 'google_place_id') ? 'place-phase3-xyz' : null,
            'mobile_no' => '9991110000',
            'firm_name' => 'Keep Firm Identity',
            'ca_name' => 'Keep CA Identity',
        ]);
        $before = $ca->fresh()->only(array_values(array_filter([
            'firm_name', 'ca_name', 'mobile_no',
            Schema::hasColumn('ca_masters', 'verification_status') ? 'verification_status' : null,
            Schema::hasColumn('ca_masters', 'is_verified') ? 'is_verified' : null,
            Schema::hasColumn('ca_masters', 'google_place_id') ? 'google_place_id' : null,
            Schema::hasColumn('ca_masters', 'source_ocr_document_id') ? 'source_ocr_document_id' : null,
        ])));

        $row = $this->makeRow($batch, [
            'mobile_no' => '9876500099',
        ]);
        if (Schema::hasColumn('sales_import_rows', 'email')) {
            $row->forceFill(['email' => 'sales@example.test'])->save();
        }

        $first = $this->postJson('/employee-imports/'.$row->id.'/confirm-match', [
            'matched_ca_id' => $ca->ca_id,
            'reason' => 'Manual confirm phase3',
        ]);
        $first->assertOk()->assertJsonPath('data.mapping_status', 'matched');

        $this->assertSame(1, SalesMasterLink::query()->where('sales_import_row_id', $row->id)->count());
        $this->assertSame(1, SalesHistory::query()->where('sales_import_row_id', $row->id)->count());
        if (Schema::hasColumn('sales_import_rows', 'email') || $row->mobile_no) {
            $this->assertSame(1, SalesContact::query()->where('sales_import_row_id', $row->id)->count());
        }

        $second = $this->postJson('/employee-imports/'.$row->id.'/confirm-match', [
            'matched_ca_id' => $ca->ca_id,
            'reason' => 'Repeat confirm',
        ]);
        $second->assertOk();
        $this->assertSame(1, SalesMasterLink::query()->where('sales_import_row_id', $row->id)->count());
        $this->assertSame(1, SalesHistory::query()->where('sales_import_row_id', $row->id)->count());
        $this->assertSame(1, SalesContact::query()->where('sales_import_row_id', $row->id)->count());

        $ca->refresh();
        foreach ($before as $key => $value) {
            $this->assertSame($value, $ca->{$key}, "Master field {$key} must remain unchanged");
        }
    }

    public function test_confirm_skips_contact_when_no_sales_contact_fields(): void
    {
        $this->skipUnlessReady();
        $this->actingAs(CrmTestAccounts::admin());
        $batch = $this->makeBatch();
        $ca = $this->makeCa();
        $row = $this->makeRow($batch, [
            'mobile_no' => null,
            'alternate_mobile_no' => null,
        ]);
        if (Schema::hasColumn('sales_import_rows', 'email')) {
            $row->email = null;
            $row->save();
        }
        if (Schema::hasColumn('sales_import_rows', 'website')) {
            $row->website = null;
            $row->save();
        }

        $this->postJson('/employee-imports/'.$row->id.'/confirm-match', [
            'matched_ca_id' => $ca->ca_id,
        ])->assertOk();

        $this->assertSame(1, SalesMasterLink::query()->where('sales_import_row_id', $row->id)->count());
        $this->assertSame(1, SalesHistory::query()->where('sales_import_row_id', $row->id)->count());
        $this->assertSame(0, SalesContact::query()->where('sales_import_row_id', $row->id)->count());
    }

    public function test_reject_and_ignore_create_no_link_or_history(): void
    {
        $this->skipUnlessReady();
        $this->actingAs(CrmTestAccounts::admin());
        $batch = $this->makeBatch();
        $rejectRow = $this->makeRow($batch, ['source_row_number' => 11]);
        $ignoreRow = $this->makeRow($batch, ['source_row_number' => 12]);

        $this->postJson('/employee-imports/'.$rejectRow->id.'/reject', [
            'reason' => 'Not a real lead',
        ])->assertOk()->assertJsonPath('data.mapping_status', 'rejected');

        $this->postJson('/employee-imports/'.$ignoreRow->id.'/ignore', [
            'reason' => 'Skip for now',
        ])->assertOk()->assertJsonPath('data.mapping_status', 'ignored');

        $this->assertSame(0, SalesMasterLink::query()->whereIn('sales_import_row_id', [$rejectRow->id, $ignoreRow->id])->count());
        $this->assertSame(0, SalesHistory::query()->whereIn('sales_import_row_id', [$rejectRow->id, $ignoreRow->id])->count());
    }

    public function test_mark_unmatched_creates_no_master(): void
    {
        $this->skipUnlessReady();
        $this->actingAs(CrmTestAccounts::admin());
        $before = CaMaster::query()->count();
        $batch = $this->makeBatch();
        $row = $this->makeRow($batch);

        $this->postJson('/employee-imports/'.$row->id.'/mark-unmatched', [
            'reason' => 'No Master',
        ])->assertOk()->assertJsonPath('data.mapping_status', 'unmatched');

        $row->refresh();
        $this->assertNull($row->matched_ca_id);
        $this->assertSame($before, CaMaster::query()->count());
    }

    public function test_search_masters_returns_only_existing_masters(): void
    {
        $this->skipUnlessReady();
        $this->actingAs(CrmTestAccounts::admin());
        $ca = $this->makeCa(['firm_name' => 'Searchable Firm '.uniqid()]);
        $before = CaMaster::query()->count();

        $response = $this->getJson('/employee-imports/search-masters?firm='.urlencode($ca->firm_name));
        $response->assertOk();
        $items = $response->json('data.items') ?? [];
        $this->assertNotEmpty($items);
        $this->assertContains($ca->ca_id, collect($items)->pluck('ca_id')->all());
        $this->assertSame($before, CaMaster::query()->count());
    }

    public function test_needs_verification_and_verified_masters_unchanged_after_link(): void
    {
        $this->skipUnlessReady();
        if (! Schema::hasColumn('ca_masters', 'verification_status')) {
            $this->markTestSkipped('verification_status missing');
        }
        $this->actingAs(CrmTestAccounts::admin());
        $batch = $this->makeBatch();

        $nv = $this->makeCa(['verification_status' => 'needs_verification', 'is_verified' => false]);
        $verified = $this->makeCa(['verification_status' => 'verified', 'is_verified' => true]);

        $rowNv = $this->makeRow($batch, ['source_row_number' => 21]);
        $rowV = $this->makeRow($batch, ['source_row_number' => 22]);

        $this->postJson('/employee-imports/'.$rowNv->id.'/confirm-match', ['matched_ca_id' => $nv->ca_id])->assertOk();
        $this->postJson('/employee-imports/'.$rowV->id.'/confirm-match', ['matched_ca_id' => $verified->ca_id])->assertOk();

        $nv->refresh();
        $verified->refresh();
        $this->assertSame('needs_verification', $nv->verification_status);
        $this->assertFalse((bool) $nv->is_verified);
        $this->assertSame('verified', $verified->verification_status);
        $this->assertTrue((bool) $verified->is_verified);
    }

    public function test_unauthorized_employee_cannot_approve(): void
    {
        $this->skipUnlessReady();
        $employee = CrmTestAccounts::employeeUser();
        $this->actingAs($employee);
        $batch = $this->makeBatch();
        $ca = $this->makeCa();
        $row = $this->makeRow($batch);

        $this->postJson('/employee-imports/'.$row->id.'/confirm-match', [
            'matched_ca_id' => $ca->ca_id,
        ])->assertStatus(403);

        $row->refresh();
        $this->assertSame('needs_review', $row->mapping_status);
        $this->assertSame(0, SalesMasterLink::query()->where('sales_import_row_id', $row->id)->count());
    }

    public function test_batch_counters_remain_correct_after_manual_decisions(): void
    {
        $this->skipUnlessReady();
        $this->actingAs(CrmTestAccounts::admin());
        $batch = $this->makeBatch();
        $ca = $this->makeCa();
        $matched = $this->makeRow($batch, ['source_row_number' => 31]);
        $rejected = $this->makeRow($batch, ['source_row_number' => 32]);
        $ignored = $this->makeRow($batch, ['source_row_number' => 33]);

        $this->postJson('/employee-imports/'.$matched->id.'/confirm-match', ['matched_ca_id' => $ca->ca_id])->assertOk();
        $this->postJson('/employee-imports/'.$rejected->id.'/reject', ['reason' => 'no'])->assertOk();
        $this->postJson('/employee-imports/'.$ignored->id.'/ignore', ['reason' => 'skip'])->assertOk();

        $counts = app(SalesBatchCounterService::class)->countsForBatch($batch->id);
        $this->assertSame(3, $counts['total_records']);
        $this->assertSame(1, $counts['matched_count']);
        $this->assertSame(1, $counts['rejected_count']);
        $this->assertSame(1, $counts['skipped_count']);

        $batch->refresh();
        $this->assertSame(1, (int) $batch->matched_count);
        $this->assertSame(1, (int) $batch->rejected_count);
        $this->assertSame(1, (int) $batch->skipped_count);
    }

    public function test_manual_decisions_survive_remap(): void
    {
        $this->skipUnlessReady();
        $this->actingAs(CrmTestAccounts::admin());
        $batch = $this->makeBatch();
        $ca = $this->makeCa();
        $row = $this->makeRow($batch);

        $this->postJson('/employee-imports/'.$row->id.'/confirm-match', [
            'matched_ca_id' => $ca->ca_id,
            'reason' => 'Keep me',
        ])->assertOk();

        $row->refresh();
        $protection = app(SalesImportRemapProtection::class);
        $this->assertTrue($protection->isProtected($row));

        $result = app(SalesImportRemapService::class)->run([
            'dry_run' => false,
            'batch' => $batch->id,
            'include_auto_matched' => true,
            'include_manual_unmatched' => true,
        ]);

        $row->refresh();
        $this->assertSame('matched', $row->mapping_status);
        $this->assertSame($ca->ca_id, (int) $row->matched_ca_id);
        $this->assertSame(SalesImportReviewService::ACTION_CONFIRM, $row->matched_on);
        $this->assertGreaterThanOrEqual(1, (int) ($result['totals']['skipped_protected'] ?? 0));
    }

    public function test_master_sales_panels_return_history(): void
    {
        $this->skipUnlessReady();
        $this->actingAs(CrmTestAccounts::admin());
        $batch = $this->makeBatch();
        $ca = $this->makeCa();
        $row = $this->makeRow($batch, ['remarks_1' => 'Panel history note']);

        $this->postJson('/employee-imports/'.$row->id.'/confirm-match', [
            'matched_ca_id' => $ca->ca_id,
        ])->assertOk();

        $this->getJson('/ca-masters/'.$ca->ca_id.'/sales-summary')
            ->assertOk()
            ->assertJsonPath('data.total_sales_links', 1);

        $history = $this->getJson('/ca-masters/'.$ca->ca_id.'/sales-history');
        $history->assertOk();
        $this->assertNotEmpty($history->json('data.items'));

        $this->getJson('/ca-masters/'.$ca->ca_id.'/sales-contacts')->assertOk();
        $this->getJson('/ca-masters/'.$ca->ca_id.'/sales-import-history')
            ->assertOk()
            ->assertJsonStructure(['data' => ['import_history', 'reviews']]);
    }

    public function test_server_side_pagination_for_large_batches(): void
    {
        $this->skipUnlessReady();
        $this->actingAs(CrmTestAccounts::admin());
        $batch = $this->makeBatch();
        for ($i = 1; $i <= 35; $i++) {
            $this->makeRow($batch, [
                'source_row_number' => $i,
                'firm_name' => 'Page Firm '.$i,
            ]);
        }

        $page1 = $this->getJson('/employee-imports/data?import_batch_id='.$batch->id.'&per_page=10&page=1');
        $page1->assertOk()
            ->assertJsonPath('data.pagination.per_page', 10)
            ->assertJsonPath('data.pagination.total', 35);
        $this->assertCount(10, $page1->json('data.data'));

        $page2 = $this->getJson('/employee-imports/data?import_batch_id='.$batch->id.'&per_page=10&page=2');
        $page2->assertOk();
        $this->assertCount(10, $page2->json('data.data'));
        $ids1 = collect($page1->json('data.data'))->pluck('id')->all();
        $ids2 = collect($page2->json('data.data'))->pluck('id')->all();
        $this->assertEmpty(array_intersect($ids1, $ids2));
    }

    public function test_accept_top_and_accept_all_are_disabled(): void
    {
        $this->skipUnlessReady();
        $this->actingAs(CrmTestAccounts::admin());
        $batch = $this->makeBatch();
        $row = $this->makeRow($batch);

        $this->postJson('/employee-imports/'.$row->id.'/accept-top')->assertStatus(410);
        $this->postJson('/employee-imports/accept-all-matched', [
            'import_batch_id' => $batch->id,
        ])->assertStatus(410);
    }

    public function test_review_record_approved_on_confirm(): void
    {
        $this->skipUnlessReady();
        if (! Schema::hasTable('sales_mapping_reviews')) {
            $this->markTestSkipped('sales_mapping_reviews missing');
        }
        $this->actingAs(CrmTestAccounts::admin());
        $batch = $this->makeBatch();
        $ca = $this->makeCa();
        $row = $this->makeRow($batch);
        SalesMappingReview::query()->create([
            'sales_import_row_id' => $row->id,
            'import_batch_id' => $batch->id,
            'status' => SalesMappingReview::STATUS_PENDING,
            'reason' => 'Needs eyes',
        ]);

        $this->postJson('/employee-imports/'.$row->id.'/confirm-match', [
            'matched_ca_id' => $ca->ca_id,
        ])->assertOk();

        $review = SalesMappingReview::query()->where('sales_import_row_id', $row->id)->first();
        $this->assertNotNull($review);
        $this->assertSame(SalesMappingReview::STATUS_APPROVED, $review->status);
        $this->assertSame($ca->ca_id, (int) $review->approved_ca_id);
    }
}
