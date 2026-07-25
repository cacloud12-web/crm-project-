<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\Employee;
use App\Models\MasterImportBatch;
use App\Models\SalesContact;
use App\Models\SalesHistory;
use App\Models\SalesImportRow;
use App\Models\SalesMappingReview;
use App\Models\SalesMasterLink;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesMappingSchemaTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    private const NEW_BATCH_COLUMNS = [
        'employee_id',
        'matched_count',
        'rejected_count',
        'skipped_count',
        'unmatched_count',
        'processing_ms',
        'column_map',
        'started_at',
        'finished_at',
    ];

    /** @var list<string> */
    private const NEW_SALES_ROW_COLUMNS = [
        'employee_id',
        'email',
        'website',
        'state_name',
        'address',
        'pincode',
        'call_status',
        'follow_up',
        'software',
        'sales_source',
        'normalized_mobile',
        'normalized_email',
        'extra_columns',
        'confidence_tier',
        'duplicate_of_row_id',
    ];

    /** @var list<string> */
    private const NEW_TABLES = [
        'sales_master_links',
        'sales_contacts',
        'sales_histories',
        'sales_mapping_reviews',
    ];

    /** Master identity / verification / OCR / Google fields that must not be altered by these migrations. */
    private const MASTER_PROTECTED_COLUMNS = [
        'ca_id',
        'ca_name',
        'firm_name',
        'mobile_no',
        'email_id',
        'is_verified',
        'verification_status',
        'data_quality_status',
        'data_quality_issue',
        'google_place_id',
        'google_rating',
        'google_maps_url',
        'verified_from_google',
        'source_ocr_document_id',
        'source_ocr_row_id',
        'ocr_match_status',
        'ocr_review_status',
        'ocr_city_text',
    ];

    private function skipUnlessBaseSchemaReady(): void
    {
        if (! Schema::hasTable('master_import_batches')
            || ! Schema::hasTable('sales_import_rows')
            || ! Schema::hasTable('ca_masters')
            || ! Schema::hasTable('employees')) {
            $this->markTestSkipped('Required base tables missing');
        }
    }

    public function test_sales_mapping_migrations_created_expected_schema(): void
    {
        $this->skipUnlessBaseSchemaReady();

        foreach (self::NEW_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table {$table}");
        }

        foreach (self::NEW_BATCH_COLUMNS as $col) {
            $this->assertTrue(
                Schema::hasColumn('master_import_batches', $col),
                "Missing master_import_batches.{$col}"
            );
        }

        foreach (self::NEW_SALES_ROW_COLUMNS as $col) {
            $this->assertTrue(
                Schema::hasColumn('sales_import_rows', $col),
                "Missing sales_import_rows.{$col}"
            );
        }

        // Reuse existing review_count — do not invent needs_review_count.
        $this->assertTrue(Schema::hasColumn('master_import_batches', 'review_count'));
        $this->assertFalse(Schema::hasColumn('master_import_batches', 'needs_review_count'));
    }

    public function test_existing_sales_import_rows_remain_intact_after_extension(): void
    {
        $this->skipUnlessBaseSchemaReady();

        $batch = MasterImportBatch::query()->create([
            'source_type' => 'employee_sales_list',
            'source_ref' => 'schema-test-'.uniqid(),
            'file_name' => 'legacy.csv',
            'status' => MasterImportBatch::STATUS_COMPLETED,
            'total_records' => 1,
        ]);

        $row = SalesImportRow::query()->create([
            'import_batch_id' => $batch->id,
            'source_file_name' => 'legacy.csv',
            'source_row_number' => 2,
            'employee_name' => 'Legacy Emp',
            'ca_name' => 'Legacy CA',
            'firm_name' => 'Legacy Firm',
            'mobile_no' => '9876543210',
            'city_name' => 'Jaipur',
            'mapping_status' => 'unmatched',
            'raw_payload' => ['Date' => '01-01-2026'],
        ]);

        $row->refresh();
        $this->assertSame('Legacy CA', $row->ca_name);
        $this->assertSame('Legacy Firm', $row->firm_name);
        $this->assertSame('9876543210', $row->mobile_no);
        $this->assertSame('unmatched', $row->mapping_status);
        $this->assertNull($row->email);
        $this->assertNull($row->confidence_tier);
        $this->assertSame(['Date' => '01-01-2026'], $row->raw_payload);
    }

    public function test_existing_batch_records_remain_intact_after_extension(): void
    {
        $this->skipUnlessBaseSchemaReady();

        $batch = MasterImportBatch::query()->create([
            'source_type' => 'employee_sales_list',
            'source_ref' => 'batch-intact-'.uniqid(),
            'file_name' => 'batch.csv',
            'status' => MasterImportBatch::STATUS_COMPLETED,
            'total_records' => 5,
            'created_count' => 1,
            'updated_count' => 2,
            'duplicate_count' => 0,
            'review_count' => 2,
            'failed_count' => 0,
        ]);

        $batch->refresh();
        $this->assertSame(5, (int) $batch->total_records);
        $this->assertSame(2, (int) $batch->review_count);
        $this->assertSame(0, (int) ($batch->matched_count ?? 0));
        $this->assertSame(0, (int) ($batch->rejected_count ?? 0));
        $this->assertNull($batch->column_map);
        $this->assertNull($batch->employee_id);
    }

    public function test_no_ca_masters_identity_columns_added_or_removed(): void
    {
        $this->skipUnlessBaseSchemaReady();

        foreach (self::MASTER_PROTECTED_COLUMNS as $col) {
            if (! Schema::hasColumn('ca_masters', $col)) {
                // Some OCR columns may be absent on older DBs — skip those only.
                if (str_starts_with($col, 'ocr_') || str_starts_with($col, 'source_ocr') || $col === 'data_quality_issue') {
                    continue;
                }
            }
            $this->assertTrue(
                Schema::hasColumn('ca_masters', $col) || str_starts_with($col, 'ocr_') || str_starts_with($col, 'source_ocr') || in_array($col, ['data_quality_status', 'data_quality_issue', 'verification_status'], true),
                "Unexpected loss of ca_masters.{$col}"
            );
        }

        // Sales Mapping must not add Sales identity columns onto ca_masters in v1.
        foreach ([
            'sales_mobile',
            'sales_email',
            'sales_source',
            'sales_history_id',
            'sales_master_link_id',
            'last_sales_import_at',
        ] as $forbidden) {
            $this->assertFalse(
                Schema::hasColumn('ca_masters', $forbidden),
                "ca_masters must not gain Sales identity column {$forbidden}"
            );
        }

        $master = CaMaster::query()->create([
            'ca_name' => 'Schema Guard CA',
            'firm_name' => 'Schema Guard Firm',
            'mobile_no' => '9111111111',
            'status' => 'New',
            'rating' => 1,
        ]);
        $master->refresh();
        $before = [];
        foreach (self::MASTER_PROTECTED_COLUMNS as $col) {
            if ($col === 'ca_id' || ! Schema::hasColumn('ca_masters', $col)) {
                continue;
            }
            $before[$col] = $master->getAttributes()[$col] ?? null;
        }

        // Touch Sales schema tables only — must not mutate Master identity.
        SalesImportRow::query()->create([
            'import_batch_id' => MasterImportBatch::query()->create([
                'source_type' => 'employee_sales_list',
                'source_ref' => 'guard-'.uniqid(),
                'file_name' => 'g.csv',
                'status' => MasterImportBatch::STATUS_PROCESSING,
                'total_records' => 0,
            ])->id,
            'source_file_name' => 'g.csv',
            'source_row_number' => 1,
            'ca_name' => 'x',
            'mapping_status' => 'unmatched',
        ]);

        $master->refresh();
        foreach ($before as $key => $value) {
            $this->assertSame(
                $value,
                $master->getAttributes()[$key] ?? null,
                "ca_masters.{$key} must remain unchanged"
            );
        }
    }

    public function test_foreign_keys_use_production_primary_keys(): void
    {
        $this->skipUnlessBaseSchemaReady();

        $this->assertSame('ca_id', (new CaMaster)->getKeyName());
        $this->assertSame('employee_id', (new Employee)->getKeyName());
        $this->assertSame('id', (new MasterImportBatch)->getKeyName());
        $this->assertSame('id', (new SalesImportRow)->getKeyName());

        $employee = $this->crmEmployee();
        $master = CaMaster::query()->create([
            'ca_name' => 'FK CA',
            'firm_name' => 'FK Firm',
            'status' => 'New',
            'rating' => 1,
        ]);
        $batch = MasterImportBatch::query()->create([
            'source_type' => 'employee_sales_list',
            'source_ref' => 'fk-'.uniqid(),
            'file_name' => 'fk.csv',
            'status' => MasterImportBatch::STATUS_COMPLETED,
            'total_records' => 1,
        ]);
        $batch->forceFill(['employee_id' => $employee->employee_id])->save();
        $row = SalesImportRow::query()->create([
            'import_batch_id' => $batch->id,
            'source_file_name' => 'fk.csv',
            'source_row_number' => 2,
            'ca_name' => 'FK CA',
            'mapping_status' => 'matched',
            'matched_ca_id' => $master->ca_id,
        ]);
        $row->forceFill(['employee_id' => $employee->employee_id])->save();

        $link = SalesMasterLink::query()->create([
            'ca_id' => $master->ca_id,
            'import_batch_id' => $batch->id,
            'sales_import_row_id' => $row->id,
            'employee_id' => $employee->employee_id,
            'match_tier' => 'firm_ca_city',
            'confidence' => 0.9500,
            'linked_at' => now(),
        ]);

        $this->assertSame((int) $master->ca_id, (int) $link->ca_id);
        $this->assertSame((int) $employee->employee_id, (int) $link->employee_id);
        $this->assertTrue($link->caMaster()->is($master));
        $this->assertTrue($link->employee()->is($employee));
        $this->assertTrue($link->salesImportRow()->is($row));
        $this->assertTrue($link->importBatch()->is($batch));
    }

    public function test_one_sales_row_can_have_only_one_master_link(): void
    {
        $this->skipUnlessBaseSchemaReady();

        [$master, $batch, $row] = $this->seedLinkedTriplet();

        SalesMasterLink::query()->create([
            'ca_id' => $master->ca_id,
            'import_batch_id' => $batch->id,
            'sales_import_row_id' => $row->id,
            'match_tier' => 'firm_mobile',
            'linked_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        SalesMasterLink::query()->create([
            'ca_id' => $master->ca_id,
            'import_batch_id' => $batch->id,
            'sales_import_row_id' => $row->id,
            'match_tier' => 'ca_mobile',
            'linked_at' => now(),
        ]);
    }

    public function test_one_sales_row_creates_one_append_only_history(): void
    {
        $this->skipUnlessBaseSchemaReady();

        [$master, $batch, $row] = $this->seedLinkedTriplet();

        $history = SalesHistory::query()->create([
            'ca_id' => $master->ca_id,
            'import_batch_id' => $batch->id,
            'sales_import_row_id' => $row->id,
            'remarks' => 'First note',
            'imported_at' => now(),
        ]);

        $this->assertSame('First note', $history->remarks);
        $this->assertTrue($row->salesHistory()->is($history));

        $this->expectException(QueryException::class);
        SalesHistory::query()->create([
            'ca_id' => $master->ca_id,
            'import_batch_id' => $batch->id,
            'sales_import_row_id' => $row->id,
            'remarks' => 'Duplicate history forbidden',
            'imported_at' => now(),
        ]);
    }

    public function test_multiple_employees_can_create_separate_histories_for_one_master(): void
    {
        $this->skipUnlessBaseSchemaReady();

        $master = CaMaster::query()->create([
            'ca_name' => 'Multi Emp CA',
            'firm_name' => 'Multi Firm',
            'status' => 'New',
            'rating' => 1,
        ]);
        $empA = $this->crmEmployee();
        $empB = Employee::query()->create([
            'name' => 'Second Sales Emp '.uniqid(),
            'email_id' => 'sales-schema-'.uniqid().'@example.test',
            'role' => 'employee',
            'status' => 'active',
        ]);

        $batch = MasterImportBatch::query()->create([
            'source_type' => 'employee_sales_list',
            'source_ref' => 'multi-'.uniqid(),
            'file_name' => 'multi.csv',
            'status' => MasterImportBatch::STATUS_COMPLETED,
            'total_records' => 2,
        ]);
        $rowA = SalesImportRow::query()->create([
            'import_batch_id' => $batch->id,
            'source_file_name' => 'a.csv',
            'source_row_number' => 2,
            'ca_name' => 'Multi Emp CA',
            'mapping_status' => 'matched',
            'matched_ca_id' => $master->ca_id,
        ]);
        $rowA->forceFill(['employee_id' => $empA->employee_id])->save();
        $rowB = SalesImportRow::query()->create([
            'import_batch_id' => $batch->id,
            'source_file_name' => 'b.csv',
            'source_row_number' => 2,
            'ca_name' => 'Multi Emp CA',
            'mapping_status' => 'matched',
            'matched_ca_id' => $master->ca_id,
        ]);
        $rowB->forceFill(['employee_id' => $empB->employee_id])->save();

        SalesHistory::query()->create([
            'ca_id' => $master->ca_id,
            'import_batch_id' => $batch->id,
            'sales_import_row_id' => $rowA->id,
            'employee_id' => $empA->employee_id,
            'remarks' => 'From A',
            'imported_at' => now(),
        ]);
        SalesHistory::query()->create([
            'ca_id' => $master->ca_id,
            'import_batch_id' => $batch->id,
            'sales_import_row_id' => $rowB->id,
            'employee_id' => $empB->employee_id,
            'remarks' => 'From B',
            'imported_at' => now(),
        ]);

        $this->assertSame(2, $master->salesHistories()->count());
        $this->assertSame(
            [$empA->employee_id, $empB->employee_id],
            $master->salesHistories()->orderBy('id')->pluck('employee_id')->map(fn ($id) => (int) $id)->all()
        );
    }

    public function test_review_records_default_to_pending(): void
    {
        $this->skipUnlessBaseSchemaReady();

        [$master, $batch, $row] = $this->seedLinkedTriplet();
        unset($master);

        $review = SalesMappingReview::query()->create([
            'import_batch_id' => $batch->id,
            'sales_import_row_id' => $row->id,
            'reason' => 'no_match',
            'candidate_ca_ids' => [],
        ]);

        $this->assertSame(SalesMappingReview::STATUS_PENDING, $review->status);
        $this->assertSame('pending', $review->fresh()->status);
        $this->assertNull($review->approved_ca_id);
        $this->assertNull($review->reviewed_at);
    }

    public function test_sales_contact_can_attach_without_touching_master_mobile(): void
    {
        $this->skipUnlessBaseSchemaReady();

        $master = CaMaster::query()->create([
            'ca_name' => 'Contact CA',
            'firm_name' => 'Contact Firm',
            'mobile_no' => '9000000001',
            'status' => 'New',
            'rating' => 1,
        ]);
        [$batch, $row] = $this->seedBatchAndRow($master);

        SalesContact::query()->create([
            'ca_id' => $master->ca_id,
            'import_batch_id' => $batch->id,
            'sales_import_row_id' => $row->id,
            'sales_mobile' => '9000000099',
            'normalized_sales_mobile' => '9000000099',
            'is_primary_sales' => true,
        ]);

        $master->refresh();
        $this->assertSame('9000000001', $master->mobile_no);
        $this->assertSame(1, $master->salesContacts()->count());
    }

    /**
     * @return array{0: CaMaster, 1: MasterImportBatch, 2: SalesImportRow}
     */
    private function seedLinkedTriplet(): array
    {
        $master = CaMaster::query()->create([
            'ca_name' => 'Link CA '.uniqid(),
            'firm_name' => 'Link Firm',
            'status' => 'New',
            'rating' => 1,
        ]);
        [$batch, $row] = $this->seedBatchAndRow($master);

        return [$master, $batch, $row];
    }

    /**
     * @return array{0: MasterImportBatch, 1: SalesImportRow}
     */
    private function seedBatchAndRow(CaMaster $master): array
    {
        $batch = MasterImportBatch::query()->create([
            'source_type' => 'employee_sales_list',
            'source_ref' => 'row-'.uniqid(),
            'file_name' => 'row.csv',
            'status' => MasterImportBatch::STATUS_COMPLETED,
            'total_records' => 1,
        ]);
        $row = SalesImportRow::query()->create([
            'import_batch_id' => $batch->id,
            'source_file_name' => 'row.csv',
            'source_row_number' => 2,
            'ca_name' => $master->ca_name,
            'mapping_status' => 'matched',
            'matched_ca_id' => $master->ca_id,
        ]);

        return [$batch, $row];
    }
}
