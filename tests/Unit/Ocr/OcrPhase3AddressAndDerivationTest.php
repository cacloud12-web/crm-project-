<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\OcrEntityClassificationService;
use App\Services\Ocr\OcrFirmCaCityExtractorService;
use App\Services\Ocr\OcrHumanNameClassifier;
use App\Services\Ocr\OcrSourceVerificationService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

class OcrPhase3AddressAndDerivationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ocr_workflow.mode' => 'firm_ca_city']);
    }

    private function entities(): OcrEntityClassificationService
    {
        return new OcrEntityClassificationService;
    }

    #[DataProvider('addressAsCaProvider')]
    public function test_numeric_building_lines_are_address_not_ca(string $line): void
    {
        $e = $this->entities();
        $this->assertTrue($e->isAddress($line) || $e->isAddressShape($line), $line);
        $this->assertFalse($e->isPerson($line), $line);

        $classified = $e->classifyNameCandidate($line);
        $this->assertTrue($classified['is_address']);
        $this->assertFalse($classified['is_person']);
        $this->assertContains($classified['reason'], [
            'address_numeric_prefix',
            'address_building_keyword',
        ]);
        $this->assertSame($line, $classified['original']);
    }

    /** @return array<string, array{0: string}> */
    public static function addressAsCaProvider(): array
    {
        return [
            'nirman' => ['14 NIRMAN SQUARE TENAMENT'],
            'anmol' => ['206 ANMOL BUSINESS CENTRE'],
            'sarvodaya' => ['510 SARVODAYA COMM'],
            'today' => ['308 TODAY SQUARE'],
        ];
    }

    public function test_leading_numeric_text_preserved_and_address_before_digit_strip(): void
    {
        $e = $this->entities();
        $raw = '14 NIRMAN SQUARE TENAMENT';
        $classified = $e->classifyNameCandidate($raw);
        $this->assertSame('address_numeric_prefix', $classified['reason']);
        $this->assertSame($raw, $classified['original']);
        $this->assertStringStartsWith('14', $e->stripPersonDecorations($raw));
        $this->assertFalse($e->isPerson('NIRMAN SQUARE TENAMENT'));
    }

    public function test_abhishek_associates_derives_suggestion(): void
    {
        $extractor = new OcrFirmCaCityExtractorService;
        $derived = $extractor->suggestCaFromFirmName('ABHISHEK P JAIN & ASSOCIATES', 'AHMEDABAD');
        $this->assertSame('ABHISHEK P JAIN', $derived);
    }

    public function test_raw_vs_derived_initial_expansion_stays_review(): void
    {
        $extractor = new OcrFirmCaCityExtractorService;
        $ref = new ReflectionClass($extractor);
        $method = $ref->getMethod('analyzeCaDerivation');
        $analysis = $method->invoke(
            $extractor,
            'ABHISHEK JAIN',
            'ABHISHEK P JAIN',
            'ABHISHEK P JAIN & ASSOCIATES',
        );
        $this->assertTrue($analysis['compatible']);
        $this->assertSame('initials_expansion_suggestion', $analysis['comparison_class']);
        $this->assertSame(['P'], $analysis['added']);
        $this->assertSame([], $analysis['removed']);
    }

    public function test_missing_raw_ca_with_strict_proprietary_firm_is_safe_suggestion(): void
    {
        $extractor = new OcrFirmCaCityExtractorService(new OcrEntityClassificationService);
        $row = $extractor->extract([
            [
                'text' => 'ABHISHEK P JAIN & ASSOCIATES',
                'page' => 1,
                'column' => 1,
                'ocr_confidence' => 0.9,
                'x_min' => 0.1, 'x_max' => 0.5, 'y_min' => 0.1, 'y_max' => 0.12,
                'x_center' => 0.3, 'y_center' => 0.11,
            ],
        ], ['section_city' => 'AHMEDABAD', 'sequence_no' => 1]);

        $this->assertNotNull($row);
        $this->assertSame('ABHISHEK P JAIN', $row['ca_name']);
        $this->assertNull($row['raw_ca_name']);
        $this->assertSame('firm_derived_missing_raw_ca', $row['classification_reason']);
        $this->assertTrue($row['field_meta']['suggested_ca_name']['safe_repair_candidate'] ?? false);
    }

    public function test_brand_entity_firm_does_not_derive_person(): void
    {
        $extractor = new OcrFirmCaCityExtractorService;
        $this->assertNull($extractor->suggestCaFromFirmName('GLOBAL SERVICES & ASSOCIATES', 'DELHI'));
        $this->assertNull($extractor->suggestCaFromFirmName('SHAH & ASSOCIATES', 'DELHI'));
    }

    public function test_multi_person_firm_does_not_auto_derive(): void
    {
        $extractor = new OcrFirmCaCityExtractorService;
        $this->assertNull($extractor->suggestCaFromFirmName('MEHTA AND SHAH AND CO', 'MUMBAI'));
        $this->assertNull($extractor->suggestCaFromFirmName('A & B & ASSOCIATES', 'MUMBAI'));
    }

    public function test_human_classifier_rejects_building_as_person(): void
    {
        $h = new OcrHumanNameClassifier($this->entities());
        $this->assertFalse($h->isValid('14 NIRMAN SQUARE TENAMENT'));
        $this->assertFalse($h->isValid('ANMOL BUSINESS CENTRE'));
    }

    public function test_source_verification_comparison_classes(): void
    {
        $v = app(OcrSourceVerificationService::class);
        $this->assertSame('exact', $v->comparisonClass('ABHISHEK JAIN', 'ABHISHEK JAIN'));
        $this->assertSame('formatting_only', $v->comparisonClass('Abhishek Jain', 'ABHISHEK JAIN'));
        $this->assertSame('safe_decoration_removal', $v->comparisonClass('CA ABHISHEK JAIN', 'ABHISHEK JAIN'));
        $this->assertSame('incompatible_change', $v->comparisonClass('14 NIRMAN SQUARE', 'NIRMAN SQUARE'));
    }
}
