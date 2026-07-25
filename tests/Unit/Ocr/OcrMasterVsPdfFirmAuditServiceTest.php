<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\OcrMasterVsPdfFirmAuditService;
use PHPUnit\Framework\TestCase;

class OcrMasterVsPdfFirmAuditServiceTest extends TestCase
{
    public function test_normalize_firm_name_trims_uppercases_strips_punctuation(): void
    {
        $svc = new OcrMasterVsPdfFirmAuditService;

        $this->assertSame(
            'ABC AND CO',
            $svc->normalizeFirmName('  abc, and  co.  ')
        );
        $this->assertSame(
            'MANISH CHANDAK AND ASSOCIATES',
            $svc->normalizeFirmName('Manish Chandak & Associates')
        );
        $this->assertSame(
            $svc->normalizeFirmName('A & CO'),
            $svc->normalizeFirmName('A AND CO')
        );
        $this->assertNull($svc->normalizeFirmName('   '));
        $this->assertNull($svc->normalizeFirmName(null));
    }

    public function test_normalize_collapses_extra_spaces(): void
    {
        $svc = new OcrMasterVsPdfFirmAuditService;
        $this->assertSame('FOO BAR LLP', $svc->normalizeFirmName("Foo\t  Bar   LLP"));
    }
}
