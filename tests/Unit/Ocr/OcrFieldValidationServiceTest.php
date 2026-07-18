<?php

namespace Tests\Unit\Ocr;

use App\Services\Mapping\DataNormalizationService;
use App\Services\Ocr\OcrDocumentAiTableParser;
use App\Services\Ocr\OcrFieldValidationService;
use Tests\TestCase;

class OcrFieldValidationServiceTest extends TestCase
{
    private function validator(): OcrFieldValidationService
    {
        return new OcrFieldValidationService(new DataNormalizationService);
    }

    public function test_valid_mobile_and_email_pass(): void
    {
        $result = $this->validator()->validateFirm([
            'firm_name' => 'Shah & Associates',
            'phone' => '9876543210',
            'email' => 'amit@shah.test',
            'field_meta' => [
                'firm_name' => ['confidence' => 0.99],
                'phone' => ['confidence' => 1.0],
                'email' => ['confidence' => 0.98],
            ],
            'overall_confidence' => 0.97,
        ]);

        $this->assertTrue($result['fields']['phone']['valid']);
        $this->assertTrue($result['fields']['email']['valid']);
        $this->assertSame('9876543210', $result['fields']['phone']['normalized']);
    }

    public function test_invalid_mobile_fails_without_guessing(): void
    {
        $result = $this->validator()->validateFirm([
            'firm_name' => 'Shah & Associates',
            'phone' => '12345',
            'field_meta' => ['firm_name' => ['confidence' => 0.99], 'phone' => ['confidence' => 0.4]],
            'overall_confidence' => 0.7,
        ]);

        $this->assertFalse($result['fields']['phone']['valid']);
        $this->assertFalse($result['auto_apply_ok']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_firm_name_with_phone_is_rejected(): void
    {
        $result = $this->validator()->validateFirm([
            'firm_name' => 'Call 9876543210 Now',
            'field_meta' => ['firm_name' => ['confidence' => 0.99]],
            'overall_confidence' => 0.99,
        ]);

        $this->assertFalse($result['fields']['firm_name']['valid']);
        $this->assertFalse($result['auto_apply_ok']);
    }

    public function test_low_confidence_blocks_auto_apply(): void
    {
        $result = $this->validator()->validateFirm([
            'firm_name' => 'Shah & Associates',
            'field_meta' => ['firm_name' => ['confidence' => 0.4]],
            'overall_confidence' => 0.4,
        ]);

        $this->assertFalse($result['auto_apply_ok']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_document_ai_table_parser_maps_cells_without_mixing(): void
    {
        $parser = new OcrDocumentAiTableParser;
        $parsed = $parser->parseTables([[
            'page_number' => 1,
            'table_index' => 0,
            'header_rows' => [[
                ['text' => 'Firm Name', 'confidence' => 0.99, 'bounding_box' => []],
                ['text' => 'CA Name', 'confidence' => 0.98, 'bounding_box' => []],
                ['text' => 'Mobile', 'confidence' => 0.97, 'bounding_box' => []],
                ['text' => 'State', 'confidence' => 0.96, 'bounding_box' => []],
            ]],
            'body_rows' => [[
                ['text' => 'Shah & Associates', 'confidence' => 0.99, 'bounding_box' => [['x' => 0.1, 'y' => 0.2]]],
                ['text' => 'Amit Shah', 'confidence' => 0.95, 'bounding_box' => []],
                ['text' => '9876543210', 'confidence' => 1.0, 'bounding_box' => []],
                ['text' => 'Delhi', 'confidence' => 0.94, 'bounding_box' => []],
            ]],
        ]]);

        $this->assertNotNull($parsed);
        $this->assertSame('document_ai_tables', $parsed['parse_mode']);
        $this->assertCount(1, $parsed['firms']);
        $firm = $parsed['firms'][0];
        $this->assertSame('Shah & Associates', $firm['firm_name']);
        $this->assertSame('9876543210', $firm['phone']);
        $this->assertSame('Delhi', $firm['state']);
        $this->assertSame('Amit Shah', $firm['members'][0]['ca_name']);
        $this->assertSame('document_ai_table_cell', $firm['field_meta']['firm_name']['extraction']);
    }

    public function test_table_parser_does_not_guess_without_headers(): void
    {
        $parser = new OcrDocumentAiTableParser;
        $parsed = $parser->parseTables([[
            'page_number' => 1,
            'header_rows' => [],
            'body_rows' => [[
                ['text' => 'Something', 'confidence' => 0.9, 'bounding_box' => []],
            ]],
        ]]);

        $this->assertNull($parsed);
    }
}
