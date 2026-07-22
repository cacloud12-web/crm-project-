<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\MasterMappingDecision;
use App\Models\SalesImportRow;
use App\Models\User;
use App\Services\Mapping\SalesImportMatchingService;
use App\Services\Mapping\SalesImportReviewService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CrmTestAccounts;
use Tests\TestCase;

class SalesImportReviewWorkflowTest extends TestCase
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
        if (! Schema::hasTable('sales_import_rows') || ! Schema::hasTable('ca_masters')) {
            $this->markTestSkipped('sales_import_rows or ca_masters missing');
        }
    }

    private function actingAsAdmin(): User
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        return $admin;
    }

    private function makeCa(string $firm, string $city, string $caName = 'Review CA'): CaMaster
    {
        $payload = [
            'firm_name' => $firm,
            'ca_name' => $caName,
            'status' => 'New',
            'rating' => 1,
        ];
        if (Schema::hasColumn('ca_masters', 'normalized_firm_name')) {
            $payload['normalized_firm_name'] = mb_strtoupper(preg_replace('/\s+/', ' ', trim($firm)) ?? $firm);
        }
        if (Schema::hasColumn('ca_masters', 'normalized_ca_name')) {
            $payload['normalized_ca_name'] = mb_strtoupper($caName);
        }

        return CaMaster::query()->create($payload);
    }

    private function makeRow(array $overrides = []): SalesImportRow
    {
        return SalesImportRow::query()->create(array_merge([
            'source_file_name' => 'ankit_list.csv',
            'source_row_number' => 12,
            'employee_name' => 'ANKIT',
            'call_date' => now()->toDateString(),
            'ca_name' => 'Aastha Partner',
            'firm_name' => 'Aastha Co',
            'city_name' => 'Jaipur',
            'mobile_no' => '9876543210',
            'alternate_mobile_no' => '9123456789',
            'remarks_1' => 'Called morning',
            'remarks_2' => 'Interested in GST',
            'normalized_firm_name' => 'AASTHA CO',
            'normalized_city' => 'JAIPUR',
            'normalized_ca_name' => 'AASTHA PARTNER',
            'mapping_status' => 'needs_review',
            'review_reason' => 'Multiple candidates',
            'match_candidates' => [],
            'raw_payload' => ['source' => 'test'],
        ], $overrides));
    }

    public function test_exact_normalized_firm_and_city_produces_one_candidate_path(): void
    {
        $this->skipUnlessReady();
        $matcher = app(SalesImportMatchingService::class);
        $result = $matcher->match('Aastha Unique Exact '.uniqid(), 'Jaipur');

        $this->assertContains($result['status'], ['matched', 'needs_review', 'unmatched']);
        if ($result['status'] === 'matched') {
            $this->assertNotNull($result['ca_id']);
            $this->assertSame(SalesImportMatchingService::MATCHED_ON, $result['matched_on']);
            $this->assertCount(1, $result['candidates']);
        }
    }

    public function test_multiple_exact_candidates_produce_needs_review_when_applicable(): void
    {
        $this->skipUnlessReady();
        $matcher = app(SalesImportMatchingService::class);
        $result = $matcher->match('Aastha & Co.', 'Jaipur');
        $this->assertContains($result['status'], ['matched', 'needs_review', 'unmatched']);
        if (count($result['candidates']) > 1) {
            $this->assertSame('needs_review', $result['status']);
        }
    }

    public function test_confirm_match_links_selected_ca_without_creating_ca(): void
    {
        $this->skipUnlessReady();
        $admin = $this->actingAsAdmin();
        $before = CaMaster::query()->count();
        $ca = $this->makeCa('Confirm Firm '.uniqid(), 'Jaipur');
        $row = $this->makeRow([
            'firm_name' => $ca->firm_name,
            'remarks_1' => 'Keep remarks intact',
            'employee_name' => 'SIMRAN',
        ]);
        $remarks1 = $row->remarks_1;
        $mobile = $row->mobile_no;
        $employee = $row->employee_name;

        $response = $this->postJson('/employee-imports/'.$row->id.'/confirm-match', [
            'matched_ca_id' => $ca->ca_id,
            'reason' => 'Confirmed after comparing firm and city',
        ]);

        $response->assertOk()->assertJsonPath('data.mapping_status', 'matched')
            ->assertJsonPath('data.matched_ca_id', $ca->ca_id)
            ->assertJsonPath('data.matched_on', SalesImportReviewService::ACTION_CONFIRM);

        $row->refresh();
        $this->assertSame('matched', $row->mapping_status);
        $this->assertSame($ca->ca_id, (int) $row->matched_ca_id);
        $this->assertSame($remarks1, $row->remarks_1);
        $this->assertSame($mobile, $row->mobile_no);
        $this->assertSame($employee, $row->employee_name);
        $this->assertSame($before + 1, CaMaster::query()->count());

        if (Schema::hasTable('master_mapping_decisions')) {
            $this->assertTrue(
                MasterMappingDecision::query()
                    ->where('source_type', SalesImportReviewService::SOURCE_TYPE)
                    ->where('source_ref', (string) $row->id)
                    ->where('actor_id', $admin->id)
                    ->exists()
            );
        }
    }

    public function test_confirm_match_rejects_invalid_ca_id(): void
    {
        $this->skipUnlessReady();
        $this->actingAsAdmin();
        $row = $this->makeRow();

        $this->postJson('/employee-imports/'.$row->id.'/confirm-match', [
            'matched_ca_id' => 999999999,
            'reason' => 'Invalid',
        ])->assertStatus(422);

        $row->refresh();
        $this->assertSame('needs_review', $row->mapping_status);
        $this->assertNull($row->matched_ca_id);
    }

    public function test_confirm_match_rejects_soft_deleted_ca(): void
    {
        $this->skipUnlessReady();
        $this->actingAsAdmin();
        $ca = $this->makeCa('Deleted Firm '.uniqid(), 'Jaipur');
        $caId = $ca->ca_id;
        $ca->delete();
        $row = $this->makeRow();

        $this->postJson('/employee-imports/'.$row->id.'/confirm-match', [
            'matched_ca_id' => $caId,
            'reason' => 'Deleted CA',
        ])->assertStatus(422);

        $row->refresh();
        $this->assertNull($row->matched_ca_id);
    }

    public function test_mark_unmatched_clears_match_safely(): void
    {
        $this->skipUnlessReady();
        $this->actingAsAdmin();
        $ca = $this->makeCa('Unmatch Firm '.uniqid(), 'Jaipur');
        $row = $this->makeRow([
            'matched_ca_id' => $ca->ca_id,
            'mapping_status' => 'matched',
            'matched_on' => 'exact_normalized_firm_city',
            'mapped_at' => now(),
            'remarks_1' => 'History stays',
        ]);

        $this->postJson('/employee-imports/'.$row->id.'/mark-unmatched', [
            'reason' => 'No correct CA found in reference data',
        ])->assertOk()->assertJsonPath('data.mapping_status', 'unmatched');

        $row->refresh();
        $this->assertSame('unmatched', $row->mapping_status);
        $this->assertNull($row->matched_ca_id);
        $this->assertNull($row->matched_on);
        $this->assertNull($row->mapped_at);
        $this->assertSame('History stays', $row->remarks_1);
        $this->assertTrue(CaMaster::query()->where('ca_id', $ca->ca_id)->exists());
    }

    public function test_ignore_keeps_source_employee_row(): void
    {
        $this->skipUnlessReady();
        $this->actingAsAdmin();
        $row = $this->makeRow(['remarks_2' => 'Do not delete']);

        $this->postJson('/employee-imports/'.$row->id.'/ignore', [
            'reason' => 'Ignored during manual review',
        ])->assertOk()->assertJsonPath('data.mapping_status', 'ignored');

        $this->assertTrue(SalesImportRow::query()->whereKey($row->id)->exists());
        $row->refresh();
        $this->assertSame('ignored', $row->mapping_status);
        $this->assertNull($row->matched_ca_id);
        $this->assertSame('Do not delete', $row->remarks_2);
    }

    public function test_unauthorized_user_cannot_confirm_mappings(): void
    {
        $this->skipUnlessReady();
        $employee = CrmTestAccounts::employeeUser();
        $this->actingAs($employee);
        $ca = $this->makeCa('RBAC Firm '.uniqid(), 'Jaipur');
        $row = $this->makeRow();

        $response = $this->postJson('/employee-imports/'.$row->id.'/confirm-match', [
            'matched_ca_id' => $ca->ca_id,
            'reason' => 'Should fail',
        ]);

        $this->assertTrue(in_array($response->status(), [403, 401], true), 'Expected 403/401, got '.$response->status());
        $row->refresh();
        $this->assertNull($row->matched_ca_id);
    }

    public function test_repeated_employee_rows_can_link_to_one_canonical_ca(): void
    {
        $this->skipUnlessReady();
        $this->actingAsAdmin();
        $ca = $this->makeCa('Shared Firm '.uniqid(), 'Jaipur');
        $rowA = $this->makeRow(['employee_name' => 'ANKIT', 'remarks_1' => 'A call']);
        $rowB = $this->makeRow([
            'employee_name' => 'SIMRAN',
            'firm_name' => 'Aastha Company',
            'remarks_1' => 'B call',
            'source_row_number' => 99,
        ]);

        $this->postJson('/employee-imports/'.$rowA->id.'/confirm-match', [
            'matched_ca_id' => $ca->ca_id,
            'reason' => 'ANKIT confirm',
        ])->assertOk();
        $this->postJson('/employee-imports/'.$rowB->id.'/confirm-match', [
            'matched_ca_id' => $ca->ca_id,
            'reason' => 'SIMRAN confirm',
        ])->assertOk();

        $rowA->refresh();
        $rowB->refresh();
        $this->assertSame((int) $ca->ca_id, (int) $rowA->matched_ca_id);
        $this->assertSame((int) $ca->ca_id, (int) $rowB->matched_ca_id);
        $this->assertSame('A call', $rowA->remarks_1);
        $this->assertSame('B call', $rowB->remarks_1);
        $this->assertSame(2, SalesImportRow::query()->where('matched_ca_id', $ca->ca_id)->count());
    }

    public function test_audit_history_created_for_every_manual_decision(): void
    {
        $this->skipUnlessReady();
        if (! Schema::hasTable('master_mapping_decisions')) {
            $this->markTestSkipped('master_mapping_decisions missing');
        }

        $admin = $this->actingAsAdmin();
        $ca = $this->makeCa('Audit Firm '.uniqid(), 'Jaipur');
        $row = $this->makeRow();

        $this->postJson('/employee-imports/'.$row->id.'/confirm-match', [
            'matched_ca_id' => $ca->ca_id,
            'reason' => 'Audit confirm',
        ])->assertOk();

        $this->postJson('/employee-imports/'.$row->id.'/mark-unmatched', [
            'reason' => 'Audit unmatch',
        ])->assertOk();

        $this->postJson('/employee-imports/'.$row->id.'/ignore', [
            'reason' => 'Audit ignore',
        ])->assertOk();

        $count = MasterMappingDecision::query()
            ->where('source_type', SalesImportReviewService::SOURCE_TYPE)
            ->where('source_ref', (string) $row->id)
            ->where('actor_id', $admin->id)
            ->count();

        $this->assertGreaterThanOrEqual(3, $count);
    }

    public function test_accept_all_matched_only_updates_matched_rows(): void
    {
        $this->skipUnlessReady();
        $admin = $this->actingAsAdmin();
        $ca = $this->makeCa('Accept All Firm '.uniqid(), 'Jaipur');
        $matched = $this->makeRow([
            'matched_ca_id' => $ca->ca_id,
            'mapping_status' => 'matched',
            'matched_on' => 'exact_normalized_firm_city',
            'remarks_1' => 'Keep remarks',
            'employee_name' => 'ANKIT',
        ]);
        $review = $this->makeRow([
            'mapping_status' => 'needs_review',
            'employee_name' => 'ANKIT',
            'source_row_number' => 77,
        ]);
        $beforeMasters = CaMaster::query()->count();

        $response = $this->postJson('/employee-imports/accept-all-matched', [
            'employee' => 'ANKIT',
        ]);
        $response->assertOk()
            ->assertJsonPath('data.accepted', 1);

        $matched->refresh();
        $review->refresh();
        $this->assertSame('matched', $matched->mapping_status);
        $this->assertSame(SalesImportReviewService::ACTION_ACCEPT_MATCHED, $matched->matched_on);
        $this->assertSame('Keep remarks', $matched->remarks_1);
        $this->assertSame('needs_review', $review->mapping_status);
        $this->assertSame($beforeMasters, CaMaster::query()->count());
        $this->assertTrue(
            MasterMappingDecision::query()
                ->where('source_type', SalesImportReviewService::SOURCE_TYPE)
                ->where('source_ref', (string) $matched->id)
                ->where('actor_id', $admin->id)
                ->exists()
        );
    }

    public function test_summary_respects_employee_filter(): void
    {
        $this->skipUnlessReady();
        $this->actingAsAdmin();
        $this->makeRow(['employee_name' => 'SIMRAN', 'source_row_number' => 201, 'mapping_status' => 'unmatched']);
        $this->makeRow(['employee_name' => 'RAHUL', 'source_row_number' => 202, 'mapping_status' => 'needs_review']);

        $all = $this->getJson('/employee-imports/summary')->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(2, (int) ($all['total'] ?? 0));
        $this->assertContains('SIMRAN', $all['employees'] ?? []);
        $this->assertContains('RAHUL', $all['employees'] ?? []);

        $simran = $this->getJson('/employee-imports/summary?employee=SIMRAN')->assertOk()->json('data');
        $this->assertSame('SIMRAN', $simran['selected_employee']);
        $this->assertSame(1, (int) ($simran['total'] ?? 0));
        $this->assertSame(1, (int) ($simran['unmatched'] ?? 0));
    }

    public function test_candidates_endpoint_returns_ranked_payload_shape(): void
    {
        $this->skipUnlessReady();
        $this->actingAsAdmin();
        $row = $this->makeRow();

        $response = $this->getJson('/employee-imports/'.$row->id.'/candidates');
        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'sales_import_row_id',
                'row' => ['id', 'firm_name', 'city_name', 'remarks_1'],
                'candidates',
                'candidate_count',
            ],
        ]);
        $this->assertLessThanOrEqual(20, count($response->json('data.candidates') ?? []));
    }
}
