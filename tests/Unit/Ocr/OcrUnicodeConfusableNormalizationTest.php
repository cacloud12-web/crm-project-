<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\OcrEntityClassificationService;
use App\Services\Ocr\OcrFieldCollisionService;
use App\Services\Ocr\OcrFirmCaCityExtractorService;
use App\Services\Ocr\OcrUnicodeNormalizationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OcrUnicodeConfusableNormalizationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ocr_workflow.mode' => 'firm_ca_city']);
    }

    /** Greek Τ Ι Α look-alikes (as returned by Document AI). */
    private function anmolSetiaRaw(): string
    {
        return "ANMOL SE\u{03A4}\u{0399}\u{0391}"; // ANMOL SEΤΙΑ
    }

    public function test_anmol_setia_confusable_normalizes_to_latin(): void
    {
        $raw = $this->anmolSetiaRaw();
        $result = (new OcrUnicodeNormalizationService)->normalizeForClassification($raw);

        $this->assertSame($raw, $result['raw_value']);
        $this->assertSame('ANMOL SETIA', $result['classification_value']);
        $this->assertTrue($result['confusable_replaced']);
        $this->assertSame('unicode_confusable_normalized', $result['reason']);
        $this->assertLessThan(1.0, $result['unicode_confidence']);
    }

    public function test_confusable_person_classifies_as_person_not_unknown(): void
    {
        $raw = $this->anmolSetiaRaw();
        $classified = (new OcrEntityClassificationService)->classify($raw);

        $this->assertSame(OcrEntityClassificationService::PERSON, $classified['entity_type']);
        $this->assertSame('ca_name', $classified['crm_field']);
        $this->assertSame($raw, $classified['raw_value']);
        $this->assertSame('ANMOL SETIA', $classified['classification_value']);
        $this->assertSame('ANMOL SETIA', $classified['normalized']);
        $this->assertTrue($classified['confusable_replaced']);
        $this->assertSame('unicode_confusable_normalized', $classified['unicode_reason']);
        $this->assertGreaterThan(0.5, $classified['confidence']);
        $this->assertNotSame(OcrEntityClassificationService::UNKNOWN, $classified['entity_type']);
    }

    public function test_golden_anmol_setia_associates_record(): void
    {
        $rawPerson = $this->anmolSetiaRaw();
        $tokens = [];
        foreach ([
            'ANMOL SETIA & ASSOCIATES',
            $rawPerson,
            'HOUSE NO 12',
            '024992N',
        ] as $i => $text) {
            $tokens[] = [
                'text' => $text,
                'page' => 1,
                'column' => 0,
                'ocr_confidence' => 0.91,
                'x_min' => 0.1, 'x_max' => 0.4,
                'y_min' => 0.1 + ($i * 0.04), 'y_max' => 0.13 + ($i * 0.04),
                'x_center' => 0.25, 'y_center' => 0.115 + ($i * 0.04),
            ];
        }

        $row = (new OcrFirmCaCityExtractorService(new OcrEntityClassificationService))
            ->extract($tokens, ['section_city' => 'ABOHAR', 'sequence_no' => 1, 'page' => 1]);

        $this->assertNotNull($row);
        $this->assertSame('ANMOL SETIA & ASSOCIATES', $row['firm_name']);
        $this->assertSame('ANMOL SETIA', $row['ca_name']);
        $this->assertSame('ABOHAR', $row['city']);
        $this->assertSame($rawPerson, $row['raw_ca_name']);
        $this->assertSame('unicode_confusable_normalized', $row['classification_reason']);
        $this->assertSame([], $row['missing_required_fields']);

        $collision = (new OcrFieldCollisionService)->detect($row);
        $this->assertTrue($collision['ok']);
        $this->assertNotContains('MISSING_CA_NAME', $collision['codes']);
        $this->assertSame([], $collision['codes']);
    }

    public function test_pure_greek_name_is_not_blindly_latinized(): void
    {
        $pureGreek = "ΝΙΚΟΣ ΠΑΠΑΔΟΠΟΥΛΟΣ";
        $result = (new OcrUnicodeNormalizationService)->normalizeForClassification($pureGreek);

        $this->assertFalse($result['confusable_replaced']);
        $this->assertSame($pureGreek, $result['classification_value']);
    }

    public function test_address_still_rejected_as_ca_name(): void
    {
        $classified = (new OcrEntityClassificationService)->classify('ANAJ MANDI');
        $this->assertSame(OcrEntityClassificationService::ADDRESS, $classified['entity_type']);
        $this->assertFalse((new OcrEntityClassificationService)->isPerson('ANAJ MANDI'));
    }

    public function test_firm_name_not_copied_into_ca_name(): void
    {
        $row = (new OcrFirmCaCityExtractorService(new OcrEntityClassificationService))->extract([
            [
                'text' => 'ANMOL SETIA & ASSOCIATES',
                'page' => 1, 'column' => 0, 'ocr_confidence' => 0.95,
                'x_min' => 0.1, 'x_max' => 0.4, 'y_min' => 0.1, 'y_max' => 0.12,
                'x_center' => 0.25, 'y_center' => 0.11,
            ],
            [
                'text' => 'HOUSE NO 968 GOBIND NAGRI',
                'page' => 1, 'column' => 0, 'ocr_confidence' => 0.9,
                'x_min' => 0.1, 'x_max' => 0.4, 'y_min' => 0.14, 'y_max' => 0.16,
                'x_center' => 0.25, 'y_center' => 0.15,
            ],
        ], ['section_city' => 'ABOHAR', 'sequence_no' => 1]);

        $this->assertSame('ANMOL SETIA & ASSOCIATES', $row['firm_name']);
        // Multi-word person prefix peeled from firm title token (same visual record).
        $this->assertSame('ANMOL SETIA', $row['ca_name']);
        $this->assertNotSame($row['firm_name'], $row['ca_name']);
        $this->assertSame([], $row['missing_required_fields']);
    }

    public function test_blocking_errors_dedupe_ca_name_required(): void
    {
        $resource = new \App\Http\Resources\OcrParsedFirmResource(new \App\Models\OcrParsedFirm([
            'firm_name' => 'ANMOL SETIA & ASSOCIATES',
            'city' => 'ABOHAR',
            'match_reason' => null,
            'review_status' => 'pending',
            'match_status' => 'needs_review',
            'validation_errors' => ['MISSING_CA_NAME'],
            'source_data' => [
                'validation' => [
                    'errors' => [
                        'ca_name: CA name is required.',
                        'CA name is required.',
                    ],
                    'collision_codes' => ['MISSING_CA_NAME'],
                ],
            ],
            'field_meta' => [],
        ]));

        $arr = $resource->toArray(request());
        $this->assertSame('Invalid', $arr['status']);
        $this->assertSame('CA Name is required.', $arr['user_message']);
        $this->assertArrayNotHasKey('blocking_errors', $arr);
        $this->assertArrayNotHasKey('collision_codes', $arr);
        $this->assertArrayNotHasKey('review_summary', $arr);
        $this->assertArrayNotHasKey('address', $arr);
        $this->assertArrayNotHasKey('frn', $arr);
        $this->assertArrayNotHasKey('field_meta', $arr);
        $this->assertTrue($arr['can_approve']);
        $this->assertTrue($arr['can_correct']);
    }

    public function test_review_api_payload_only_three_fields(): void
    {
        $resource = new \App\Http\Resources\OcrParsedFirmResource(new \App\Models\OcrParsedFirm([
            'id' => 99,
            'firm_name' => 'ANMOL SETIA & ASSOCIATES',
            'city' => 'ABOHAR',
            'frn' => '024992N',
            'address' => 'HOUSE NO 12',
            'pincode' => '152116',
            'match_status' => 'verified',
            'review_status' => 'pending',
            'source_data' => [
                'raw' => ['ca_name' => "ANMOL SE\u{03A4}\u{0399}\u{0391}"],
                'parsed' => ['ca_name' => 'ANMOL SETIA', 'firm_name' => 'ANMOL SETIA & ASSOCIATES', 'city' => 'ABOHAR'],
                'match_type' => 'EXACT_VERIFIED',
                'validation' => ['ok' => true, 'collision_codes' => [], 'errors' => []],
            ],
        ]));

        $arr = $resource->toArray(request());
        $allowed = [
            'id', 'firm_name', 'ca_name', 'city',
            'raw_firm_name', 'raw_ca_name', 'raw_city',
            'normalized_firm_name', 'normalized_ca_name', 'normalized_city',
            'page_number', 'row_number',
            'validation_status', 'validation_errors',
            'match_type', 'match_status', 'matched_master_id',
            'status', 'user_message',
            'can_approve', 'can_correct', 'can_reject', 'review_status', 'crm_ca_id', 'ca_id',
        ];
        foreach (array_keys($arr) as $key) {
            $this->assertContains($key, $allowed, "Unexpected review key: {$key}");
        }
        $this->assertSame('ANMOL SETIA', $arr['ca_name']);
        $this->assertSame('Exact verified', $arr['match_type']);
        $this->assertSame('Verified', $arr['status']);
        $this->assertSame('Verified', $arr['validation_status']);
        $this->assertTrue($arr['can_approve']);
        foreach (['address', 'pincode', 'frn', 'membership_no', 'partner', 'firm_type', 'state', 'phone', 'field_meta', 'raw_values', 'technical_details'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $arr);
        }
    }

    public function test_urban_estate_and_suraj_nagar_never_ca_name(): void
    {
        $classifier = new OcrEntityClassificationService;
        foreach (['URBAN ESTATE HUDA', 'NEW SURAJ NAGAR', 'LAJPAT NAGAR', 'STREET NO 2'] as $token) {
            $this->assertFalse($classifier->isPerson($token), $token);
            $classified = $classifier->classify($token);
            $this->assertNotSame('ca_name', $classified['crm_field'] ?? null, $token);
        }
    }
}
