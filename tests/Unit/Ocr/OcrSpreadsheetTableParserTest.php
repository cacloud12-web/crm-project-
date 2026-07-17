<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\OcrSpreadsheetTableParser;
use PHPUnit\Framework\TestCase;

class OcrSpreadsheetTableParserTest extends TestCase
{
    public function test_detects_spreadsheet_headers(): void
    {
        $parser = new OcrSpreadsheetTableParser;
        $this->assertTrue($parser->looksLikeSpreadsheet("date\nca name\nfirm name\nmobile number\n"));
        $this->assertFalse($parser->looksLikeSpreadsheet("ABHANPUR\nAGRAWAL & ASSOCIATES\nSHOP NO 1"));
    }

    public function test_every_serial_date_anchor_becomes_a_firm(): void
    {
        $raw = <<<'TXT'
date
ca name
firm name
mobile number
1
01-01-2026 Alpha & Co
9000000001
2
01-01-2026
Beta Associates
9000000002
3
01-01-2026 Gamma & Company
90000 00003
TXT;

        $parser = new OcrSpreadsheetTableParser;
        $result = $parser->parse($raw);

        $this->assertSame(3, $result['rows_detected']);
        $this->assertCount(3, $result['firms']);
        $this->assertSame('9000000003', $result['firms'][2]['phone']);
    }
}
