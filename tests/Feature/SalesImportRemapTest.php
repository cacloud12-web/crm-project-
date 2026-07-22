<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\MasterMappingDecision;
use App\Models\SalesImportRow;
use App\Services\Mapping\SalesImportMatchingService;
use App\Services\Mapping\SalesImportRemapProtection;
use App\Services\Mapping\SalesImportRemapService;
use App\Services\Mapping\SalesImportReviewService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\Support\CrmTestAccounts;
use Tests\TestCase;

class SalesImportRemapTest extends TestCase
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

    private function makeRow(array $overrides = []): SalesImportRow
    {
        return SalesImportRow::query()->create(array_merge([
            'import_batch_id' => 88001,
            'source_file_name' => 'CA CloudDesk Leads - SIMRAN.csv',
            'source_row_number' => 2,
            'employee_name' => 'SIMRAN',
            'call_date' => now()->toDateString(),
            'ca_name' => 'Test CA',
            'firm_name' => 'Remap Firm '.uniqid(),
            'city_name' => 'Jaipur',
            'mobile_no' => '9000001111',
            'remarks_1' => 'keep-remarks',
            'remarks_2' => 'keep-r2',
            'mapping_status' => 'unmatched',
            'raw_payload' => ['x' => 1],
        ], $overrides));
    }

    private function okPreflight(): array
    {
        return [
            'ok' => true,
            'error' => null,
            'has_ca_firms' => true,
            'has_ca_addresses' => true,
            'has_ca_partners' => true,
            'firm_count' => 10,
            'has_normalized_firm' => true,
            'has_normalized_city' => true,
        ];
    }

    private function matchResult(string $status, ?int $caId = null, ?int $refId = null): array
    {
        return [
            'status' => $status,
            'ca_id' => $caId,
            'matched_reference_firm_id' => $refId,
            'matched_on' => $status === 'matched' ? SalesImportMatchingService::MATCHED_ON : ($status === 'needs_review' ? 'multiple_exact_normalized_firm_city' : null),
            'score' => $status === 'unmatched' ? null : 1.0,
            'reason' => $status === 'unmatched' ? 'No candidate' : null,
            'candidates' => $refId ? [['reference_firm_id' => $refId, 'ca_id' => $caId]] : [],
            'normalized_firm_name' => 'FIRM',
            'normalized_city' => 'JAIPUR',
        ];
    }

    private function service(SalesImportMatchingService $matcher): SalesImportRemapService
    {
        return new SalesImportRemapService($matcher, new SalesImportRemapProtection);
    }

    private function fakeMatcher(array $preflight, ?array $matchResult = null): SalesImportMatchingService
    {
        $matcher = Mockery::mock(SalesImportMatchingService::class);
        $matcher->shouldReceive('preflightCaReference')->andReturn($preflight);
        if ($matchResult !== null) {
            $matcher->shouldReceive('match')->andReturn($matchResult);
        }

        return $matcher;
    }

    public function test_failed_preflight_updates_zero_rows_and_zero_audit(): void
    {
        $this->skipUnlessReady();
        $row = $this->makeRow();
        $beforeAudit = Schema::hasTable('master_mapping_decisions') ? MasterMappingDecision::query()->count() : 0;
        $matcher = $this->fakeMatcher([
            'ok' => false,
            'error' => 'offline',
            'has_ca_firms' => false,
            'has_ca_addresses' => false,
            'has_ca_partners' => false,
            'firm_count' => 0,
            'has_normalized_firm' => false,
            'has_normalized_city' => false,
        ]);
        $matcher->shouldReceive('match')->never();

        $result = $this->service($matcher)->run(['all' => true]);
        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['updated']);
        $row->refresh();
        $this->assertSame('unmatched', $row->mapping_status);
        if (Schema::hasTable('master_mapping_decisions')) {
            $this->assertSame($beforeAudit, MasterMappingDecision::query()->count());
        }
    }

    public function test_dry_run_updates_no_rows_and_writes_no_audit(): void
    {
        $this->skipUnlessReady();
        $row = $this->makeRow();
        $beforeAudit = Schema::hasTable('master_mapping_decisions') ? MasterMappingDecision::query()->count() : 0;
        $beforeCa = CaMaster::query()->count();

        $result = $this->service($this->fakeMatcher($this->okPreflight(), $this->matchResult('matched', 11, 22)))
            ->run(['file' => $row->source_file_name, 'dry_run' => true]);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['dry_run']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['totals']['audit_rows_created'] ?? 0);
        $row->refresh();
        $this->assertSame('unmatched', $row->mapping_status);
        $this->assertSame('keep-remarks', $row->remarks_1);
        $this->assertSame($beforeCa, CaMaster::query()->count());
        if (Schema::hasTable('master_mapping_decisions')) {
            $this->assertSame($beforeAudit, MasterMappingDecision::query()->count());
        }
    }

    public function test_unmatched_becomes_matched_and_audited(): void
    {
        $this->skipUnlessReady();
        $this->actingAs(CrmTestAccounts::admin());
        $ca = CaMaster::query()->create(['firm_name' => 'F '.uniqid(), 'ca_name' => 'C', 'status' => 'New', 'rating' => 1]);
        $row = $this->makeRow(['firm_name' => $ca->firm_name]);
        $beforeCa = CaMaster::query()->count();
        $beforeAudit = Schema::hasTable('master_mapping_decisions')
            ? MasterMappingDecision::query()->where('source_type', SalesImportRemapService::SOURCE_TYPE)->count()
            : 0;

        $result = $this->service($this->fakeMatcher($this->okPreflight(), $this->matchResult('matched', (int) $ca->ca_id, 77)))
            ->run(['file' => $row->source_file_name]);

        $this->assertTrue($result['ok']);
        $this->assertGreaterThanOrEqual(1, $result['updated']);
        $row->refresh();
        $this->assertSame('matched', $row->mapping_status);
        $this->assertSame((int) $ca->ca_id, (int) $row->matched_ca_id);
        $this->assertSame('keep-remarks', $row->remarks_1);
        $this->assertSame('9000001111', $row->mobile_no);
        $this->assertSame($beforeCa, CaMaster::query()->count());
        if (Schema::hasTable('master_mapping_decisions')) {
            $this->assertGreaterThan($beforeAudit, MasterMappingDecision::query()->where('source_type', SalesImportRemapService::SOURCE_TYPE)->count());
        }
    }

    public function test_manual_confirmed_ignored_accepted_and_mark_unmatched_are_skipped(): void
    {
        $this->skipUnlessReady();
        $rows = [
            $this->makeRow(['mapping_status' => 'matched', 'matched_on' => SalesImportReviewService::ACTION_CONFIRM, 'matched_ca_id' => 1, 'source_row_number' => 10]),
            $this->makeRow(['mapping_status' => 'ignored', 'matched_on' => null, 'source_row_number' => 11, 'source_file_name' => 'x.csv']),
            $this->makeRow(['mapping_status' => 'matched', 'matched_on' => SalesImportReviewService::ACTION_ACCEPT_TOP, 'matched_ca_id' => 2, 'source_row_number' => 12, 'source_file_name' => 'y.csv']),
            $this->makeRow(['mapping_status' => 'matched', 'matched_on' => SalesImportReviewService::ACTION_ACCEPT_MATCHED, 'matched_ca_id' => 3, 'source_row_number' => 13, 'source_file_name' => 'z.csv']),
            $this->makeRow(['mapping_status' => 'unmatched', 'matched_on' => SalesImportReviewService::ACTION_UNMATCHED, 'source_row_number' => 14, 'source_file_name' => 'u.csv']),
        ];

        $matcher = $this->fakeMatcher($this->okPreflight());
        $matcher->shouldReceive('match')->never();
        $service = $this->service($matcher);

        foreach ($rows as $row) {
            $result = $service->run(['file' => $row->source_file_name]);
            $this->assertTrue($result['ok']);
            $row->refresh();
        }

        $this->assertSame(SalesImportReviewService::ACTION_CONFIRM, $rows[0]->matched_on);
        $this->assertSame('ignored', $rows[1]->mapping_status);
        $this->assertSame(SalesImportReviewService::ACTION_ACCEPT_TOP, $rows[2]->matched_on);
        $this->assertSame(SalesImportReviewService::ACTION_ACCEPT_MATCHED, $rows[3]->matched_on);
        $this->assertSame(SalesImportReviewService::ACTION_UNMATCHED, $rows[4]->matched_on);
    }

    public function test_include_manual_unmatched_allows_mark_unmatched_only(): void
    {
        $this->skipUnlessReady();
        $manual = $this->makeRow([
            'mapping_status' => 'matched',
            'matched_on' => SalesImportReviewService::ACTION_CONFIRM,
            'matched_ca_id' => 9,
            'source_row_number' => 20,
        ]);
        $unmatchedManual = $this->makeRow([
            'mapping_status' => 'unmatched',
            'matched_on' => SalesImportReviewService::ACTION_UNMATCHED,
            'source_row_number' => 21,
            'source_file_name' => 'manual-unmatched.csv',
        ]);

        $matcher = $this->fakeMatcher($this->okPreflight(), $this->matchResult('needs_review', null, 5));
        $service = $this->service($matcher);
        $service->run([
            'file' => $unmatchedManual->source_file_name,
            'include_manual_unmatched' => true,
        ]);

        $manual->refresh();
        $unmatchedManual->refresh();
        $this->assertSame(SalesImportReviewService::ACTION_CONFIRM, $manual->matched_on);
        $this->assertSame('needs_review', $unmatchedManual->mapping_status);
    }

    public function test_auto_matched_skipped_unless_include_auto_matched(): void
    {
        $this->skipUnlessReady();
        $row = $this->makeRow([
            'mapping_status' => 'matched',
            'matched_on' => SalesImportMatchingService::MATCHED_ON,
            'matched_ca_id' => 44,
        ]);

        $matcher = $this->fakeMatcher($this->okPreflight());
        $matcher->shouldReceive('match')->never();
        $this->service($matcher)->run(['file' => $row->source_file_name]);
        $row->refresh();
        $this->assertSame(44, (int) $row->matched_ca_id);

        $matcher2 = $this->fakeMatcher($this->okPreflight(), $this->matchResult('unmatched'));
        $this->service($matcher2)->run([
            'file' => $row->source_file_name,
            'include_auto_matched' => true,
        ]);
        $row->refresh();
        $this->assertSame('unmatched', $row->mapping_status);
    }

    public function test_batch_file_employee_scopes_and_no_scope_error(): void
    {
        $this->skipUnlessReady();
        $a = $this->makeRow(['import_batch_id' => 901, 'source_file_name' => 'a.csv', 'employee_name' => 'A', 'source_row_number' => 30]);
        $b = $this->makeRow(['import_batch_id' => 902, 'source_file_name' => 'b.csv', 'employee_name' => 'B', 'source_row_number' => 31]);

        $service = $this->service($this->fakeMatcher($this->okPreflight(), $this->matchResult('needs_review', null, 1)));
        $service->run(['batch' => 901]);
        $a->refresh();
        $b->refresh();
        $this->assertSame('needs_review', $a->mapping_status);
        $this->assertSame('unmatched', $b->mapping_status);

        $this->expectException(RuntimeException::class);
        $this->service($this->fakeMatcher($this->okPreflight()))->run([]);
    }

    public function test_unchanged_result_writes_no_audit(): void
    {
        $this->skipUnlessReady();
        $row = $this->makeRow([
            'mapping_status' => 'unmatched',
            'matched_on' => null,
            'matched_ca_id' => null,
        ]);
        $beforeAudit = Schema::hasTable('master_mapping_decisions') ? MasterMappingDecision::query()->count() : 0;

        $this->service($this->fakeMatcher($this->okPreflight(), $this->matchResult('unmatched')))
            ->run(['file' => $row->source_file_name]);

        if (Schema::hasTable('master_mapping_decisions')) {
            $this->assertSame($beforeAudit, MasterMappingDecision::query()->count());
        }
    }

    public function test_command_registered_and_no_scope_fails(): void
    {
        $this->skipUnlessReady();
        $exit = Artisan::call('sales-list:remap');
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Specify --all', Artisan::output());
    }

    public function test_protection_helper_reasons(): void
    {
        $this->skipUnlessReady();
        $protection = new SalesImportRemapProtection;
        $row = $this->makeRow(['mapping_status' => 'matched', 'matched_on' => SalesImportReviewService::ACTION_CONFIRM]);
        $info = $protection->inspect($row);
        $this->assertTrue($info['protected']);
        $this->assertStringContainsString('manual_confirmed', (string) $info['reason']);
    }
}
