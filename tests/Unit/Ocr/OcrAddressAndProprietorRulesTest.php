<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\OcrDirectoryRecordParser;
use App\Services\Ocr\OcrEntityClassificationService;
use App\Services\Ocr\OcrFirmRoleResolverService;
use App\Services\Ocr\OcrStructureParserService;
use PHPUnit\Framework\TestCase;

class OcrAddressAndProprietorRulesTest extends TestCase
{
    public function test_roads_and_hospitals_are_address_not_person(): void
    {
        $c = new OcrEntityClassificationService;
        foreach ([
            'CIRCULAR ROAD', 'PATEL ROAD', 'NICHOLSON ROAD', 'BACKSIDE DHAYAL HOSPITAL',
            'POST OFFICE BARA GAON', 'JAIL LAND', 'TANGA STAND', 'IDGAH ROAD',
            'LAJPAT NAGAR', 'H NO -77A', 'NEAR PUNJAB AND SIND BANK',
        ] as $line) {
            $this->assertTrue($c->isAddress($line), $line.' must be address');
            $this->assertFalse($c->isPerson($line), $line.' must not be person');
        }
    }

    public function test_proprietor_never_gets_address_as_partner(): void
    {
        $raw = <<<'TXT'
DINESH PUJARA & ASSOCIATES
PUJARA DINESH
LAJPAT NAGAR
BACKSIDE DHAYAL HOSPITAL
TXT;
        $firm = (new OcrStructureParserService)->parse($raw)['firms'][0];
        $this->assertSame('PUJARA DINESH', $firm['ca_name']);
        $this->assertSame('Proprietor', $firm['ca_role']);
        $this->assertSame('Proprietorship', $firm['firm_type']);
        $this->assertSame([], $firm['members']);
        $this->assertStringContainsStringIgnoringCase('LAJPAT NAGAR', (string) $firm['address']);
        $this->assertStringContainsStringIgnoringCase('BACKSIDE DHAYAL HOSPITAL', (string) $firm['address']);
    }

    public function test_partnership_keeps_two_real_persons_only(): void
    {
        $raw = <<<'TXT'
AKASH GOYAL & ASSOCIATES
AKASH GOYAL
SANJEEV GOYAL
WARD NO 4 NEAR HIND HOSPITAL
TXT;
        $firm = (new OcrStructureParserService)->parse($raw)['firms'][0];
        $this->assertSame('Partnership', $firm['firm_type']);
        $names = array_column($firm['members'], 'ca_name');
        $this->assertContains('AKASH GOYAL', $names);
        $this->assertContains('SANJEEV GOYAL', $names);
        $this->assertNotContains('WARD NO 4 NEAR HIND HOSPITAL', $names);
        $this->assertStringContainsStringIgnoringCase('WARD NO 4', (string) $firm['address']);
    }

    public function test_role_resolver_rejects_address_noise(): void
    {
        $roles = new OcrFirmRoleResolverService;
        $result = $roles->resolve('SHALU & CO', [
            ['name' => 'SHALU VERMA'],
            ['name' => 'CIRCULAR ROAD'],
        ], false);
        $this->assertSame('Proprietor', $result['ca_role']);
        $this->assertSame([], $result['members']);
        $this->assertContains('CIRCULAR ROAD', $result['rejected_as_address']);
    }

    public function test_layout_block_keeps_full_address_for_sole_ca(): void
    {
        $tokens = [
            ['text' => 'SHALU & CO', 'x_center' => 0.2, 'x_min' => 0.1, 'x_max' => 0.3, 'y_center' => 0.1, 'y_min' => 0.09, 'y_max' => 0.11],
            ['text' => 'SHALU VERMA', 'x_center' => 0.2, 'x_min' => 0.1, 'x_max' => 0.3, 'y_center' => 0.13, 'y_min' => 0.12, 'y_max' => 0.14],
            ['text' => 'STREET NO 2, NEAR MUNJAL MOBILES', 'x_center' => 0.22, 'x_min' => 0.1, 'x_max' => 0.34, 'y_center' => 0.16, 'y_min' => 0.15, 'y_max' => 0.17],
            ['text' => 'CIRCULAR ROAD', 'x_center' => 0.2, 'x_min' => 0.1, 'x_max' => 0.3, 'y_center' => 0.19, 'y_min' => 0.18, 'y_max' => 0.20],
        ];
        $firm = (new OcrDirectoryRecordParser)->parseBlock($tokens, ['section_city' => 'ABOHAR']);
        $this->assertNotNull($firm);
        $this->assertSame([], $firm['members']);
        $this->assertSame('Proprietor', $firm['ca_role']);
        $this->assertStringContainsStringIgnoringCase('CIRCULAR ROAD', (string) $firm['address']);
        $this->assertStringContainsStringIgnoringCase('STREET NO 2', (string) $firm['address']);
    }
}
