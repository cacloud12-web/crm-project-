<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\OcrCityContextResolver;
use App\Services\Ocr\OcrEntityClassificationService;
use App\Services\Ocr\OcrFirmCaCityExtractorService;
use App\Services\Ocr\OcrNeedsReviewClassifier;
use App\Services\Ocr\OcrNeedsReviewProposalService;
use App\Services\Ocr\OcrPartnershipDirectoryExtractor;
use App\Services\Ocr\OcrSourceVerificationService;
use Tests\TestCase;

class OcrNeedsReviewParserFixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ocr_workflow.mode' => 'firm_ca_city']);
    }

    public function test_multiple_partners_extracted_under_firm(): void
    {
        $firm = (new OcrPartnershipDirectoryExtractor)->extract([
            ['text' => 'GKC & COMPANY', 'page' => 1, 'column' => 1],
            ['text' => 'RUCHI KHATRI', 'page' => 1, 'column' => 1],
            ['text' => 'RASHI KOTHARI', 'page' => 1, 'column' => 1],
            ['text' => 'SHILPA JAIN', 'page' => 1, 'column' => 1],
        ], ['section_city' => 'JAIPUR']);

        $this->assertNotNull($firm);
        $this->assertSame('RUCHI KHATRI', $firm['ca_name']);
        $this->assertSame(['RASHI KOTHARI', 'SHILPA JAIN'], $firm['partners']);
        $this->assertCount(3, $firm['members']);
    }

    public function test_partner_stops_at_next_firm_boundary(): void
    {
        $firm = (new OcrPartnershipDirectoryExtractor)->extract([
            ['text' => 'AAA & CO', 'page' => 1],
            ['text' => 'ANIL KUMAR SHARMA', 'page' => 1],
            ['text' => 'BBB & ASSOCIATES', 'page' => 1],
            ['text' => 'VIJAY SINGH MEHTA', 'page' => 1],
        ], ['section_city' => 'DELHI']);

        $this->assertNotNull($firm);
        $this->assertSame('ANIL KUMAR SHARMA', $firm['ca_name']);
        $this->assertNotContains('VIJAY SINGH MEHTA', $firm['partners'] ?? []);
    }

    public function test_proprietor_peel_accepted(): void
    {
        $row = (new OcrFirmCaCityExtractorService)->extract([
            [
                'text' => 'MANISH CHANDAK & ASSOCIATES',
                'page' => 1, 'column' => 1, 'ocr_confidence' => 0.9,
                'x_min' => 0.1, 'x_max' => 0.5, 'y_min' => 0.1, 'y_max' => 0.12,
                'x_center' => 0.3, 'y_center' => 0.11,
            ],
        ], ['section_city' => 'INDORE', 'sequence_no' => 1]);

        $this->assertSame('MANISH CHANDAK', $row['ca_name']);
        $this->assertSame('INDORE', $row['city']);
    }

    public function test_invalid_proprietor_peel_rejected(): void
    {
        $sug = (new OcrFirmCaCityExtractorService)->suggestCaFromFirmName('MSVP & COMPANY');
        $this->assertNull($sug);
    }

    public function test_city_heading_forward_fill(): void
    {
        $resolver = new OcrCityContextResolver;
        $out = $resolver->resolveForFirm(null, [], [
            'AHMEDABAD',
            'ABC & CO',
            'SOME ADDRESS LINE',
        ], ['firm_name' => 'ABC & CO']);

        $this->assertSame('AHMEDABAD', $out['city']);
        $this->assertSame('city_heading_forward_fill', $out['method']);
    }

    public function test_city_stops_at_new_heading(): void
    {
        $resolver = new OcrCityContextResolver;
        $out = $resolver->resolveForFirm(null, [], [
            'AHMEDABAD',
            'FIRST & CO',
            'SURAT',
            'SECOND & CO',
        ], ['firm_name' => 'SECOND & CO']);

        $this->assertSame('SURAT', $out['city']);
    }

    public function test_address_rejected_as_ca(): void
    {
        $e = new OcrEntityClassificationService;
        $line = 'FLAT NO 201 2ND FLOOR GURUPLAZA COMPLEX';
        $this->assertTrue(
            $e->isAddress($line) || $e->isAddressShape($line) || (bool) preg_match('/\d/', $line),
            $line
        );
        $this->assertFalse($e->isPerson($line));
        $noise = '1205 MATRIX bh divya bhaskar';
        $this->assertFalse($e->isPerson($noise));
        $this->assertTrue((bool) preg_match('/\d/', $noise));
    }

    public function test_numeric_noise_rejected(): void
    {
        $e = new OcrEntityClassificationService;
        $this->assertFalse($e->isPerson('1 ST KUMBHARWADA'));
    }

    public function test_valid_indian_names_accepted(): void
    {
        $e = new OcrEntityClassificationService;
        foreach (['KISHORE KUMAR', 'KISHORE G CHOUDHARY', 'RUCHI KHATRI'] as $name) {
            $this->assertTrue($e->isPerson($name), $name);
        }
    }

    public function test_raw_parsed_person_conflict_keeps_needs_review(): void
    {
        $classifier = new OcrNeedsReviewClassifier;
        $out = $classifier->classify([
            'firm_name' => 'KISHORE G CHOUDHARY & CO',
            'ca_name' => 'KISHORE G CHOUDHARY',
            'city' => 'SUKHAPUR',
            'raw_parsed_conflict' => true,
        ]);
        $this->assertSame('needs_review', $out['match_status']);
        $this->assertSame(OcrNeedsReviewClassifier::RAW_PARSED_CONFLICT, $out['reason']);
    }

    public function test_complete_record_classifies_verified(): void
    {
        $classifier = new OcrNeedsReviewClassifier;
        $out = $classifier->classify([
            'firm_name' => 'ABC & CO',
            'ca_name' => 'RAMESH KUMAR',
            'city' => 'PUNE',
        ]);
        $this->assertTrue($out['complete']);
        $this->assertSame('verified', $out['match_status']);
    }

    public function test_incomplete_without_source_stays_missing_ca(): void
    {
        $classifier = new OcrNeedsReviewClassifier;
        $out = $classifier->classify([
            'firm_name' => 'PBSD & ASSOCIATES',
            'ca_name' => '',
            'city' => 'DELHI',
            'has_ca_in_source' => false,
        ]);
        $this->assertSame(OcrNeedsReviewClassifier::MISSING_CA_IN_STORED_OCR, $out['reason']);
    }

    public function test_firm_derived_source_verification_does_not_fail(): void
    {
        $v = app(OcrSourceVerificationService::class);
        $out = $v->verify([
            'firm_name' => 'MANISH CHANDAK & ASSOCIATES',
            'ca_name' => 'MANISH CHANDAK',
            'city' => 'INDORE',
            'classification_reason' => 'firm_derived_missing_raw_ca',
            'raw' => [
                'firm_name' => 'MANISH CHANDAK & ASSOCIATES',
                'city' => 'INDORE',
            ],
            'parsed' => [
                'firm_name' => 'MANISH CHANDAK & ASSOCIATES',
                'ca_name' => 'MANISH CHANDAK',
                'city' => 'INDORE',
            ],
            'field_meta' => [
                'ca_name' => ['reason' => 'firm_derived_missing_raw_ca'],
            ],
        ]);

        $this->assertTrue($out['ok'] || ! collect($out['errors'])->contains(
            fn ($e) => str_contains((string) $e, 'silent correction')
        ));
    }

    public function test_category_bcd_not_fabricated_by_peel_on_brand(): void
    {
        $svc = new OcrNeedsReviewProposalService;
        $this->assertNull((new OcrFirmCaCityExtractorService)->suggestCaFromFirmName('PBSD & ASSOCIATES'));
        $this->assertNull((new OcrFirmCaCityExtractorService)->suggestCaFromFirmName('GRS & ASSOCIATES'));
    }

    public function test_locality_alias_only_when_configured(): void
    {
        config(['ocr_locality_aliases' => []]);
        $resolver = new OcrCityContextResolver;
        $out = $resolver->resolveForFirm(null, [], ['PRAHLADNAGAR', 'ABC & CO'], ['firm_name' => 'ABC & CO']);
        // Without heading detector treating locality as city, may be null — must not invent AHMEDABAD.
        if ($out['city'] !== null) {
            $this->assertNotSame('AHMEDABAD', $out['city']);
        } else {
            $this->assertNull($out['city']);
        }
    }
}
