<?php

namespace Tests\Feature;

use App\Http\Resources\OcrParsedFirmResource;
use App\Models\CaMaster;
use App\Models\City;
use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Models\User;
use App\Services\Mapping\DataNormalizationService;
use App\Services\Mapping\FirmCaCityMatchingProfile;
use App\Services\Ocr\MasterCaDirectImportService;
use App\Services\Ocr\OcrEntityClassificationService;
use App\Services\Ocr\OcrFirmCaCityExtractorService;
use App\Services\Ocr\OcrGoldenAuditService;
use App\Services\Ocr\OcrReconciliationReportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Final three-field OCR audit: golden regression, reconciliation, matching, write safety.
 */
class OcrFinalThreeFieldAuditTest extends TestCase
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
            'crm_mapping.queue_after_ocr_parse' => false,
        ]);
    }

    private function golden(): array
    {
        $path = base_path('tests/Fixtures/ocr/golden_northprop_three_field.json');
        $data = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($data['records'] ?? null);

        return $data;
    }

    private function tokens(array $lines, float $y = 0.1): array
    {
        $out = [];
        foreach ($lines as $i => $text) {
            $out[] = [
                'text' => $text,
                'page' => 1,
                'column' => 0,
                'ocr_confidence' => 0.93,
                'x_min' => 0.1, 'x_max' => 0.4,
                'y_min' => $y + ($i * 0.03), 'y_max' => $y + 0.02 + ($i * 0.03),
                'x_center' => 0.25, 'y_center' => $y + 0.01 + ($i * 0.03),
            ];
        }

        return $out;
    }

    public function test_golden_required_regression_cases_exact_fields(): void
    {
        $golden = $this->golden();
        $extractor = new OcrFirmCaCityExtractorService(new OcrEntityClassificationService);
        $cases = [
            ['ANMOL SETIA & ASSOCIATES', 'ANMOL SETIA', 'ABOHAR'],
            ['BAGAI & ASSOCIATES', 'ITIE BAGAI', 'ABOHAR'],
            ['BANSAL SANDEEP AND ASSOCIATES', 'SANDEEP BANSAL', 'ABOHAR'],
            ['DINESH PUJARA & ASSOCIATES', 'PUJARA DINESH', 'ABOHAR'],
            ['NEETU BHATIA & ASSOCIATES', 'NEETU BHATIA', 'AMRITSAR'],
            ['RAMESH AHUJA & ASSOCIATES', 'RAMESH KUMAR', 'AMBALA'],
            ['S AJAY & CO', 'AJAY SHARMA', 'AMBALA'],
            ['S JUNEJA & ASSOCIATES', 'SAKSHI JUNEJA', 'AMBALA'],
            ['HARSHIL & ASSOCIATES', 'HARSHIL', 'AMBALA'],
            ['MIGLANI & CO', 'KUSHAL MIGLANI', 'AMRITSAR'],
        ];
        $actual = [];
        foreach ($cases as [$firm, $ca, $city]) {
            $lines = [$firm, $ca];
            if ($firm === 'MIGLANI & CO') {
                $lines = [$firm, '* KUSHAL MIGLANI'];
            }
            $row = $extractor->extract($this->tokens($lines), ['section_city' => $city, 'sequence_no' => 1]);
            $this->assertNotNull($row, $firm);
            $this->assertSame($firm, $row['firm_name'], $firm);
            $this->assertSame($ca, $row['ca_name'], $firm);
            $this->assertSame($city, $row['city'], $firm);
            $this->assertSame([], $row['missing_required_fields'], $firm);
            $actual[] = [
                'firm_name' => $row['firm_name'],
                'ca_name' => $row['ca_name'],
                'city' => $row['city'],
                'raw_firm_name' => $row['raw_firm_name'] ?? $row['firm_name'],
                'raw_ca_name' => $row['raw_ca_name'] ?? $row['ca_name'],
                'raw_city' => $row['raw_city'] ?? $row['city'],
            ];
        }

        $subset = array_values(array_filter(
            $golden['records'],
            static fn (array $r) => in_array($r['firm_name'], array_column($cases, 0), true),
        ));
        $cmp = app(OcrGoldenAuditService::class)->compareGolden($subset, $actual);
        $this->assertTrue($cmp['pass'], json_encode($cmp['mismatches']));
        $this->assertSame(100.0, $cmp['complete_record_exact_accuracy']);
        $this->assertSame(0, $cmp['silent_loss_count']);
    }

    public function test_locality_tokens_never_become_ca_name(): void
    {
        $entities = new OcrEntityClassificationService;
        foreach ($this->golden()['must_never_be_ca_name'] as $token) {
            $this->assertFalse($entities->isPerson($token), $token);
        }
        $row = (new OcrFirmCaCityExtractorService($entities))->extract(
            $this->tokens(['SHAH & ASSOCIATES', 'ANAJ MANDI', 'URBAN ESTATE HUDA', 'NEW SURAJ NAGAR']),
            ['section_city' => 'ROHTAK'],
        );
        $this->assertNull($row['ca_name']);
        $this->assertNotContains($row['ca_name'], ['ANAJ MANDI', 'URBAN ESTATE HUDA', 'NEW SURAJ NAGAR']);
    }

    public function test_populated_field_never_reports_missing(): void
    {
        $row = (new OcrFirmCaCityExtractorService(new OcrEntityClassificationService))->extract(
            $this->tokens(['BAGAI & ASSOCIATES', 'ITIE BAGAI']),
            ['section_city' => 'ABOHAR'],
        );
        $this->assertNotEmpty($row['ca_name']);
        $this->assertNotContains('ca_name', $row['missing_required_fields']);
        $this->assertNotEmpty($row['city']);
        $this->assertNotContains('city', $row['missing_required_fields']);

        $resource = new OcrParsedFirmResource(new OcrParsedFirm([
            'firm_name' => $row['firm_name'],
            'city' => $row['city'],
            'match_status' => 'needs_review',
            'review_status' => 'pending',
            'source_data' => [
                'parsed' => ['firm_name' => $row['firm_name'], 'ca_name' => $row['ca_name'], 'city' => $row['city']],
                'raw' => ['firm_name' => $row['firm_name'], 'ca_name' => $row['ca_name'], 'city' => $row['city']],
                'validation' => ['ok' => true, 'errors' => [], 'collision_codes' => []],
            ],
            'validation_errors' => null,
        ]));
        $arr = $resource->toArray(Request::create('/'));
        $this->assertSame($row['ca_name'], $arr['ca_name']);
        $this->assertNotSame('Invalid', $arr['status']);
        $msg = (string) ($arr['user_message'] ?? '');
        $this->assertStringNotContainsString('CA Name is required', $msg);
        $this->assertStringNotContainsString('City is required', $msg);
    }

    public function test_exact_unique_match_is_verified_zero_and_multiple_conflict(): void
    {
        if (! Schema::hasTable('ca_masters') || ! Schema::hasTable('cities')) {
            $this->markTestSkipped('ca_masters/cities missing');
        }
        $city = City::query()->whereRaw('LOWER(city_name) = ?', ['abohar'])->first()
            ?: City::query()->first();
        $this->assertNotNull($city);
        $cityName = (string) $city->city_name;
        $n = app(DataNormalizationService::class);
        $firm = 'AUDIT UNIQUE FIRM '.uniqid('', false).' & ASSOCIATES';
        $ca = 'AUDIT UNIQUE CA '.uniqid('', false);
        CaMaster::query()->create([
            'firm_name' => $firm,
            'normalized_firm_name' => $n->firmName($firm),
            'ca_name' => $ca,
            'normalized_ca_name' => $n->caName($ca),
            'city_id' => $city->city_id ?? $city->id,
            'is_active' => true,
        ]);
        $profile = app(FirmCaCityMatchingProfile::class);
        $one = $profile->match(['firm_name' => $firm, 'ca_name' => $ca, 'city' => $cityName]);
        $this->assertTrue($one->isExact(), $one->reason);
        $none = $profile->match(['firm_name' => $firm, 'ca_name' => $ca, 'city' => 'NoSuchCityXYZ999']);
        $this->assertFalse($none->isExact());
        CaMaster::query()->create([
            'firm_name' => $firm,
            'normalized_firm_name' => $n->firmName($firm),
            'ca_name' => $ca,
            'normalized_ca_name' => $n->caName($ca),
            'city_id' => $city->city_id ?? $city->id,
            'is_active' => true,
        ]);
        $many = $profile->match(['firm_name' => $firm, 'ca_name' => $ca, 'city' => $cityName]);
        $this->assertTrue($many->isConflict(), $many->reason.' cands='.count($many->candidates));
    }

    public function test_needs_review_and_invalid_cannot_auto_write_master(): void
    {
        if (! Schema::hasTable('ocr_parsed_firms') || ! Schema::hasTable('ca_masters')) {
            $this->markTestSkipped('tables missing');
        }
        app(\App\Services\Rbac\RbacDatabaseService::class)->ensureConfigDefaultGrants();
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $before = CaMaster::query()->count();
        $document = OcrDocument::query()->create([
            'uploaded_by' => $admin->id,
            'original_filename' => 'audit-write.pdf',
            'stored_filename' => 'audit-write.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/audit-write.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size' => 10,
            'checksum' => hash('sha256', uniqid('audit', true)),
            'status' => 'completed',
            'parse_status' => 'completed',
            'import_type' => OcrDocument::IMPORT_MASTER_CA,
            'processing_progress' => 'Validating official Master records',
            'processed_at' => now(),
            'extracted_text' => 'x',
        ]);
        $firm = OcrParsedFirm::query()->create([
            'ocr_document_id' => $document->id,
            'sequence_no' => 1,
            'firm_name' => 'NO WRITE FIRM & ASSOCIATES',
            'city' => 'ABOHAR',
            'review_status' => OcrParsedFirm::REVIEW_PENDING,
            'match_status' => null,
            'overall_confidence' => 0.9,
            'source_data' => [
                'parsed' => ['firm_name' => 'NO WRITE FIRM & ASSOCIATES', 'ca_name' => 'NO WRITE CA', 'city' => 'ABOHAR'],
                'raw' => ['firm_name' => 'NO WRITE FIRM & ASSOCIATES', 'ca_name' => 'NO WRITE CA', 'city' => 'ABOHAR'],
                'validation' => ['ok' => true, 'verified' => true, 'auto_apply_ok' => false, 'errors' => [], 'collision_codes' => []],
            ],
            'field_meta' => [
                'firm_name' => ['confidence' => 0.9],
                'ca_name' => ['confidence' => 0.9],
                'city' => ['confidence' => 0.9],
            ],
        ]);
        $stats = app(MasterCaDirectImportService::class)->processDocument((int) $document->id, (int) $admin->id);
        $firm->refresh();
        $this->assertContains($firm->match_status, ['needs_review', 'verified', 'invalid']);
        if ($firm->match_status === 'needs_review' || $firm->match_status === 'invalid') {
            $this->assertNull($firm->crm_ca_id);
            $this->assertSame($before, CaMaster::query()->count());
        }
        $this->assertArrayHasKey('processed', $stats);
    }

    public function test_reconciliation_equation_balances_for_synthetic_document(): void
    {
        if (! Schema::hasTable('ocr_parsed_firms')) {
            $this->markTestSkipped('ocr_parsed_firms missing');
        }
        $admin = User::query()->where('email', 'admin@ca.local')->first();
        if (! $admin) {
            $this->markTestSkipped('admin user missing');
        }
        $document = OcrDocument::query()->create([
            'uploaded_by' => $admin->id,
            'original_filename' => 'recon.pdf',
            'stored_filename' => 'recon.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/recon.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size' => 10,
            'checksum' => hash('sha256', uniqid('recon', true)),
            'status' => 'completed',
            'parse_status' => 'completed',
            'parsed_firm_count' => 3,
            'import_type' => OcrDocument::IMPORT_MASTER_CA,
            'structured_data' => [
                'parsed' => ['quality_report' => ['total_rows_detected' => 3, 'total_source_rows' => 3]],
            ],
            'extracted_text' => 'x',
        ]);
        foreach ([
            ['verified', 'EXACT_VERIFIED', 'ANMOL SETIA'],
            ['needs_review', 'NO_EXACT_MATCH', 'ITIE BAGAI'],
            ['invalid', 'INCOMPLETE_SCOPED_FIELDS', null],
        ] as $i => [$match, $type, $ca]) {
            OcrParsedFirm::query()->create([
                'ocr_document_id' => $document->id,
                'sequence_no' => $i + 1,
                'firm_name' => 'RECON FIRM '.$i.' & ASSOCIATES',
                'city' => $ca ? 'ABOHAR' : null,
                'match_status' => $match,
                'review_status' => 'pending',
                'validation_errors' => $ca ? null : ['MISSING_CA_NAME'],
                'source_data' => [
                    'match_type' => $type,
                    'parsed' => [
                        'firm_name' => 'RECON FIRM '.$i.' & ASSOCIATES',
                        'ca_name' => $ca,
                        'city' => $ca ? 'ABOHAR' : null,
                    ],
                    'validation' => [
                        'ok' => $ca !== null,
                        'errors' => $ca ? [] : ['CA name is required.'],
                        'collision_codes' => $ca ? [] : ['MISSING_CA_NAME'],
                    ],
                ],
            ]);
        }
        $recon = app(OcrGoldenAuditService::class)->reconcileDocument($document->fresh());
        $this->assertTrue($recon['equation_balances']);
        $this->assertTrue($recon['parsed_equals_detected']);
        $this->assertSame(3, $recon['parsed_rows']);
        $this->assertSame(0, $recon['missing_rows']);
        $report = app(OcrReconciliationReportService::class)->buildForDocument($document->fresh());
        $this->assertSame($recon['parsed_rows'], $report['parsed_rows']);
    }

    public function test_matching_connection_is_logged_without_secrets(): void
    {
        $info = app(OcrGoldenAuditService::class)->matchingConnectionInfo();
        $this->assertArrayHasKey('connection', $info);
        $this->assertArrayHasKey('database', $info);
        $this->assertArrayHasKey('table', $info);
        $this->assertArrayNotHasKey('password', $info);
        $this->assertArrayNotHasKey('username', $info);
    }

    public function test_scale_match_smoke_10_100_1000(): void
    {
        $audit = app(OcrGoldenAuditService::class);
        foreach ([10, 100, 1000] as $n) {
            $result = $audit->scaleMatchSmoke($n);
            $this->assertSame($n, $result['rows']);
            $this->assertGreaterThan(0, $result['query_count']);
            // Indexed exact lookups — should stay well under 30s for 1k.
            $this->assertLessThan(30000, $result['matching_ms'], 'scale '.$n);
        }
    }

    public function test_ignored_identifiers_do_not_change_status_when_three_fields_valid(): void
    {
        $clean = (new OcrFirmCaCityExtractorService(new OcrEntityClassificationService))->extract(
            $this->tokens(['BAGAI & ASSOCIATES', 'ITIE BAGAI']),
            ['section_city' => 'ABOHAR'],
        );
        $noisy = (new OcrFirmCaCityExtractorService(new OcrEntityClassificationService))->extract(
            $this->tokens(['BAGAI & ASSOCIATES', 'ITIE BAGAI', '024992N', '525126', 'HOUSE NO 968', '124001']),
            ['section_city' => 'ABOHAR'],
        );
        $this->assertSame($clean['firm_name'], $noisy['firm_name']);
        $this->assertSame($clean['ca_name'], $noisy['ca_name']);
        $this->assertSame($clean['city'], $noisy['city']);
        $this->assertSame($clean['missing_required_fields'], $noisy['missing_required_fields']);
        $this->assertFalse($noisy['row_merge_suspected']);
    }
}
