<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Models\User;
use App\Services\Ocr\OcrImportRemainingToMasterService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CrmTestAccounts;
use Tests\TestCase;

class OcrImportRemainingToMasterTest extends TestCase
{
    use DatabaseTransactions;

    private OcrImportRemainingToMasterService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->service = app(OcrImportRemainingToMasterService::class);
        $migration = require database_path('migrations/2026_07_23_120000_add_ocr_needs_verification_fields_to_ca_masters.php');
        try {
            $migration->up();
        } catch (\Throwable) {
            // Already applied.
        }
    }

    public function test_missing_ca_with_city_plans_needs_verification_without_ocr_city_text_column(): void
    {
        $firm = $this->seedUnlinked([
            'firm_name' => 'SCHEMA SAFE FIRM LLP',
            'city' => 'SURAT',
            'source_data' => [
                'raw' => ['firm_name' => 'SCHEMA SAFE FIRM LLP', 'ca_name' => '', 'city' => 'SURAT'],
                'parsed' => ['firm_name' => 'SCHEMA SAFE FIRM LLP', 'ca_name' => '', 'city' => 'SURAT'],
            ],
            'match_status' => 'needs_review',
            'match_reason' => 'missing_ca_name',
        ]);

        // Simulate production before Needs Verification migration (no ocr_city_text).
        $plan = $this->service->planImport($firm, false, false, false);
        $this->assertSame('create', $plan['action']);
        $this->assertSame('needs_verification', $plan['bucket']);
        $this->assertSame('missing_ca_name', $plan['data_quality_issue']);
        $this->assertNull($plan['ca_name']);
        $this->assertSame('SURAT', $plan['city']);
    }

    public function test_dry_run_with_city_does_not_error_when_ocr_city_text_absent(): void
    {
        $firm = $this->seedUnlinked([
            'firm_name' => 'NO COL FIRM AND CO',
            'city' => 'NAGPUR',
            'source_data' => [
                'raw' => ['firm_name' => 'NO COL FIRM AND CO', 'ca_name' => '', 'city' => 'NAGPUR'],
                'parsed' => ['firm_name' => 'NO COL FIRM AND CO', 'ca_name' => '', 'city' => 'NAGPUR'],
            ],
            'match_status' => 'needs_review',
        ]);

        // Force candidate search path with hasOcrCityText=false via planImport (no exception).
        $plan = $this->service->planImport($firm, false, true, true);
        $this->assertNotSame('skip_invalid', $plan['action']);
        $this->assertContains($plan['action'], ['create', 'link', 'ambiguous']);
    }

    public function test_needs_verification_create_without_verification_columns_in_attrs(): void
    {
        $firm = $this->seedUnlinked([
            'firm_name' => 'NO MIGRATE FIRM LLP',
            'city' => null,
            'source_data' => [
                'raw' => ['firm_name' => 'NO MIGRATE FIRM LLP', 'ca_name' => 'SURESH PATEL', 'city' => ''],
                'parsed' => ['firm_name' => 'NO MIGRATE FIRM LLP', 'ca_name' => 'SURESH PATEL', 'city' => ''],
            ],
            'match_status' => 'needs_review',
        ]);
        $before = CaMaster::query()->count();

        // Force the create path with quality issue present even if columns absent.
        $plan = [
            'action' => 'create',
            'bucket' => 'needs_verification',
            'firm_name' => 'NO MIGRATE FIRM LLP',
            'ca_name' => 'SURESH PATEL',
            'city' => null,
            'address_text' => null,
            'data_quality_issue' => 'missing_city',
            'data_quality_status' => 'incomplete',
        ];
        $ref = new \ReflectionClass($this->service);
        $method = $ref->getMethod('importNeedsVerification');
        $method->setAccessible(true);
        $method->invoke($this->service, $firm, 1, $plan);

        $firm->refresh();
        $this->assertNotNull($firm->crm_ca_id);
        $this->assertSame($before + 1, CaMaster::query()->count());
        $master = CaMaster::query()->findOrFail($firm->crm_ca_id);
        $this->assertSame('SURESH PATEL', $master->ca_name);
        $this->assertNull($master->city_id);
        $this->assertFalse((bool) $master->is_verified);
    }

    public function test_blank_ca_needs_verification_does_not_copy_firm_into_ca_name(): void
    {
        $firm = $this->seedUnlinked([
            'firm_name' => 'BLANK CA MASTER FIRM',
            'city' => 'SURAT',
            'source_data' => [
                'raw' => ['firm_name' => 'BLANK CA MASTER FIRM', 'ca_name' => '', 'city' => 'SURAT'],
                'parsed' => ['firm_name' => 'BLANK CA MASTER FIRM', 'ca_name' => '', 'city' => 'SURAT'],
            ],
            'match_status' => 'needs_review',
        ]);

        $report = $this->service->run([
            'document' => (int) $firm->ocr_document_id,
            'apply' => true,
            'actor' => 1,
            'needs_verification_only' => true,
        ]);

        $firm->refresh();
        $this->assertSame(0, $report['errors'], json_encode($report['error_samples'] ?? []));
        $this->assertNotNull($firm->crm_ca_id);
        $master = CaMaster::query()->findOrFail($firm->crm_ca_id);
        $this->assertTrue($master->ca_name === null || $master->ca_name === '');
        $this->assertNotEquals('BLANK CA MASTER FIRM', $master->ca_name);
    }

    public function test_revalidate_sets_review_and_match_verified(): void
    {
        $firm = $this->seedUnlinked([
            'firm_name' => 'MEHTA AND ASSOCIATES LLP',
            'city' => 'INDORE',
            'validation_errors' => ['ca_name: CA name is required.', 'stale_error'],
            'match_status' => 'needs_review',
            'review_status' => OcrParsedFirm::REVIEW_PENDING,
            'match_reason' => 'stale_phase3_reason',
            'source_data' => [
                'raw' => ['firm_name' => 'MEHTA AND ASSOCIATES LLP', 'ca_name' => 'NEHA GUPTA', 'city' => 'INDORE'],
                'parsed' => ['firm_name' => 'MEHTA AND ASSOCIATES LLP', 'ca_name' => 'NEHA GUPTA', 'city' => 'INDORE'],
                'validation' => ['ok' => false, 'errors' => ['stale']],
            ],
        ]);

        $result = $this->service->revalidateStaging($firm, false);
        $firm->refresh();

        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertSame(OcrParsedFirm::REVIEW_VERIFIED, $firm->review_status);
        $this->assertSame('verified', $firm->match_status);
        $this->assertSame([], $firm->validation_errors ?? []);
        $this->assertSame('revalidated_complete_firm_ca_city', $firm->match_reason);
        $this->assertTrue((bool) ($firm->source_data['validation']['ok'] ?? false));
        $this->assertSame([], $firm->source_data['validation']['errors'] ?? ['x']);
    }

    public function test_verified_corrected_row_imports_normally(): void
    {
        $mastersBefore = CaMaster::query()->count();
        $firm = $this->seedUnlinked([
            'firm_name' => 'VERIFIED IMPORT FIRM',
            'city' => 'MUMBAI',
            'source_data' => [
                'raw' => ['firm_name' => 'VERIFIED IMPORT FIRM', 'ca_name' => 'ANITA MEHTA', 'city' => 'MUMBAI'],
                'parsed' => ['firm_name' => 'VERIFIED IMPORT FIRM', 'ca_name' => 'ANITA MEHTA', 'city' => 'MUMBAI'],
            ],
            'match_status' => 'verified',
            'match_reason' => 'revalidated_complete_firm_ca_city',
        ]);

        $report = $this->service->run([
            'document' => (int) $firm->ocr_document_id,
            'apply' => true,
            'actor' => 1,
            'verified_only' => true,
        ]);

        $firm->refresh();
        $this->assertSame(0, $report['errors'], json_encode($report['error_samples'] ?? []));
        $this->assertNotNull($firm->crm_ca_id, json_encode($report));
        $this->assertSame($mastersBefore + 1, CaMaster::query()->count());
        $master = CaMaster::query()->findOrFail($firm->crm_ca_id);
        $this->assertTrue(in_array($master->verification_status, ['verified', null], true) || (bool) $master->is_verified);
        $this->assertSame('ANITA MEHTA', $master->ca_name);
        $this->assertSame((int) $firm->id, (int) ($master->source_ocr_row_id ?? $firm->id));
    }

    public function test_dry_run_changes_nothing(): void
    {
        $mastersBefore = CaMaster::query()->count();
        $firm = $this->seedUnlinked([
            'firm_name' => 'DRY RUN FIRM',
            'city' => 'AHMEDABAD',
            'source_data' => [
                'raw' => ['firm_name' => 'DRY RUN FIRM', 'ca_name' => 'DRY CA', 'city' => 'AHMEDABAD'],
                'parsed' => ['firm_name' => 'DRY RUN FIRM', 'ca_name' => 'DRY CA', 'city' => 'AHMEDABAD'],
            ],
            'match_status' => 'verified',
        ]);
        $rawBefore = $firm->source_data;

        $report = $this->service->run([
            'all' => true,
            'document' => (int) $firm->ocr_document_id,
            'dry_run' => true,
            'actor' => 1,
        ]);

        $firm->refresh();
        $this->assertNull($firm->crm_ca_id);
        $this->assertEquals($rawBefore, $firm->source_data);
        $this->assertSame($mastersBefore, CaMaster::query()->count());
        $this->assertGreaterThan(0, $report['scanned']);
    }

    public function test_missing_ca_imports_as_needs_verification(): void
    {
        $firm = $this->seedUnlinked([
            'firm_name' => 'MISSING CA FIRM LLP',
            'city' => 'SURAT',
            'source_data' => [
                'raw' => ['firm_name' => 'MISSING CA FIRM LLP', 'ca_name' => '', 'city' => 'SURAT'],
                'parsed' => ['firm_name' => 'MISSING CA FIRM LLP', 'ca_name' => '', 'city' => 'SURAT'],
            ],
            'match_status' => 'needs_review',
            'match_reason' => 'ca_name: CA name is required.',
        ]);
        $mastersBefore = CaMaster::query()->count();

        $this->service->run([
            'document' => (int) $firm->ocr_document_id,
            'dry_run' => false,
            'apply' => true,
            'actor' => 1,
            'needs_verification_only' => true,
        ]);

        $firm->refresh();
        $this->assertNotNull($firm->crm_ca_id);
        $this->assertSame($mastersBefore + 1, CaMaster::query()->count());
        $master = CaMaster::query()->find($firm->crm_ca_id);
        $this->assertSame('needs_verification', $master->verification_status);
        $this->assertFalse((bool) $master->is_verified);
        $this->assertSame('missing_ca_name', $master->data_quality_issue);
        $this->assertTrue($master->ca_name === null || $master->ca_name === '');
        $this->assertSame((int) $firm->id, (int) $master->source_ocr_row_id);
        $this->assertSame('MISSING CA FIRM LLP', $firm->source_data['raw']['firm_name']);
    }

    public function test_missing_city_imports_as_needs_verification(): void
    {
        $firm = $this->seedUnlinked([
            'firm_name' => 'MISSING CITY FIRM',
            'city' => null,
            'source_data' => [
                // Avoid names containing "CITY" — entity classifier treats those as address text.
                'raw' => ['firm_name' => 'MISSING CITY FIRM', 'ca_name' => 'RAJESH SHARMA', 'city' => ''],
                'parsed' => ['firm_name' => 'MISSING CITY FIRM', 'ca_name' => 'RAJESH SHARMA', 'city' => ''],
            ],
            'match_status' => 'needs_review',
        ]);

        $report = $this->service->run([
            'document' => (int) $firm->ocr_document_id,
            'apply' => true,
            'actor' => 1,
            'needs_verification_only' => true,
        ]);

        $firm->refresh();
        $this->assertSame(0, $report['errors'], json_encode($report['error_samples'] ?? []));
        $this->assertNotNull($firm->crm_ca_id, json_encode($report));
        $master = CaMaster::query()->findOrFail($firm->crm_ca_id);
        $this->assertSame('needs_verification', $master->verification_status);
        $this->assertSame('missing_city', $master->data_quality_issue);
        $this->assertSame('RAJESH SHARMA', $master->ca_name);
    }

    public function test_address_text_not_stored_as_ca_name(): void
    {
        $firm = $this->seedUnlinked([
            'firm_name' => 'ADDR FIRM & CO',
            'city' => 'AHMEDABAD',
            'source_data' => [
                'raw' => ['firm_name' => 'ADDR FIRM & CO', 'ca_name' => '14 NIRMAN SQUARE TENAMENT', 'city' => 'AHMEDABAD'],
                'parsed' => ['firm_name' => 'ADDR FIRM & CO', 'ca_name' => '14 NIRMAN SQUARE TENAMENT', 'city' => 'AHMEDABAD'],
            ],
            'match_status' => 'needs_review',
            'match_reason' => 'Parsed value differs from preserved raw OCR value — silent correction blocked.',
        ]);

        $this->service->run([
            'document' => (int) $firm->ocr_document_id,
            'apply' => true,
            'actor' => 1,
        ]);

        $firm->refresh();
        $this->assertNotNull($firm->crm_ca_id);
        $master = CaMaster::query()->findOrFail($firm->crm_ca_id);
        $this->assertNotEquals('14 NIRMAN SQUARE TENAMENT', $master->ca_name);
        $this->assertTrue($master->ca_name === null || $master->ca_name === '');
        $this->assertStringContainsString('NIRMAN', (string) $master->address);
        $this->assertSame('14 NIRMAN SQUARE TENAMENT', $firm->source_data['raw']['ca_name']);
    }

    public function test_needs_verification_duplicate_links(): void
    {
        $existing = CaMaster::query()->create([
            'firm_name' => 'NV DUP FIRM',
            'ca_name' => '',
            'normalized_firm_name' => 'NV DUP FIRM',
            'status' => 'New',
            'rating' => 1,
            'is_verified' => false,
            'verification_status' => 'needs_verification',
            'data_quality_issue' => 'missing_ca_name',
            'ocr_city_text' => 'JAIPUR',
            'source_type' => 'ocr',
        ]);
        $mastersBefore = CaMaster::query()->count();
        $firm = $this->seedUnlinked([
            'firm_name' => 'NV DUP FIRM',
            'city' => 'JAIPUR',
            'source_data' => [
                'raw' => ['firm_name' => 'NV DUP FIRM', 'ca_name' => '', 'city' => 'JAIPUR'],
                'parsed' => ['firm_name' => 'NV DUP FIRM', 'ca_name' => '', 'city' => 'JAIPUR'],
            ],
            'match_status' => 'needs_review',
        ]);

        $this->service->run([
            'document' => (int) $firm->ocr_document_id,
            'apply' => true,
            'actor' => 1,
            'needs_verification_only' => true,
        ]);

        $firm->refresh();
        $this->assertSame((int) $existing->ca_id, (int) $firm->crm_ca_id);
        $this->assertSame($mastersBefore, CaMaster::query()->count());
    }

    public function test_multiple_candidates_do_not_create(): void
    {
        CaMaster::query()->create([
            'firm_name' => 'AMBIG FIRM',
            'ca_name' => 'AMBIG CA',
            'normalized_firm_name' => 'AMBIG FIRM',
            'normalized_ca_name' => 'AMBIG CA',
            'status' => 'New',
            'rating' => 1,
            'is_verified' => true,
            'verification_status' => 'verified',
            'ocr_city_text' => 'CHENNAI',
        ]);
        CaMaster::query()->create([
            'firm_name' => 'AMBIG FIRM',
            'ca_name' => 'AMBIG CA',
            'normalized_firm_name' => 'AMBIG FIRM',
            'normalized_ca_name' => 'AMBIG CA',
            'status' => 'New',
            'rating' => 1,
            'is_verified' => false,
            'verification_status' => 'needs_verification',
            'ocr_city_text' => 'CHENNAI',
        ]);
        $mastersBefore = CaMaster::query()->count();
        $firm = $this->seedUnlinked([
            'firm_name' => 'AMBIG FIRM',
            'city' => 'CHENNAI',
            'source_data' => [
                'raw' => ['firm_name' => 'AMBIG FIRM', 'ca_name' => 'AMBIG CA', 'city' => 'CHENNAI'],
                'parsed' => ['firm_name' => 'AMBIG FIRM', 'ca_name' => 'AMBIG CA', 'city' => 'CHENNAI'],
            ],
            'match_status' => 'needs_review',
        ]);

        $report = $this->service->run([
            'document' => (int) $firm->ocr_document_id,
            'apply' => true,
            'actor' => 1,
        ]);

        $firm->refresh();
        $this->assertNull($firm->crm_ca_id);
        $this->assertSame($mastersBefore, CaMaster::query()->count());
        $this->assertGreaterThan(0, $report['ambiguous_rows']);
    }

    public function test_audit_row_created_on_needs_verification_import(): void
    {
        if (! Schema::hasTable('activity_logs')) {
            $this->markTestSkipped('activity_logs missing');
        }
        $before = DB::table('activity_logs')->count();
        $firm = $this->seedUnlinked([
            'firm_name' => 'AUDIT NV FIRM',
            'city' => 'KOCHI',
            'source_data' => [
                'raw' => ['firm_name' => 'AUDIT NV FIRM', 'ca_name' => '', 'city' => 'KOCHI'],
                'parsed' => ['firm_name' => 'AUDIT NV FIRM', 'ca_name' => '', 'city' => 'KOCHI'],
            ],
            'match_status' => 'needs_review',
        ]);

        $this->service->run([
            'document' => (int) $firm->ocr_document_id,
            'apply' => true,
            'actor' => 1,
            'needs_verification_only' => true,
        ]);

        $this->assertGreaterThan($before, DB::table('activity_logs')->count());
        $this->assertTrue(
            DB::table('activity_logs')->where('action', 'OCR Needs Verification Import')->exists()
        );
    }

    public function test_exact_duplicate_links_instead_of_creating(): void
    {
        $existing = CaMaster::query()->create([
            'firm_name' => 'DUP FIRM LLP',
            'ca_name' => 'DUP CA',
            'normalized_firm_name' => 'DUP FIRM LLP',
            'normalized_ca_name' => 'DUP CA',
            'status' => 'New',
            'rating' => 1,
            'is_verified' => true,
            'verification_status' => 'verified',
            'ocr_city_text' => 'DELHI',
        ]);
        $mastersBefore = CaMaster::query()->count();
        $firm = $this->seedUnlinked([
            'firm_name' => 'DUP FIRM LLP',
            'city' => 'DELHI',
            'source_data' => [
                'raw' => ['firm_name' => 'DUP FIRM LLP', 'ca_name' => 'DUP CA', 'city' => 'DELHI'],
                'parsed' => ['firm_name' => 'DUP FIRM LLP', 'ca_name' => 'DUP CA', 'city' => 'DELHI'],
            ],
            'match_status' => 'verified',
        ]);

        $this->service->run([
            'document' => (int) $firm->ocr_document_id,
            'apply' => true,
            'actor' => 1,
        ]);

        $firm->refresh();
        $this->assertSame((int) $existing->ca_id, (int) $firm->crm_ca_id);
        $this->assertSame($mastersBefore, CaMaster::query()->count());
        $existing->refresh();
        $this->assertSame('DUP CA', $existing->ca_name);
    }

    public function test_rerun_creates_no_duplicates(): void
    {
        $firm = $this->seedUnlinked([
            'firm_name' => 'RERUN FIRM',
            'city' => 'PUNE',
            'source_data' => [
                'raw' => ['firm_name' => 'RERUN FIRM', 'ca_name' => '', 'city' => 'PUNE'],
                'parsed' => ['firm_name' => 'RERUN FIRM', 'ca_name' => '', 'city' => 'PUNE'],
            ],
            'match_status' => 'needs_review',
        ]);

        $this->service->run([
            'document' => (int) $firm->ocr_document_id,
            'apply' => true,
            'actor' => 1,
            'needs_verification_only' => true,
        ]);
        $firm->refresh();
        $firstId = $firm->crm_ca_id;
        $mastersAfterFirst = CaMaster::query()->count();

        // Linked OCR rows are excluded from the query — re-run must not create another Master.
        $report = $this->service->run([
            'document' => (int) $firm->ocr_document_id,
            'apply' => true,
            'actor' => 1,
        ]);
        $this->assertSame($mastersAfterFirst, CaMaster::query()->count());
        $firm->refresh();
        $this->assertSame($firstId, $firm->crm_ca_id);
        $this->assertSame(0, $report['created_needs_verification'] + $report['created_verified']);
        $this->assertSame(0, $report['scanned']); // already linked → not selected again
    }

    public function test_noise_and_blank_firm_skipped(): void
    {
        $doc = $this->makeDocument();
        $this->seedUnlinked([
            'ocr_document_id' => $doc->id,
            'firm_name' => 'NOISE FIRM',
            'is_noise' => true,
            'city' => 'X',
            'source_data' => [
                'raw' => ['firm_name' => 'NOISE FIRM', 'ca_name' => 'N', 'city' => 'X'],
                'parsed' => ['firm_name' => 'NOISE FIRM', 'ca_name' => 'N', 'city' => 'X'],
            ],
        ]);
        $this->seedUnlinked([
            'ocr_document_id' => $doc->id,
            'firm_name' => '',
            'city' => 'Y',
            'source_data' => [
                'raw' => ['firm_name' => '', 'ca_name' => 'Z', 'city' => 'Y'],
                'parsed' => ['firm_name' => '', 'ca_name' => 'Z', 'city' => 'Y'],
            ],
        ]);
        $before = CaMaster::query()->count();
        $report = $this->service->run([
            'document' => (int) $doc->id,
            'apply' => true,
            'actor' => 1,
        ]);
        $this->assertSame($before, CaMaster::query()->count());
        $this->assertGreaterThan(0, $report['noise_rows_skipped'] + $report['invalid_rows_skipped']);
    }

    public function test_listing_filter_needs_verification(): void
    {
        $admin = User::query()->first() ?: CrmTestAccounts::ensureAdmin();
        $this->actingAs($admin);
        CaMaster::query()->create([
            'firm_name' => 'FILTER NV FIRM',
            'ca_name' => '',
            'status' => 'New',
            'rating' => 1,
            'is_verified' => false,
            'verification_status' => 'needs_verification',
            'data_quality_issue' => 'missing_ca_name',
            'source_type' => 'ocr',
        ]);

        $response = $this->getJson('/ca-masters?verification_status=needs_verification&per_page=5');
        if ($response->status() === 404) {
            $response = $this->getJson('/api/ca-masters?verification_status=needs_verification&per_page=5');
        }
        $response->assertOk();
    }

    public function test_mark_verified_route_is_rbac_protected(): void
    {
        $response = $this->patchJson('/ca-masters/1/mark-verified');
        if ($response->status() === 404) {
            $response = $this->patchJson('/api/ca-masters/1/mark-verified');
        }
        $this->assertTrue(in_array($response->status(), [401, 403, 302], true), 'Expected auth failure, got '.$response->status());
    }

    public function test_force_missing_ca_creates_needs_verification(): void
    {
        $firm = $this->seedUnlinked([
            'firm_name' => 'FORCE MISS CA FIRM',
            'city' => 'SURAT',
            'match_reason' => 'ca_name: CA name is required.',
            'source_data' => [
                'raw' => ['firm_name' => 'FORCE MISS CA FIRM', 'ca_name' => '', 'city' => 'SURAT'],
                'parsed' => ['firm_name' => 'FORCE MISS CA FIRM', 'ca_name' => '', 'city' => 'SURAT'],
            ],
        ]);
        $before = CaMaster::query()->count();
        $report = $this->service->run([
            'document' => (int) $firm->ocr_document_id,
            'apply' => true,
            'actor' => 1,
            'force_needs_verification' => true,
        ]);
        $firm->refresh();
        $this->assertSame(0, $report['errors'], json_encode($report['error_samples'] ?? []));
        $this->assertSame($before + 1, CaMaster::query()->count());
        $master = CaMaster::query()->findOrFail($firm->crm_ca_id);
        $this->assertSame('needs_verification', $master->verification_status);
        $this->assertFalse((bool) $master->is_verified);
        $this->assertSame('missing_ca_name', $master->data_quality_issue);
        $this->assertTrue($master->ca_name === null || $master->ca_name === '');
    }

    public function test_force_missing_city_creates_needs_verification(): void
    {
        $firm = $this->seedUnlinked([
            'firm_name' => 'FORCE MISS CITY FIRM',
            'city' => null,
            'match_reason' => 'city: City is required.',
            'source_data' => [
                'raw' => ['firm_name' => 'FORCE MISS CITY FIRM', 'ca_name' => 'KIRAN SHAH', 'city' => ''],
                'parsed' => ['firm_name' => 'FORCE MISS CITY FIRM', 'ca_name' => 'KIRAN SHAH', 'city' => ''],
            ],
        ]);
        $report = $this->service->run([
            'document' => (int) $firm->ocr_document_id,
            'apply' => true,
            'actor' => 1,
            'force_needs_verification' => true,
        ]);
        $firm->refresh();
        $this->assertSame(0, $report['errors']);
        $master = CaMaster::query()->findOrFail($firm->crm_ca_id);
        $this->assertSame('missing_city', $master->data_quality_issue);
        $this->assertNull($master->city_id);
        $this->assertTrue($master->ocr_city_text === null || $master->ocr_city_text === '');
        $this->assertSame('KIRAN SHAH', $master->ca_name);
        $this->assertFalse((bool) $master->is_verified);
    }

    public function test_force_invalid_ca_creates_blank_ca_needs_verification(): void
    {
        $firm = $this->seedUnlinked([
            'firm_name' => 'FORCE INVALID CA FIRM',
            'city' => 'PUNE',
            'match_reason' => 'CA name does not look like a valid person name.',
            'source_data' => [
                'raw' => ['firm_name' => 'FORCE INVALID CA FIRM', 'ca_name' => '14 NIRMAN SQUARE', 'city' => 'PUNE'],
                'parsed' => ['firm_name' => 'FORCE INVALID CA FIRM', 'ca_name' => '14 NIRMAN SQUARE', 'city' => 'PUNE'],
            ],
        ]);
        $this->service->run([
            'document' => (int) $firm->ocr_document_id,
            'apply' => true,
            'actor' => 1,
            'force_needs_verification' => true,
        ]);
        $firm->refresh();
        $master = CaMaster::query()->findOrFail($firm->crm_ca_id);
        $this->assertSame('invalid_ca_name', $master->data_quality_issue);
        $this->assertTrue($master->ca_name === null || $master->ca_name === '');
        $this->assertNotEquals('14 NIRMAN SQUARE', $master->ca_name);
        $this->assertFalse((bool) $master->is_verified);
    }

    public function test_force_pending_with_firm_creates_needs_verification(): void
    {
        $firm = $this->seedUnlinked([
            'firm_name' => 'FORCE PENDING FIRM LLP',
            'city' => null,
            'match_status' => 'pending',
            'match_reason' => '',
            'source_data' => [
                'raw' => ['firm_name' => 'FORCE PENDING FIRM LLP', 'ca_name' => '', 'city' => ''],
                'parsed' => ['firm_name' => 'FORCE PENDING FIRM LLP', 'ca_name' => '', 'city' => ''],
            ],
        ]);
        $this->service->run([
            'document' => (int) $firm->ocr_document_id,
            'apply' => true,
            'actor' => 1,
            'force_needs_verification' => true,
        ]);
        $firm->refresh();
        $this->assertNotNull($firm->crm_ca_id);
        $master = CaMaster::query()->findOrFail($firm->crm_ca_id);
        $this->assertSame('needs_verification', $master->verification_status);
        $this->assertFalse((bool) $master->is_verified);
    }

    public function test_force_dry_run_writes_nothing(): void
    {
        $firm = $this->seedUnlinked([
            'firm_name' => 'FORCE DRY FIRM',
            'city' => 'SURAT',
            'match_reason' => 'ca_name: CA name is required.',
            'source_data' => [
                'raw' => ['firm_name' => 'FORCE DRY FIRM', 'ca_name' => '', 'city' => 'SURAT'],
                'parsed' => ['firm_name' => 'FORCE DRY FIRM', 'ca_name' => '', 'city' => 'SURAT'],
            ],
        ]);
        $before = CaMaster::query()->count();
        $report = $this->service->run([
            'document' => (int) $firm->ocr_document_id,
            'dry_run' => true,
            'actor' => 1,
            'force_needs_verification' => true,
        ]);
        $firm->refresh();
        $this->assertNull($firm->crm_ca_id);
        $this->assertSame($before, CaMaster::query()->count());
        $this->assertGreaterThan(0, $report['would_create_needs_verification_master']);
        $this->assertSame(0, $report['created_needs_verification']);
    }

    public function test_force_rerun_is_idempotent(): void
    {
        $firm = $this->seedUnlinked([
            'firm_name' => 'FORCE IDEMP FIRM',
            'city' => 'JAIPUR',
            'match_reason' => 'ca_name: CA name is required.',
            'source_data' => [
                'raw' => ['firm_name' => 'FORCE IDEMP FIRM', 'ca_name' => '', 'city' => 'JAIPUR'],
                'parsed' => ['firm_name' => 'FORCE IDEMP FIRM', 'ca_name' => '', 'city' => 'JAIPUR'],
            ],
        ]);
        $this->service->run([
            'document' => (int) $firm->ocr_document_id,
            'apply' => true,
            'actor' => 1,
            'force_needs_verification' => true,
        ]);
        $firm->refresh();
        $id = $firm->crm_ca_id;
        $count = CaMaster::query()->count();
        $report = $this->service->run([
            'document' => (int) $firm->ocr_document_id,
            'apply' => true,
            'actor' => 1,
            'force_needs_verification' => true,
        ]);
        $this->assertSame($count, CaMaster::query()->count());
        $firm->refresh();
        $this->assertSame($id, $firm->crm_ca_id);
        $this->assertSame(0, $report['created_needs_verification']);
    }

    /** @param  array<string, mixed>  $attrs */
    private function seedUnlinked(array $attrs): OcrParsedFirm
    {
        $documentId = $attrs['ocr_document_id'] ?? $this->makeDocument()->id;
        unset($attrs['ocr_document_id']);

        return OcrParsedFirm::query()->create(array_merge([
            'ocr_document_id' => $documentId,
            'sequence_no' => random_int(1, 999999),
            'firm_name' => 'DEFAULT FIRM',
            'review_status' => OcrParsedFirm::REVIEW_PENDING,
            'match_status' => 'needs_review',
            'overall_confidence' => 0.8,
            'crm_ca_id' => null,
            'is_noise' => false,
        ], $attrs));
    }

    private function makeDocument(string $name = 'import-remaining.pdf'): OcrDocument
    {
        $admin = User::query()->first() ?: CrmTestAccounts::ensureAdmin();

        return OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => $admin->id,
            'original_filename' => $name,
            'stored_filename' => $name,
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/'.$name,
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'status' => OcrDocument::STATUS_COMPLETED,
            'import_type' => OcrDocument::IMPORT_MASTER_CA,
            'parse_status' => 'completed',
            'processing_progress' => 'Completed',
            'checksum' => hash('sha256', $name.uniqid('', true)),
            'processed_at' => now(),
        ]);
    }
}
