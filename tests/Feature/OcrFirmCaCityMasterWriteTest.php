<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\City;
use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Services\Mapping\DataNormalizationService;
use App\Services\Ocr\MasterCaDirectImportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CrmTestAccounts;
use Tests\TestCase;

class OcrFirmCaCityMasterWriteTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'ocr_workflow.mode' => 'firm_ca_city',
            'ocr_safety.require_verification' => true,
            'ocr_safety.auto_create' => false,
            'ocr_safety.auto_update' => false,
            'ocr_safety.reject_on_field_collision' => true,
            'crm_mapping.queue_after_ocr_parse' => false,
        ]);
        app(\App\Services\Rbac\RbacDatabaseService::class)->ensureConfigDefaultGrants();
        app(\App\Services\Rbac\RbacMatrixService::class)->flushCache();
    }

    public function test_only_verified_approve_writes_master_and_ignored_fields_do_not_match(): void
    {
        if (! Schema::hasTable('ocr_parsed_firms') || ! Schema::hasTable('ca_masters')) {
            $this->markTestSkipped('Required tables missing.');
        }

        $city = City::query()->where('city_name', 'Mumbai')->first()
            ?: City::query()->orderBy('city_id')->first();
        if ($city === null) {
            $this->markTestSkipped('City master missing.');
        }

        $admin = CrmTestAccounts::admin();
        $normalizer = app(DataNormalizationService::class);
        $firmName = 'Three Field Exact Firm '.uniqid();
        $caName = 'Three Field Exact CA';
        $normFirm = $normalizer->firmName($firmName);
        $normCa = $normalizer->caName($caName);

        $existing = CaMaster::query()->create([
            'firm_name' => $firmName,
            'ca_name' => $caName,
            'normalized_firm_name' => $normFirm,
            'normalized_ca_name' => $normCa,
            'city_id' => $city->city_id,
            'frn' => 'FRNIGNORE'.random_int(1000, 9999),
            'mobile_no' => '9000000001',
            'status' => 'New',
            'rating' => 1,
        ]);

        $before = CaMaster::query()->count();

        $document = OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => $admin->id,
            'original_filename' => 'three-field.pdf',
            'stored_filename' => 'three-field.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/three-field.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size' => 100,
            'checksum' => hash('sha256', 'three-field-'.uniqid()),
            'status' => 'completed',
            'parse_status' => 'completed',
            'import_type' => OcrDocument::IMPORT_MASTER_CA,
            'processing_progress' => 'Validating official Master records',
            'processed_at' => now(),
            'extracted_text' => 'test',
        ]);

        $matched = OcrParsedFirm::query()->create([
            'ocr_document_id' => $document->id,
            'sequence_no' => 1,
            'firm_name' => $firmName,
            'raw_firm_name' => $firmName,
            'normalized_firm_name' => $normFirm,
            'city' => $city->city_name,
            'frn' => 'TOTALLYDIFFERENT',
            'phone' => '9111111111',
            'address' => 'ANAJ MANDI SHOULD NOT MATCH',
            'overall_confidence' => 0.95,
            'review_status' => OcrParsedFirm::REVIEW_PENDING,
            'match_status' => null,
            'source_data' => [
                'raw' => [
                    'firm_name' => $firmName,
                    'ca_name' => $caName,
                    'city' => $city->city_name,
                ],
                'parsed' => [
                    'firm_name' => $firmName,
                    'ca_name' => $caName,
                    'city' => $city->city_name,
                ],
                'normalized' => [
                    'firm_name' => $normFirm,
                    'ca_name' => $normCa,
                    'city' => $normalizer->city($city->city_name),
                ],
                'validation' => [
                    'ok' => true,
                    'verified' => true,
                    'auto_apply_ok' => false,
                    'errors' => [],
                    'warnings' => [],
                    'collision_codes' => [],
                ],
            ],
            'field_meta' => [
                'firm_name' => ['confidence' => 0.95],
                'ca_name' => ['confidence' => 0.94],
                'city' => ['confidence' => 0.93],
            ],
        ]);

        $missingCity = OcrParsedFirm::query()->create([
            'ocr_document_id' => $document->id,
            'sequence_no' => 2,
            'firm_name' => 'Missing City Firm',
            'raw_firm_name' => 'Missing City Firm',
            'city' => null,
            'overall_confidence' => 0.95,
            'review_status' => OcrParsedFirm::REVIEW_PENDING,
            'source_data' => [
                'raw' => ['firm_name' => 'Missing City Firm', 'ca_name' => 'Some Person', 'city' => null],
                'parsed' => ['firm_name' => 'Missing City Firm', 'ca_name' => 'Some Person', 'city' => null],
                'validation' => [
                    'ok' => false,
                    'verified' => false,
                    'auto_apply_ok' => false,
                    'errors' => ['city: City is required.'],
                    'warnings' => [],
                    'collision_codes' => ['MISSING_REQUIRED_FIELD'],
                ],
            ],
            'field_meta' => [
                'firm_name' => ['confidence' => 0.95],
                'ca_name' => ['confidence' => 0.94],
            ],
        ]);

        $stats = app(MasterCaDirectImportService::class)->processDocument((int) $document->id, (int) $admin->id);

        $this->assertSame($before, CaMaster::query()->count(), 'No Master write without Approve');

        $matched->refresh();
        $missingCity->refresh();
        $this->assertSame(MasterCaDirectImportService::MATCH_VERIFIED, $matched->match_status);
        $this->assertSame((int) $existing->ca_id, (int) $matched->matched_ca_id);
        $this->assertNull($matched->crm_ca_id);
        $this->assertSame('EXACT_VERIFIED', $matched->match_reason);
        $this->assertSame(MasterCaDirectImportService::MATCH_NEEDS_REVIEW, $missingCity->match_status);

        $approved = app(MasterCaDirectImportService::class)->approveFirm($document, $matched, (int) $admin->id);
        $this->assertNotNull($approved['ca_id'] ?? $matched->fresh()->crm_ca_id);
        $this->assertSame((int) $existing->ca_id, (int) $matched->fresh()->crm_ca_id);
        $this->assertSame($before, CaMaster::query()->count(), 'Approve of exact match must not create a duplicate');
        $this->assertGreaterThanOrEqual(1, (int) ($stats['review'] ?? 0) + (int) ($stats['conflict'] ?? 0));
    }
}
