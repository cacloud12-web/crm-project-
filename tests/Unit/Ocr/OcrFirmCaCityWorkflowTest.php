<?php

namespace Tests\Unit\Ocr;

use App\Services\Mapping\DataNormalizationService;
use App\Services\Mapping\FirmCaCityMatchingProfile;
use App\Services\Ocr\OcrEntityClassificationService;
use App\Services\Ocr\OcrFieldCollisionService;
use App\Services\Ocr\OcrFieldValidationService;
use App\Services\Ocr\OcrFirmCaCityExtractorService;
use App\Services\Ocr\OcrSourceVerificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OcrFirmCaCityWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ocr_workflow.mode' => 'firm_ca_city',
            'ocr_workflow.require_all_three' => true,
            'ocr_workflow.min_field_confidence' => 0.70,
            'ocr_safety.require_verification' => true,
            'ocr_safety.auto_create' => false,
            'ocr_safety.auto_update' => false,
        ]);
    }

    private function extractor(): OcrFirmCaCityExtractorService
    {
        return new OcrFirmCaCityExtractorService(new OcrEntityClassificationService);
    }

    private function tokens(array $lines, int $page = 1, int $col = 0): array
    {
        $out = [];
        foreach ($lines as $i => $text) {
            $out[] = [
                'text' => $text,
                'page' => $page,
                'column' => $col,
                'ocr_confidence' => 0.95,
                'x_min' => 0.1,
                'x_max' => 0.4,
                'y_min' => 0.1 + ($i * 0.04),
                'y_max' => 0.13 + ($i * 0.04),
                'x_center' => 0.25,
                'y_center' => 0.115 + ($i * 0.04),
            ];
        }

        return $out;
    }

    public function test_anaj_mandi_is_not_extracted_as_ca_name(): void
    {
        $row = $this->extractor()->extract($this->tokens([
            'SHAH & ASSOCIATES',
            'ANAJ MANDI',
            'ROHTAK',
        ]), ['section_city' => 'ROHTAK', 'sequence_no' => 1, 'page' => 1]);

        $this->assertNotNull($row);
        $this->assertSame('SHAH & ASSOCIATES', $row['firm_name']);
        $this->assertSame('ROHTAK', $row['city']);
        $this->assertNotSame('ANAJ MANDI', $row['ca_name']);
        $this->assertNull($row['ca_name']);
        $this->assertContains('ca_name', $row['missing_required_fields']);
        $this->assertNull($row['address']);
        $this->assertSame([], $row['members']);
    }

    public function test_urban_estate_huda_is_not_extracted_as_ca_name(): void
    {
        $row = $this->extractor()->extract($this->tokens([
            'MEHTA & CO',
            'URBAN ESTATE HUDA',
            'KARNAL',
        ]), ['section_city' => 'KARNAL', 'sequence_no' => 2]);

        $this->assertNotNull($row);
        $this->assertSame('MEHTA & CO', $row['firm_name']);
        $this->assertNotSame('URBAN ESTATE HUDA', $row['ca_name']);
        $this->assertNull($row['ca_name']);
        $this->assertSame('KARNAL', $row['city']);
    }

    public function test_firm_ca_city_stay_in_own_fields(): void
    {
        $row = $this->extractor()->extract($this->tokens([
            'GUPTA ASSOCIATES CHARTERED ACCOUNTANTS',
            'RAJESH KUMAR GUPTA',
            '12 MG ROAD ANAJ MANDI',
            '501946',
            '9812345678',
        ]), ['section_city' => 'HISAR', 'sequence_no' => 3]);

        $this->assertSame('GUPTA ASSOCIATES CHARTERED ACCOUNTANTS', $row['firm_name']);
        $this->assertSame('RAJESH KUMAR GUPTA', $row['ca_name']);
        $this->assertSame('HISAR', $row['city']);
        $this->assertNull($row['address']);
        $this->assertNull($row['phone']);
        $this->assertNull($row['membership_no']);
        $this->assertNull($row['pincode']);
        $this->assertNull($row['frn']);
        $this->assertSame($row['firm_name'], $row['raw_firm_name']);
        $this->assertSame($row['ca_name'], $row['raw_ca_name']);
        $this->assertSame($row['city'], $row['raw_city']);
    }

    public function test_neighboring_rows_are_not_merged(): void
    {
        $row1 = $this->extractor()->extract($this->tokens([
            'ALPHA & ASSOCIATES',
            'AMIT SHARMA',
        ], 1, 0), ['section_city' => 'DELHI', 'sequence_no' => 1, 'page' => 1, 'column' => 0]);

        $row2 = $this->extractor()->extract($this->tokens([
            'BETA LLP',
            'BINA VERMA',
        ], 1, 1), ['section_city' => 'DELHI', 'sequence_no' => 2, 'page' => 1, 'column' => 1]);

        $this->assertSame('ALPHA & ASSOCIATES', $row1['firm_name']);
        $this->assertSame('AMIT SHARMA', $row1['ca_name']);
        $this->assertSame('BETA LLP', $row2['firm_name']);
        $this->assertSame('BINA VERMA', $row2['ca_name']);
        $this->assertNotSame($row1['firm_name'], $row2['firm_name']);
        $this->assertNotSame($row1['ca_name'], $row2['ca_name']);
    }

    public function test_missing_city_blocks_auto_match_status(): void
    {
        $validation = (new OcrFieldValidationService(new DataNormalizationService))->validateFirm([
            'firm_name' => 'SHAH & ASSOCIATES',
            'ca_name' => 'RAVI SHAH',
            'city' => null,
            'field_meta' => [
                'firm_name' => ['confidence' => 0.95],
                'ca_name' => ['confidence' => 0.9],
            ],
        ]);

        $this->assertFalse($validation['ok']);
        $this->assertFalse($validation['auto_apply_ok']);
        $this->assertTrue(collect($validation['errors'])->contains(fn ($e) => str_contains($e, 'city')));
    }

    public function test_missing_ca_name_blocks_auto_match_status(): void
    {
        $validation = (new OcrFieldValidationService(new DataNormalizationService))->validateFirm([
            'firm_name' => 'SHAH & ASSOCIATES',
            'ca_name' => null,
            'city' => 'ROHTAK',
            'field_meta' => [
                'firm_name' => ['confidence' => 0.95],
                'city' => ['confidence' => 0.9],
            ],
        ]);

        $this->assertFalse($validation['ok']);
        $this->assertTrue(collect($validation['errors'])->contains(fn ($e) => str_contains($e, 'ca_name')));
    }

    public function test_ignored_fields_do_not_affect_matching(): void
    {
        $city = \App\Models\City::query()->where('city_name', 'Mumbai')->first()
            ?: \App\Models\City::query()->orderBy('city_id')->first();
        if ($city === null) {
            $this->markTestSkipped('City master not seeded in test DB.');
        }

        $normalizer = new DataNormalizationService;
        $firmName = 'Exact Match Associates '.uniqid();
        $caName = 'Exact Person';
        $normFirm = $normalizer->firmName($firmName);
        $normCa = $normalizer->caName($caName);

        $lead = \App\Models\CaMaster::query()->create([
            'firm_name' => $firmName,
            'ca_name' => $caName,
            'normalized_firm_name' => $normFirm,
            'normalized_ca_name' => $normCa,
            'city_id' => $city->city_id,
            'status' => 'New',
            'rating' => 1,
        ]);

        $profile = app(FirmCaCityMatchingProfile::class);
        $matchA = $profile->match([
            'firm_name' => $firmName,
            'ca_name' => $caName,
            'city' => $city->city_name,
            'phone' => '9999999999',
            'frn' => '019083N',
            'membership_no' => '501946',
            'address' => 'ANAJ MANDI',
            'gst_no' => '07AAAAA0000A1Z5',
        ]);
        $matchB = $profile->match([
            'firm_name' => $firmName,
            'ca_name' => $caName,
            'city' => $city->city_name,
            'phone' => '1111111111',
            'frn' => 'DIFFERENT',
            'membership_no' => '000001',
            'address' => 'SOMEWHERE ELSE',
        ]);

        $this->assertTrue($matchA->isExact());
        $this->assertTrue($matchB->isExact());
        $this->assertSame((int) $lead->ca_id, (int) $matchA->caId);
        $this->assertSame((int) $lead->ca_id, (int) $matchB->caId);
    }

    public function test_collision_service_ignores_partner_address_noise_in_three_field_mode(): void
    {
        $result = (new OcrFieldCollisionService)->detect([
            'firm_name' => 'Shah & Associates',
            'ca_name' => 'Ravi Shah',
            'city' => 'Rohtak',
            'address' => 'should be ignored',
            'members' => [['ca_name' => '12 MG Road']],
            'membership_no' => '124001',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertNotContains('ADDRESS_IN_PARTNER_FIELD', $result['codes']);
        $this->assertNotContains('PIN_IN_MEMBERSHIP_FIELD', $result['codes']);
    }

    public function test_verification_never_auto_applies_three_field_rows(): void
    {
        $verifier = new OcrSourceVerificationService(
            new OcrFieldValidationService(new DataNormalizationService),
            new OcrFieldCollisionService,
        );
        $result = $verifier->verify([
            'firm_name' => 'Shah & Associates',
            'ca_name' => 'Ravi Shah',
            'city' => 'Rohtak',
            'overall_confidence' => 0.99,
            'parser_confidence' => 0.99,
            'structural_confidence' => 0.99,
            'field_meta' => [
                'firm_name' => ['confidence' => 0.99],
                'ca_name' => ['confidence' => 0.99],
                'city' => ['confidence' => 0.99],
            ],
            'raw' => ['firm_name' => 'Shah & Associates', 'ca_name' => 'Ravi Shah', 'city' => 'Rohtak'],
            'parsed' => ['firm_name' => 'Shah & Associates', 'ca_name' => 'Ravi Shah', 'city' => 'Rohtak'],
        ]);

        $this->assertFalse($result['auto_apply_ok']);
    }
}
