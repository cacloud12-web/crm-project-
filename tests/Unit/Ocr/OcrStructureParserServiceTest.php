<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\OcrStructureParserService;
use PHPUnit\Framework\TestCase;

class OcrStructureParserServiceTest extends TestCase
{
    public function test_parses_directory_style_ocr_into_multiple_firms(): void
    {
        $raw = <<<'TXT'
Preview
File
Edit
View
ABHANPUR
AGRAWAL GIREPUNJE & ASSOCIATES
SIO SUNIL KUMAR GIREPUNJE
SHOP NO 1ST FLOOR
477908
ABU ROAD
AGRAWAL PIYUSH & CO
PIYUSH AGRAWAL
INDUSTRIAL AREA
TXT;

        $parser = new OcrStructureParserService;
        $result = $parser->parse($raw);

        $this->assertSame(2, $result['firm_count']);
        $this->assertCount(2, $result['firms']);
        $this->assertGreaterThan(0, $result['candidate_firm_count']);
        $this->assertNotEmpty($result['strategy']);

        $first = $result['firms'][0];
        $this->assertSame('Agrawal Girepunje & Associates', $first['firm_name']);
        $this->assertSame('Abhanpur', $first['city']);
        $this->assertSame('477908', $first['pincode']);
        $this->assertStringContainsStringIgnoringCase('shop', (string) $first['address']);
        $this->assertSame('Partnership', $first['firm_type']);
        $this->assertNotEmpty($first['members']);
        $this->assertSame('Sunil Kumar Girepunje', $first['members'][0]['ca_name']);
        $this->assertArrayHasKey('confidence', $first['field_meta']['firm_name']);
        $this->assertArrayHasKey('source_line', $first['field_meta']['firm_name']);

        $second = $result['firms'][1];
        $this->assertSame('Agrawal Piyush & Co', $second['firm_name']);
        $this->assertSame('Abu Road', $second['city']);
        $this->assertNotEmpty($second['members']);
        $this->assertSame('Piyush Agrawal', $second['members'][0]['ca_name']);
        $this->assertStringContainsStringIgnoringCase('industrial', (string) $second['address']);
    }

    public function test_parses_noisy_multicolumn_directory_fixture_into_multiple_firms(): void
    {
        $raw = file_get_contents(dirname(__DIR__, 2).'/Fixtures/Ocr/directory_multicolumn_sample.txt');
        $this->assertNotFalse($raw);
        $this->assertGreaterThan(200, strlen($raw));

        $parser = new OcrStructureParserService;
        $result = $parser->parse($raw);

        $this->assertGreaterThan(0, $result['firm_count'], 'Expected firms from noisy multi-column OCR text');
        $this->assertGreaterThanOrEqual(8, $result['firm_count']);

        $hasAssociates = false;
        foreach ($result['firms'] as $firm) {
            $name = mb_strtolower((string) $firm['firm_name']);
            if (str_contains($name, 'girepunje') || str_contains($name, 'associates')) {
                $hasAssociates = true;
            }
            $this->assertNotSame('abhanpur', $name);
            $this->assertNotSame('abu road', $name);
            $this->assertNotSame('meerut', $name);
        }
        $this->assertTrue($hasAssociates, 'Expected at least one associates-style firm name');

        $withPin = array_filter($result['firms'], static fn ($firm) => ! empty($firm['pincode']));
        $this->assertNotEmpty($withPin, 'Expected at least one PIN code extraction');
    }

    public function test_layout_paragraphs_are_ordered_by_columns_when_coordinates_exist(): void
    {
        $layout = [
            'pages' => [[
                'page_number' => 1,
                'paragraphs' => [
                    ['text' => 'LEFT CITY', 'x' => 0.10, 'y' => 0.10],
                    ['text' => 'RIGHT FIRM & ASSOCIATES', 'x' => 0.70, 'y' => 0.10],
                    ['text' => 'LEFT FIRM & CO', 'x' => 0.12, 'y' => 0.20],
                    ['text' => 'RIGHT PERSON NAME', 'x' => 0.72, 'y' => 0.20],
                ],
            ]],
        ];

        $parser = new OcrStructureParserService;
        $result = $parser->parse('', $layout);

        $this->assertSame('layout_aware', $result['strategy']);
        $this->assertGreaterThanOrEqual(2, $result['firm_count']);
        $names = array_map(static fn ($n) => mb_strtolower((string) $n), array_column($result['firms'], 'firm_name'));
        $this->assertTrue((bool) array_filter($names, fn ($n) => str_contains($n, 'left firm')));
        $this->assertTrue((bool) array_filter($names, fn ($n) => str_contains($n, 'right firm')));
    }

    public function test_detects_gst_pan_frn_email_and_membership(): void
    {
        $raw = <<<'TXT'
EXAMPLE & ASSOCIATES
CA EXAMPLE NAME Membership No 123456
FRN: 001234W
GST: 27AABCU9603R1ZM
PAN: AABCU9603R
example@example.local
9876543210
Mumbai
Maharashtra
400001
TXT;

        $parser = new OcrStructureParserService;
        $result = $parser->parse($raw);
        $this->assertGreaterThanOrEqual(1, $result['firm_count']);

        $firm = $result['firms'][0];
        $this->assertSame('Example & Associates', $firm['firm_name']);
        $this->assertSame('001234W', $firm['frn']);
        $this->assertSame('27AABCU9603R1ZM', $firm['gst_no']);
        $this->assertSame('AABCU9603R', $firm['pan_no']);
        $this->assertSame('example@example.local', $firm['email']);
        $this->assertSame('9876543210', $firm['phone']);
        $this->assertSame('400001', $firm['pincode']);
        $this->assertSame('Maharashtra', $firm['state']);
        $this->assertSame('123456', $firm['members'][0]['membership_no'] ?? null);
    }

    public function test_ignores_browser_noise_and_blank_lines(): void
    {
        $parser = new OcrStructureParserService;
        $result = $parser->parse("Tools\n\nWindow\n\nHelp\n\n");

        $this->assertSame(0, $result['firm_count']);
        $this->assertSame([], $result['firms']);
    }

    public function test_repairs_split_associates_ocr_wrap(): void
    {
        $raw = "INDORE\nGIN AGARWAL AND\nOCIATES\nNEAR POLICE STATION\n452001\n";
        $parser = new OcrStructureParserService;
        $result = $parser->parse($raw);

        $this->assertGreaterThanOrEqual(1, $result['firm_count']);
        $this->assertStringContainsStringIgnoringCase('associates', (string) $result['firms'][0]['firm_name']);
        $this->assertSame('452001', $result['firms'][0]['pincode']);
    }
}
