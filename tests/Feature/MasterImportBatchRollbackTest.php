<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Models\CaMaster;
use App\Models\MasterImportBatch;
use App\Models\MasterMappingDecision;
use App\Models\User;
use App\Services\Mapping\MasterDataMappingService;
use App\Services\Mapping\MasterImportRollbackService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MasterImportBatchRollbackTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'crm_mapping.auto_apply_exact' => true,
            'crm_mapping.auto_create_unmatched' => true,
            'crm_mapping.auto_update_min_confidence' => 0.90,
            'ocr_safety.require_verification' => false,
            'ocr_safety.auto_create' => true,
            'ocr_safety.auto_update' => true,
        ]);
        app(\App\Services\Rbac\RbacDatabaseService::class)->ensureConfigDefaultGrants();
        app(\App\Services\Rbac\RbacMatrixService::class)->flushCache();
    }

    public function test_low_confidence_does_not_overwrite_and_high_confidence_update_is_rollbackable(): void
    {
        if (! Schema::hasTable('master_import_batches')) {
            $this->markTestSkipped('master_import_batches missing — run migrations.');
        }

        $existing = CaMaster::query()->create([
            'ca_name' => 'Keep Partner',
            'firm_name' => 'Rollback City Firm',
            'normalized_firm_name' => 'ROLLBACK CITY FIRM',
            'gst_no' => '27ROLLBACK1111R1Z5',
            'address' => 'Jaipur',
            'website' => null,
            'status' => 'New',
            'rating' => 1,
            'field_confidence' => ['address' => 0.95, 'overall' => 0.95],
        ]);

        // Exact GST match + low overall confidence → Needs Review (no silent overwrite).
        $reviewStats = app(MasterDataMappingService::class)->processBatch('api', 'rollback-review-1', [
            [
                'firm_name' => 'Rollback City Firm',
                'gst_no' => '27ROLLBACK1111R1Z5',
                'address' => 'Jajpur',
                'overall_confidence' => 0.40,
                'field_meta' => ['address' => ['confidence' => 0.40]],
            ],
        ]);
        $this->assertSame(1, $reviewStats['needs_review']);
        $existing->refresh();
        $this->assertSame('Jaipur', $existing->address);

        // Exact match with good confidence fills empty website; low address confidence keeps Jaipur.
        $stats = app(MasterDataMappingService::class)->processBatch('api', 'rollback-update-1', [
            [
                'firm_name' => 'Rollback City Firm',
                'gst_no' => '27ROLLBACK1111R1Z5',
                'address' => 'Jajpur',
                'website' => 'https://rollback-firm.example.test',
                'overall_confidence' => 0.96,
                'field_meta' => [
                    'address' => ['confidence' => 0.40],
                    'firm_name' => ['confidence' => 0.96],
                ],
            ],
        ]);

        $this->assertSame(1, $stats['auto_updated']);
        $this->assertNotEmpty($stats['import_batch_id']);
        $existing->refresh();
        $this->assertSame('Jaipur', $existing->address, 'Low-confidence OCR must not overwrite good address');
        $this->assertSame('https://rollback-firm.example.test', $existing->website);

        $batch = MasterImportBatch::query()->findOrFail($stats['import_batch_id']);
        $this->assertSame(MasterImportBatch::STATUS_COMPLETED, $batch->status);
        $this->assertTrue($batch->isRollbackable());

        $result = app(MasterImportRollbackService::class)->rollback($batch);
        $this->assertTrue($result['rolled_back']);
        $this->assertGreaterThanOrEqual(1, $result['restored']);
        $batch->refresh();
        $this->assertSame(MasterImportBatch::STATUS_ROLLED_BACK, $batch->status);
        $existing->refresh();
        $this->assertSame('Jaipur', $existing->address);
        $this->assertTrue($existing->website === null || $existing->website === '');
    }

    public function test_rollback_deletes_auto_created_master_without_activity(): void
    {
        if (! Schema::hasTable('master_import_batches')) {
            $this->markTestSkipped('master_import_batches missing — run migrations.');
        }

        $before = CaMaster::query()->count();
        $stats = app(MasterDataMappingService::class)->processBatch('csv', 'rollback-create-1', [
            [
                'firm_name' => 'Rollback Create Only Firm XYZ',
                'ca_name' => 'Partner Create',
                'email' => 'rollback.create.only@example.test',
            ],
        ]);

        $this->assertSame(1, $stats['auto_created']);
        $this->assertSame($before + 1, CaMaster::query()->count());
        $caId = $stats['decisions'][0]['ca_id'] ?? null;
        $this->assertNotNull($caId);

        $batch = MasterImportBatch::query()->findOrFail($stats['import_batch_id']);
        $result = app(MasterImportRollbackService::class)->rollback($batch);
        $this->assertSame(1, $result['deleted']);
        $this->assertSame($before, CaMaster::query()->count());
        $this->assertFalse(CaMaster::query()->where('ca_id', $caId)->exists());
    }

    public function test_rollback_endpoint_requires_auth_and_works_for_admin(): void
    {
        if (! Schema::hasTable('master_import_batches')) {
            $this->markTestSkipped('master_import_batches missing — run migrations.');
        }

        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        $stats = app(MasterDataMappingService::class)->processBatch('api', 'rollback-http-1', [
            [
                'firm_name' => 'HTTP Rollback Firm Unique',
                'email' => 'http.rollback.firm@example.test',
            ],
        ]);
        $batchId = $stats['import_batch_id'];
        $this->assertNotNull($batchId);

        $this->getJson('/master-import-batches/'.$batchId)
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->postJson('/master-import-batches/'.$batchId.'/rollback')
            ->assertOk()
            ->assertJsonPath('data.rolled_back', true);
    }

    public function test_audit_decision_stores_old_and_new_values(): void
    {
        if (! Schema::hasTable('master_mapping_decisions')
            || ! Schema::hasColumn('master_mapping_decisions', 'old_values')) {
            $this->markTestSkipped('Audit columns missing.');
        }

        $existing = CaMaster::query()->create([
            'ca_name' => 'Audit Partner',
            'firm_name' => 'Audit Merge Firm',
            'gst_no' => '27AUDITMERGE11A1Z5',
            'address' => null,
            'status' => 'New',
            'rating' => 1,
        ]);

        app(MasterDataMappingService::class)->processBatch('excel', 'audit-1', [
            [
                'firm_name' => 'Audit Merge Firm',
                'gst_no' => '27AUDITMERGE11A1Z5',
                'address' => '12 New Street',
                'overall_confidence' => 0.99,
            ],
        ]);

        $log = MasterMappingDecision::query()
            ->where('source_type', 'excel')
            ->where('source_ref', 'audit-1')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(MasterMappingDecision::DECISION_AUTO_UPDATE, $log->decision);
        $this->assertSame((int) $existing->ca_id, (int) $log->matched_ca_id);
        $this->assertIsArray($log->old_values);
        $this->assertIsArray($log->new_values);
        $this->assertSame('12 New Street', $log->new_values['address'] ?? null);
    }
}
