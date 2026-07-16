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

    public function test_detects_gst_pan_frn_email_and_membership(): void
    {
        $raw = <<<'TXT'
SHAH & ASSOCIATES
CA AMIT SHAH Membership No 123456
FRN: 001234W
GST: 27AABCU9603R1ZM
PAN: AABCU9603R
amit@shahca.com
9876543210
Mumbai
Maharashtra
400001
TXT;

        $parser = new OcrStructureParserService;
        $result = $parser->parse($raw);
        $this->assertGreaterThanOrEqual(1, $result['firm_count']);

        $firm = $result['firms'][0];
        $this->assertSame('Shah & Associates', $firm['firm_name']);
        $this->assertSame('001234W', $firm['frn']);
        $this->assertSame('27AABCU9603R1ZM', $firm['gst_no']);
        $this->assertSame('AABCU9603R', $firm['pan_no']);
        $this->assertSame('amit@shahca.com', $firm['email']);
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
}
