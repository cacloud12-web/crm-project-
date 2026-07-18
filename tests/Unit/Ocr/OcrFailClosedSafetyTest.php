<?php

namespace Tests\Unit\Ocr;

use App\Services\Mapping\DataNormalizationService;
use App\Services\Ocr\OcrFieldCollisionService;
use App\Services\Ocr\OcrFieldValidationService;
use App\Services\Ocr\OcrImportRouterService;
use App\Services\Ocr\OcrSourceVerificationService;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class OcrFailClosedSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ocr_safety.require_verification' => true,
            'ocr_safety.auto_create' => false,
            'ocr_safety.auto_update' => false,
            'ocr_safety.reject_on_field_collision' => true,
            'ocr_safety.reject_on_row_ambiguity' => true,
            'ocr_safety.min_required_field_confidence' => 0.99,
        ]);
    }

    private function verifier(): OcrSourceVerificationService
    {
        return new OcrSourceVerificationService(
            new OcrFieldValidationService(new DataNormalizationService),
            new OcrFieldCollisionService,
        );
    }

    public function test_address_cannot_enter_partner_field(): void
    {
        $collision = (new OcrFieldCollisionService)->detect([
            'firm_name' => 'Shah & Associates',
            'members' => [['ca_name' => '12 MG Road Sector 5 PIN 400001']],
        ]);

        $this->assertContains('ADDRESS_IN_PARTNER_FIELD', $collision['codes']);
        $this->assertFalse($collision['ok']);
    }

    public function test_pin_cannot_enter_membership_field(): void
    {
        $result = (new OcrFieldValidationService(new DataNormalizationService))->validateFirm([
            'firm_name' => 'Shah & Associates',
            'membership_no' => '400001',
            'field_meta' => ['firm_name' => ['confidence' => 0.99]],
        ]);

        $this->assertFalse($result['fields']['membership_no']['valid']);
        $this->assertFalse($result['auto_apply_ok']);

        $collision = (new OcrFieldCollisionService)->detect([
            'firm_name' => 'Shah & Associates',
            'membership_no' => '400001',
            'pincode' => '400001',
        ]);
        $this->assertContains('PIN_IN_MEMBERSHIP_FIELD', $collision['codes']);
    }

    public function test_mobile_cannot_enter_firm_name_field(): void
    {
        $result = (new OcrFieldValidationService(new DataNormalizationService))->validateFirm([
            'firm_name' => 'Call 9876543210 Now',
            'field_meta' => ['firm_name' => ['confidence' => 0.99]],
            'overall_confidence' => 0.99,
        ]);

        $this->assertFalse($result['fields']['firm_name']['valid']);

        $collision = (new OcrFieldCollisionService)->detect([
            'firm_name' => 'Shah 9876543210 Associates',
            'phone' => '9876543210',
        ]);
        $this->assertContains('MOBILE_IN_FIRM_NAME', $collision['codes']);
    }

    public function test_row_merge_and_split_suspected_are_rejected(): void
    {
        config(['ocr_workflow.mode' => 'full']);
        $merge = (new OcrFieldCollisionService)->detect([
            'firm_name' => 'Firm A',
            'row_merge_suspected' => true,
        ]);
        $this->assertContains('ROW_MERGE_SUSPECTED', $merge['codes']);

        $split = (new OcrFieldCollisionService)->detect([
            'firm_name' => 'Firm B',
            'row_split_suspected' => true,
        ]);
        $this->assertContains('ROW_SPLIT_SUSPECTED', $split['codes']);

        config(['ocr_workflow.mode' => 'firm_ca_city']);
        $scoped = (new OcrFieldCollisionService)->detect([
            'firm_name' => 'Firm A',
            'ca_name' => 'Person A',
            'city' => 'ABOHAR',
            'row_merge_suspected' => true,
            'row_merge_evidence' => [['affected_field' => 'firm_name', 'reason' => 'second_firm_name_token_in_same_record']],
        ]);
        $this->assertContains('ROW_MERGE_SUSPECTED', $scoped['codes']);
    }

    public function test_require_verification_blocks_auto_apply_even_when_fields_look_valid(): void
    {
        $result = $this->verifier()->verify([
            'firm_name' => 'Shah & Associates',
            'phone' => '9876543210',
            'email' => 'amit@shah.test',
            'field_meta' => [
                'firm_name' => ['confidence' => 0.99],
                'phone' => ['confidence' => 1.0],
                'email' => ['confidence' => 0.99],
            ],
            'overall_confidence' => 0.99,
        ]);

        $this->assertFalse($result['auto_apply_ok']);
        $this->assertTrue($result['require_verification']);
    }

    public function test_low_confidence_is_not_verified(): void
    {
        $result = $this->verifier()->verify([
            'firm_name' => 'Shah & Associates',
            'field_meta' => ['firm_name' => ['confidence' => 0.40, 'parser_confidence' => 0.40]],
            'overall_confidence' => 0.40,
            'structural_confidence' => 0.40,
        ]);

        $this->assertFalse($result['verified']);
        $this->assertFalse($result['auto_apply_ok']);
        $this->assertContains('LOW_FIELD_CONFIDENCE', $result['collision_codes']);
    }

    public function test_raw_source_values_are_preserved_against_silent_rewrite(): void
    {
        $result = $this->verifier()->verify([
            'firm_name' => 'Shah & Associates',
            'raw' => ['firm_name' => 'Shah & Associates'],
            'parsed' => ['firm_name' => 'Shah and Associates LLP'],
            'field_meta' => ['firm_name' => ['confidence' => 0.99]],
            'overall_confidence' => 0.99,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['verified']);
    }

    public function test_excel_csv_bypass_ocr_router(): void
    {
        $router = new OcrImportRouterService;
        $csv = UploadedFile::fake()->create('firms.csv', 10, 'text/csv');
        $xlsx = UploadedFile::fake()->create('firms.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $csvRoute = $router->classify($csv);
        $this->assertTrue($csvRoute['bypass_ocr']);
        $this->assertSame(OcrImportRouterService::ROUTE_STRUCTURED_BULK, $csvRoute['route']);

        $xlsxRoute = $router->classify($xlsx);
        $this->assertTrue($xlsxRoute['bypass_ocr']);
        $this->assertSame(OcrImportRouterService::ROUTE_STRUCTURED_BULK, $xlsxRoute['route']);
    }

    public function test_golden_structured_output_exact_match_fixture(): void
    {
        $fixturePath = base_path('tests/fixtures/ocr/golden_safe_firm.json');
        $this->assertFileExists($fixturePath);
        $expected = json_decode((string) file_get_contents($fixturePath), true);
        $this->assertIsArray($expected);

        $result = $this->verifier()->verify($expected['input']);
        foreach ($expected['assert_collision_empty'] as $code) {
            $this->assertNotContains($code, $result['collision_codes']);
        }
        $this->assertSame($expected['assert_firm_name'], $expected['input']['firm_name']);
        $this->assertFalse($result['auto_apply_ok']); // fail-closed production default
    }
}
