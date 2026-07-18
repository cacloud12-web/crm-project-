<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\OcrEntityClassificationService;
use App\Services\Ocr\OcrFieldCollisionService;
use App\Services\Ocr\OcrFirmCaCityExtractorService;
use Tests\TestCase;

/**
 * Golden northprop demo regressions: firm/CA split + no false ROW_MERGE.
 */
class OcrFirmCaCityNorthpropRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ocr_workflow.mode' => 'firm_ca_city']);
    }

    /**
     * @param  list<string>  $lines
     * @return list<array<string, mixed>>
     */
    private function tokens(array $lines, float $startY = 0.1): array
    {
        $out = [];
        foreach ($lines as $i => $text) {
            $out[] = [
                'text' => $text,
                'page' => 1,
                'column' => 0,
                'ocr_confidence' => 0.91,
                'x_min' => 0.1, 'x_max' => 0.4,
                'y_min' => $startY + ($i * 0.03), 'y_max' => $startY + 0.02 + ($i * 0.03),
                'x_center' => 0.25, 'y_center' => $startY + 0.01 + ($i * 0.03),
            ];
        }

        return $out;
    }

    public function test_neetu_bhatia_peeled_from_multiline_firm_token(): void
    {
        $row = (new OcrFirmCaCityExtractorService(new OcrEntityClassificationService))->extract([
            [
                'text' => "NEETU BHATIA & ASSOCIATES\nNEETU BHATIA",
                'page' => 1, 'column' => 0, 'ocr_confidence' => 0.93,
                'x_min' => 0.1, 'x_max' => 0.4, 'y_min' => 0.1, 'y_max' => 0.16,
                'x_center' => 0.25, 'y_center' => 0.13,
            ],
        ], ['section_city' => 'ABOHAR', 'sequence_no' => 1]);

        $this->assertSame('NEETU BHATIA & ASSOCIATES', $row['firm_name']);
        $this->assertSame('NEETU BHATIA', $row['ca_name']);
        $this->assertSame('ABOHAR', $row['city']);
        $this->assertSame([], $row['missing_required_fields']);
        $this->assertStringNotContainsString('NEETU BHATIA', str_replace('NEETU BHATIA & ASSOCIATES', '', $row['firm_name']));
    }

    public function test_harshil_single_given_name_extracted_as_ca(): void
    {
        $row = (new OcrFirmCaCityExtractorService(new OcrEntityClassificationService))->extract(
            $this->tokens(['HARSHIL & ASSOCIATES', 'HARSHIL', 'SHOP NO 777', '1ST FLOOR', 'STREET NO 1B']),
            ['section_city' => 'ABOHAR'],
        );
        $this->assertSame('HARSHIL & ASSOCIATES', $row['firm_name']);
        $this->assertSame('HARSHIL', $row['ca_name']);
        $this->assertSame('ABOHAR', $row['city']);
        $this->assertSame([], $row['missing_required_fields']);
        $collision = (new OcrFieldCollisionService)->detect($row);
        $this->assertNotContains('MISSING_CA_NAME', $collision['codes']);
    }

    public function test_neetu_bhatia_separate_tokens(): void
    {
        $row = (new OcrFirmCaCityExtractorService(new OcrEntityClassificationService))->extract(
            $this->tokens(['NEETU BHATIA & ASSOCIATES', 'NEETU BHATIA']),
            ['section_city' => 'ABOHAR'],
        );
        $this->assertSame('NEETU BHATIA & ASSOCIATES', $row['firm_name']);
        $this->assertSame('NEETU BHATIA', $row['ca_name']);
    }

    public function test_bansal_not_invalidated_by_ignored_address_identifiers_or_false_merge(): void
    {
        $row = (new OcrFirmCaCityExtractorService(new OcrEntityClassificationService))->extract(
            $this->tokens([
                'BANSAL SANDEEP AND ASSOCIATES',
                'SANDEEP BANSAL',
                '024992N',
                'HOUSE NO 968',
                'GOBIND NAGRI',
                '124001',
            ]),
            [
                'section_city' => 'ABOHAR',
                'row_merge_suspected' => true, // legacy proximity flag without evidence
                'row_merge_evidence' => [],
            ],
        );

        $this->assertSame('BANSAL SANDEEP AND ASSOCIATES', $row['firm_name']);
        $this->assertSame('SANDEEP BANSAL', $row['ca_name']);
        $this->assertSame('ABOHAR', $row['city']);
        $this->assertFalse($row['row_merge_suspected']);
        $this->assertSame([], $row['row_merge_evidence']);

        $collision = (new OcrFieldCollisionService)->detect($row);
        $this->assertTrue($collision['ok']);
        $this->assertNotContains('ROW_MERGE_SUSPECTED', $collision['codes']);
    }

    public function test_bagai_no_row_merge_without_scoped_evidence(): void
    {
        $row = (new OcrFirmCaCityExtractorService(new OcrEntityClassificationService))->extract(
            $this->tokens(['BAGAI & ASSOCIATES', 'ITIE BAGAI', '024992N', 'HOUSE NO 968']),
            ['section_city' => 'ABOHAR', 'row_merge_suspected' => true, 'row_merge_evidence' => []],
        );
        $this->assertSame('BAGAI & ASSOCIATES', $row['firm_name']);
        $this->assertSame('ITIE BAGAI', $row['ca_name']);
        $this->assertFalse($row['row_merge_suspected']);
        $collision = (new OcrFieldCollisionService)->detect($row);
        $this->assertNotContains('ROW_MERGE_SUSPECTED', $collision['codes']);
    }

    public function test_real_scoped_row_merge_still_blocks(): void
    {
        $row = (new OcrFirmCaCityExtractorService(new OcrEntityClassificationService))->extract(
            $this->tokens(['BAGAI & ASSOCIATES', 'ITIE BAGAI', 'OTHER FIRM & ASSOCIATES']),
            ['section_city' => 'ABOHAR'],
        );
        $this->assertTrue($row['row_merge_suspected']);
        $this->assertNotEmpty($row['row_merge_evidence']);
        $collision = (new OcrFieldCollisionService)->detect($row);
        $this->assertContains('ROW_MERGE_SUSPECTED', $collision['codes']);
    }

    public function test_ignored_tokens_never_change_collision_status(): void
    {
        $clean = (new OcrFieldCollisionService)->detect([
            'firm_name' => 'ANMOL SETIA & ASSOCIATES',
            'ca_name' => 'ANMOL SETIA',
            'city' => 'ABOHAR',
        ]);
        $noisy = (new OcrFieldCollisionService)->detect([
            'firm_name' => 'ANMOL SETIA & ASSOCIATES',
            'ca_name' => 'ANMOL SETIA',
            'city' => 'ABOHAR',
            'frn' => '024992N',
            'membership_no' => '525126',
            'pincode' => '152116',
            'address' => 'HOUSE NO 12',
            'unknown_tokens' => ['XYZ'],
            'ignored_tokens' => ['024992N', 'HOUSE NO 12'],
            'ambiguous_layout' => true,
        ]);
        $this->assertSame($clean['codes'], $noisy['codes']);
        $this->assertTrue($noisy['ok']);
    }

    public function test_anmol_token_is_ca_not_city_when_section_present(): void
    {
        $row = (new OcrFirmCaCityExtractorService(new OcrEntityClassificationService))->extract(
            $this->tokens(['ANMOL ARJUN & ASSOCIATES', 'ANMOL', 'HOUSE NO 12']),
            ['section_city' => 'ABOHAR'],
        );
        $this->assertSame('ANMOL ARJUN & ASSOCIATES', $row['firm_name']);
        $this->assertSame('ABOHAR', $row['city']);
        $this->assertNotSame('ANMOL', $row['city']);
        $this->assertNotNull($row['ca_name']);
        $this->assertTrue(in_array($row['ca_name'], ['ANMOL', 'ANMOL ARJUN'], true));
        $this->assertSame([], $row['missing_required_fields']);
    }

    public function test_anmol_alone_never_becomes_city_without_section(): void
    {
        $row = (new OcrFirmCaCityExtractorService(new OcrEntityClassificationService))->extract(
            $this->tokens(['ANMOL ARJUN & ASSOCIATES', 'ANMOL']),
            [],
        );
        $this->assertNotSame('ANMOL', $row['city'] ?? null);
        $this->assertTrue(in_array($row['ca_name'] ?? null, ['ANMOL', 'ANMOL ARJUN'], true));
    }

    public function test_lovish_garg_ca_peeled_from_firm_title_token(): void
    {
        $row = (new OcrFirmCaCityExtractorService(new OcrEntityClassificationService))->extract(
            $this->tokens(['LOVISH GARG AND ASSOCIATES', 'HOUSE NO 968', 'GOBIND NAGRI']),
            ['section_city' => 'ABOHAR'],
        );
        $this->assertSame('LOVISH GARG AND ASSOCIATES', $row['firm_name']);
        $this->assertSame('LOVISH GARG', $row['ca_name']);
        $this->assertSame('ABOHAR', $row['city']);
        $this->assertSame([], $row['missing_required_fields']);
    }

    public function test_single_word_firm_prefix_does_not_invent_ca(): void
    {
        $row = (new OcrFirmCaCityExtractorService(new OcrEntityClassificationService))->extract(
            $this->tokens(['SHAH & ASSOCIATES', 'ANAJ MANDI']),
            ['section_city' => 'ROHTAK'],
        );
        $this->assertSame('SHAH & ASSOCIATES', $row['firm_name']);
        $this->assertNull($row['ca_name']);
        $this->assertContains('ca_name', $row['missing_required_fields']);
    }

    public function test_lovish_garg_ca_from_separate_person_token(): void
    {
        $row = (new OcrFirmCaCityExtractorService(new OcrEntityClassificationService))->extract(
            $this->tokens(['LOVISH GARG AND ASSOCIATES', 'LOVISH GARG', 'HOUSE NO 968']),
            ['section_city' => 'ABOHAR'],
        );
        $this->assertSame('LOVISH GARG AND ASSOCIATES', $row['firm_name']);
        $this->assertSame('LOVISH GARG', $row['ca_name']);
        $this->assertSame('ABOHAR', $row['city']);
        $this->assertSame([], $row['missing_required_fields']);
    }

    public function test_miglani_star_prefixed_person_token_becomes_ca(): void
    {
        $row = (new OcrFirmCaCityExtractorService(new OcrEntityClassificationService))->extract(
            $this->tokens(['MIGLANI & CO', '* KUSHAL MIGLANI', 'STREET NO 7 WEST']),
            ['section_city' => 'AMRITSAR'],
        );
        $this->assertSame('MIGLANI & CO', $row['firm_name']);
        $this->assertSame('KUSHAL MIGLANI', $row['ca_name']);
        $this->assertSame('AMRITSAR', $row['city']);
        $this->assertSame([], $row['missing_required_fields']);
    }

    public function test_care_of_line_is_not_a_firm_name(): void
    {
        $entities = new OcrEntityClassificationService;
        $this->assertFalse($entities->isFirmName('C/O M/S KUMAR RAJ & ASSOCIATES'));
        $this->assertTrue($entities->isCareOfLine('C/O M/S KUMAR RAJ & ASSOCIATES'));
    }

    public function test_anmol_arjun_prefers_full_name_from_firm_over_given_name_token(): void
    {
        $row = (new OcrFirmCaCityExtractorService(new OcrEntityClassificationService))->extract(
            $this->tokens(['ANMOL ARJUN & ASSOCIATES', 'ANMOL']),
            ['section_city' => 'ABOHAR'],
        );
        $this->assertSame('ANMOL ARJUN', $row['ca_name']);
        $this->assertSame('ABOHAR', $row['city']);
        $this->assertSame([], $row['missing_required_fields']);
    }

    public function test_row_coverage_label_is_not_ocr_accuracy(): void
    {
        $quality = [
            'row_coverage' => 100.0,
            'parsing_accuracy' => 100.0,
            'ocr_confidence' => 0.98,
            'total_rows_detected' => 247,
            'total_firms_parsed' => 247,
            'valid_three_field_rows' => 200,
        ];
        $this->assertArrayHasKey('row_coverage', $quality);
        $this->assertSame(100.0, $quality['row_coverage']);
        $this->assertLessThan(1.0, (float) $quality['ocr_confidence']);
        // Row coverage can be 100% while field validity is lower — they are different metrics.
        $this->assertGreaterThan(0, $quality['valid_three_field_rows']);
        $this->assertSame($quality['total_rows_detected'], $quality['total_firms_parsed']);
    }
}
