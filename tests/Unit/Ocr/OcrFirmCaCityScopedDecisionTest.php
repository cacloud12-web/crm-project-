<?php

namespace Tests\Unit\Ocr;

use App\Models\CaMaster;
use App\Models\City;
use App\Services\Mapping\DataNormalizationService;
use App\Services\Mapping\FirmCaCityMatchingProfile;
use App\Services\Ocr\OcrEntityClassificationService;
use App\Services\Ocr\OcrFieldCollisionService;
use App\Services\Ocr\OcrFieldValidationService;
use App\Services\Ocr\OcrFirmCaCityExtractorService;
use App\Services\Ocr\OcrSourceVerificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Ignored FRN/address/PIN must never force Needs Review when firm+CA+city are valid.
 */
class OcrFirmCaCityScopedDecisionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ocr_workflow.mode' => 'firm_ca_city',
            'ocr_workflow.min_field_confidence' => 0.55,
            'ocr_workflow.min_firm_name_confidence' => 0.55,
            'ocr_workflow.min_ca_name_confidence' => 0.55,
            'ocr_workflow.min_city_confidence' => 0.55,
            'ocr_safety.require_verification' => true,
            'ocr_safety.min_required_field_confidence' => 0.99,
        ]);
    }

    private function bagaiTokens(): array
    {
        $lines = [
            'BAGAI & ASSOCIATES',
            'ITIE BAGAI',
            '024992N',
            '525126',
            'HOUSE NO 968',
            'GOBIND NAGRI STREET NO 1',
            '124001',
        ];
        $out = [];
        foreach ($lines as $i => $text) {
            $out[] = [
                'text' => $text,
                'page' => 1,
                'column' => 0,
                'ocr_confidence' => 0.91,
                'x_min' => 0.1, 'x_max' => 0.4,
                'y_min' => 0.1 + ($i * 0.04), 'y_max' => 0.13 + ($i * 0.04),
                'x_center' => 0.25, 'y_center' => 0.115 + ($i * 0.04),
            ];
        }

        return $out;
    }

    public function test_bagai_not_blocked_by_ignored_address_or_identifiers(): void
    {
        $row = (new OcrFirmCaCityExtractorService(new OcrEntityClassificationService))
            ->extract($this->bagaiTokens(), [
                'section_city' => 'ABOHAR',
                'sequence_no' => 1,
                'page' => 1,
                'ambiguous_record_boundary' => true, // must be ignored when 3 fields are clean
                'row_merge_suspected' => false,
            ]);

        $this->assertSame('BAGAI & ASSOCIATES', $row['firm_name']);
        $this->assertSame('ITIE BAGAI', $row['ca_name']);
        $this->assertSame('ABOHAR', $row['city']);
        $this->assertFalse($row['ambiguous_layout']);
        $this->assertContains('024992N', $row['ignored_tokens']);
        $this->assertContains('HOUSE NO 968', $row['ignored_tokens']);

        $collision = (new OcrFieldCollisionService)->detect($row);
        $this->assertTrue($collision['ok']);
        $this->assertNotContains('AMBIGUOUS_LAYOUT', $collision['codes']);
        $this->assertNotContains('LOW_FIELD_CONFIDENCE', $collision['codes']);

        $verifier = new OcrSourceVerificationService(
            new OcrFieldValidationService(new DataNormalizationService),
            new OcrFieldCollisionService,
        );
        $result = $verifier->verify(array_merge($row, [
            'raw' => ['firm_name' => $row['firm_name'], 'ca_name' => $row['ca_name'], 'city' => $row['city']],
            'parsed' => ['firm_name' => $row['firm_name'], 'ca_name' => $row['ca_name'], 'city' => $row['city']],
        ]));

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['verified']);
        $this->assertNotContains('AMBIGUOUS_LAYOUT', $result['collision_codes']);
        $this->assertNotContains('LOW_FIELD_CONFIDENCE', $result['collision_codes']);
        $this->assertSame([], $result['errors']);
    }

    public function test_low_confidence_address_does_not_cause_low_field_confidence(): void
    {
        $firm = [
            'firm_name' => 'BAGAI & ASSOCIATES',
            'ca_name' => 'ITIE BAGAI',
            'city' => 'ABOHAR',
            'address' => 'HOUSE NO 968',
            'overall_confidence' => 0.91,
            'parser_confidence' => 0.91,
            'structural_confidence' => 0.96,
            'field_meta' => [
                'firm_name' => ['confidence' => 0.91],
                'ca_name' => ['confidence' => 0.91],
                'city' => ['confidence' => 0.91],
                'address' => ['confidence' => 0.10],
                'frn' => ['confidence' => 0.05],
            ],
            'raw' => ['firm_name' => 'BAGAI & ASSOCIATES', 'ca_name' => 'ITIE BAGAI', 'city' => 'ABOHAR'],
            'parsed' => ['firm_name' => 'BAGAI & ASSOCIATES', 'ca_name' => 'ITIE BAGAI', 'city' => 'ABOHAR'],
        ];

        $result = (new OcrSourceVerificationService(
            new OcrFieldValidationService(new DataNormalizationService),
            new OcrFieldCollisionService,
        ))->verify($firm);

        $this->assertTrue($result['ok']);
        $this->assertNotContains('LOW_FIELD_CONFIDENCE', $result['collision_codes']);
    }

    public function test_unknown_frn_membership_do_not_cause_needs_review_codes(): void
    {
        $collision = (new OcrFieldCollisionService)->detect([
            'firm_name' => 'BANSAL SANDEEP AND ASSOCIATES',
            'ca_name' => 'SANDEEP BANSAL',
            'city' => 'ABOHAR',
            'frn' => '024992N',
            'membership_no' => '525126',
            'pincode' => '124001',
            'address' => 'GOBIND NAGRI',
            'unknown_tokens' => ['525126', '024992N'],
            'ambiguous_layout' => true, // legacy flag from ignored text — must not block
        ]);

        $this->assertTrue($collision['ok']);
        $this->assertSame([], $collision['codes']);
    }

    public function test_missing_fields_cause_scoped_needs_review_codes(): void
    {
        $missingCa = (new OcrFieldCollisionService)->detect([
            'firm_name' => 'ANMOL SETIA & ASSOCIATES',
            'ca_name' => null,
            'city' => 'ABOHAR',
            'missing_required_fields' => ['ca_name'],
        ]);
        $this->assertFalse($missingCa['ok']);
        $this->assertContains('MISSING_CA_NAME', $missingCa['codes']);

        $missingCity = (new OcrFieldCollisionService)->detect([
            'firm_name' => 'ANMOL SETIA & ASSOCIATES',
            'ca_name' => 'ANMOL SETIA',
            'city' => null,
            'missing_required_fields' => ['city'],
        ]);
        $this->assertContains('MISSING_CITY', $missingCity['codes']);

        $missingFirm = (new OcrFieldCollisionService)->detect([
            'firm_name' => null,
            'ca_name' => 'ANMOL SETIA',
            'city' => 'ABOHAR',
            'missing_required_fields' => ['firm_name'],
        ]);
        $this->assertContains('MISSING_FIRM_NAME', $missingFirm['codes']);
    }

    public function test_exact_unique_three_field_match_is_exact_verified(): void
    {
        $city = City::query()->where('city_name', 'Abohar')->first()
            ?: City::query()->where('city_name', 'ABOHAR')->first()
            ?: City::query()->where('city_name', 'Mumbai')->first()
            ?: City::query()->orderBy('city_id')->first();
        if ($city === null) {
            $this->markTestSkipped('City master missing');
        }

        $normalizer = new DataNormalizationService;
        $firmName = 'BANSAL SANDEEP AND ASSOCIATES '.uniqid();
        $caName = 'SANDEEP BANSAL';
        $lead = CaMaster::query()->create([
            'firm_name' => $firmName,
            'ca_name' => $caName,
            'normalized_firm_name' => $normalizer->firmName($firmName),
            'normalized_ca_name' => $normalizer->caName($caName),
            'city_id' => $city->city_id,
            'frn' => 'IGNORED'.random_int(1000, 9999),
            'status' => 'New',
            'rating' => 1,
        ]);

        $match = app(FirmCaCityMatchingProfile::class)->match([
            'firm_name' => $firmName,
            'ca_name' => $caName,
            'city' => $city->city_name,
            'frn' => 'DIFFERENT_FRN',
            'address' => 'HOUSE NO 1',
            'membership_no' => '999999',
        ]);

        $this->assertTrue($match->isExact());
        $this->assertSame((int) $lead->ca_id, (int) $match->caId);
        $this->assertSame('firm_ca_city_exact', $match->matchedOn);
    }

    public function test_row_merge_still_blocks(): void
    {
        $collision = (new OcrFieldCollisionService)->detect([
            'firm_name' => 'BAGAI & ASSOCIATES',
            'ca_name' => 'ITIE BAGAI',
            'city' => 'ABOHAR',
            'row_merge_suspected' => true,
            'row_merge_evidence' => [[
                'affected_field' => 'firm_name',
                'token' => 'OTHER FIRM & ASSOCIATES',
                'reason' => 'second_firm_name_token_in_same_record',
            ]],
        ]);
        $this->assertFalse($collision['ok']);
        $this->assertContains('ROW_MERGE_SUSPECTED', $collision['codes']);
        $this->assertNotContains('AMBIGUOUS_LAYOUT', $collision['codes']);
    }

    public function test_proximity_row_merge_flag_without_evidence_is_ignored(): void
    {
        $collision = (new OcrFieldCollisionService)->detect([
            'firm_name' => 'BANSAL SANDEEP AND ASSOCIATES',
            'ca_name' => 'SANDEEP BANSAL',
            'city' => 'ABOHAR',
            'row_merge_suspected' => true,
            'row_merge_evidence' => [],
            'ignored_tokens' => ['024992N', 'HOUSE NO 968', '124001'],
        ]);
        $this->assertTrue($collision['ok']);
        $this->assertNotContains('ROW_MERGE_SUSPECTED', $collision['codes']);
    }

    public function test_dense_directory_does_not_flag_second_firm_as_merge(): void
    {
        $classifier = new OcrEntityClassificationService;
        $seg = new \App\Services\Ocr\OcrRecordSegmentationService($classifier);
        $tokens = [];
        $y = 0.1;
        foreach ([
            'BANSAL SANDEEP AND ASSOCIATES', 'SANDEEP BANSAL', '024992N', 'HOUSE NO 1',
            'BAGAI & ASSOCIATES', 'ITIE BAGAI', '525126',
        ] as $text) {
            $tokens[] = [
                'text' => $text, 'page' => 1, 'column' => 0, 'ocr_confidence' => 0.91,
                'x_min' => 0.1, 'x_max' => 0.35, 'y_min' => $y, 'y_max' => $y + 0.015,
                'x_center' => 0.22, 'y_center' => $y + 0.007,
            ];
            $y += 0.018;
        }
        $blocks = $seg->segmentPage($tokens);
        $firmBlocks = array_values(array_filter($blocks, static fn ($b) => empty($b['is_section_heading'])));
        $this->assertCount(2, $firmBlocks);
        $this->assertFalse($firmBlocks[0]['row_merge_suspected'] ?? false);
        $this->assertFalse($firmBlocks[1]['row_merge_suspected'] ?? false);
    }
}
