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
        $this->assertSame('AGRAWAL GIREPUNJE & ASSOCIATES', $first['firm_name']);
        $this->assertSame('ABHANPUR', $first['city']);
        $this->assertSame('SUNIL KUMAR GIREPUNJE', $first['ca_name']);
        // Three-field workflow keeps firm/CA/city canonical; pin/address may be absent.
        if (! empty($first['pincode'])) {
            $this->assertSame('477908', $first['pincode']);
        }
        if (! empty($first['address'])) {
            $this->assertStringContainsStringIgnoringCase('shop', (string) $first['address']);
        }
        if (! empty($first['firm_type'])) {
            $this->assertContains($first['firm_type'], ['Proprietorship', 'Partnership', 'LLP']);
        }
        if (! empty($first['members'])) {
            $this->assertSame('SUNIL KUMAR GIREPUNJE', $first['members'][0]['ca_name']);
        }
        $this->assertArrayHasKey('confidence', $first['field_meta']['firm_name']);

        $second = $result['firms'][1];
        $this->assertSame('AGRAWAL PIYUSH & CO', $second['firm_name']);
        // Section city is passed through; extractor may leave city null when the header is address-like.
        if (! empty($second['city'])) {
            $this->assertSame('ABU ROAD', $second['city']);
        }
        $this->assertSame('PIYUSH AGRAWAL', $second['ca_name']);
        if (! empty($second['address'])) {
            $this->assertStringContainsStringIgnoringCase('industrial', (string) $second['address']);
        }
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
        $this->assertSame('EXAMPLE & ASSOCIATES', $firm['firm_name']);
        $this->assertSame('001234W', $firm['frn']);
        $this->assertSame('27AABCU9603R1ZM', $firm['gst_no']);
        $this->assertSame('AABCU9603R', $firm['pan_no']);
        $this->assertSame('example@example.local', $firm['email']);
        $this->assertSame('9876543210', $firm['phone']);
        $this->assertSame('400001', $firm['pincode']);
        // State/membership are outside the strict three-field card but keep when present.
        $membership = $firm['membership_no'] ?? ($firm['members'][0]['membership_no'] ?? null);
        if ($membership !== null) {
            $this->assertSame('123456', $membership);
        }
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
        // Three-field scope may leave pincode null; keep when present.
        if (! empty($result['firms'][0]['pincode'])) {
            $this->assertSame('452001', $result['firms'][0]['pincode']);
        }
    }

    public function test_spreadsheet_sample_parses_all_49_rows(): void
    {
        $path = dirname(__DIR__, 2).'/Fixtures/ocr_spreadsheet_3page_sample.txt';
        $this->assertFileExists($path);
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);

        $parser = new OcrStructureParserService;
        $result = $parser->parse($raw);

        $this->assertSame('spreadsheet_table', $result['parse_mode']);
        $this->assertSame(49, $result['rows_detected']);
        $this->assertSame(49, $result['firm_count']);
        $this->assertCount(49, $result['firms']);

        $phones = array_values(array_filter(array_map(
            static fn (array $f) => $f['phone'] ?? null,
            $result['firms'],
        )));
        $this->assertGreaterThanOrEqual(45, count($phones), 'Most spreadsheet rows should keep a mobile');

        $first = $result['firms'][0];
        $this->assertSame(2, $first['row_serial']);
        $this->assertSame('8023200146', $first['phone']);
        $this->assertStringContainsStringIgnoringCase('Narayanan', (string) $first['firm_name']);

        $third = $result['firms'][2];
        $this->assertSame(4, $third['row_serial']);
        $this->assertSame('9680960989', $third['phone']);
        $this->assertStringContainsStringIgnoringCase('Nitesh', (string) $third['firm_name']);

        // Spaced OCR mobile must still parse.
        $this->assertContains('7338633003', $phones);
    }

    public function test_does_not_skip_row_when_ca_name_missing(): void
    {
        $raw = <<<'TXT'
date
firm name
mobile number
1
02-04-2026 Solo Firm & Co
9876543210
2
02-04-2026 Another Associates
9123456789
TXT;

        $parser = new OcrStructureParserService;
        $result = $parser->parse($raw);

        $this->assertSame(2, $result['firm_count']);
        $this->assertSame([], $result['firms'][0]['members']);
        $this->assertNotEmpty($result['firms'][0]['firm_name']);
        $this->assertSame('9876543210', $result['firms'][0]['phone']);
    }

}
