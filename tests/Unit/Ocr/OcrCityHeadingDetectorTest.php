<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\OcrCityHeadingDetector;
use App\Services\Ocr\OcrEntityClassificationService;
use App\Services\Ocr\OcrStructureParserService;
use PHPUnit\Framework\TestCase;

class OcrCityHeadingDetectorTest extends TestCase
{
    public function test_adipur_and_ahily_nagar_are_cities_never_ca_names(): void
    {
        $detector = new OcrCityHeadingDetector(new OcrEntityClassificationService);
        $entities = new OcrEntityClassificationService;

        foreach (['ADIPUR', 'AHILY NAGAR', 'AHILYANAGAR', 'AMBALA', 'ABOHAR'] as $city) {
            $hit = $detector->detect($city);
            $this->assertNotNull($hit, $city.' must be a city heading');
            $this->assertFalse($entities->isPerson($city), $city.' must not be PERSON');
        }

        $this->assertNull($detector->detect('BHAVISHA MANOJ TIKYANI'));
        $this->assertNull($detector->detect('ANIRUDDHA DHANANJAY NAGARKAR'));
        $this->assertNull($detector->detect('AMBADAS KISAN KOHK'));
        $this->assertNull($detector->detect('KRISHNA NAGAR'), 'street locality must not be a section city');
        $hit = $detector->detect('ABU ROAD (M)');
        $this->assertNotNull($hit);
        $this->assertSame('ABU ROAD', $hit['city']);
    }

    public function test_abu_road_m_section_city_applies_to_following_firms(): void
    {
        $parsed = (new OcrStructureParserService)->parse(<<<'TXT'
ABU ROAD (M)
GN AGARWAL AND ASSOCIATES
NAVODIT AGARWAL
NEAR POLICE STATION
MANISH CHANDAK & ASSOCIATES
MANISH CHANDAK
RIICO COLONY
TXT);
        $this->assertSame(2, $parsed['firm_count']);
        $this->assertSame('ABU ROAD', $parsed['firms'][0]['city']);
        $this->assertSame('ABU ROAD', $parsed['firms'][1]['city']);
    }

    public function test_city_heading_applies_to_following_firms_in_text_parser(): void
    {
        $text = <<<'TXT'
ADIPUR
BHAVISHA MANOJ TIKYANI & CO
BHAVISHA MANOJ TIKYANI
Shop 1 Main Road Adipur
370205

AHILY NAGAR
A D NAGARKAR & CO
ANIRUDDHA DHANANJAY NAGARKAR
Near Station Ahily Nagar
414001
TXT;

        $parsed = (new OcrStructureParserService)->parse($text);
        $this->assertGreaterThanOrEqual(2, $parsed['firm_count']);

        $byFirm = [];
        foreach ($parsed['firms'] as $firm) {
            $byFirm[mb_strtoupper((string) $firm['firm_name'])] = $firm;
        }

        $this->assertArrayHasKey('BHAVISHA MANOJ TIKYANI & CO', $byFirm);
        $this->assertSame('ADIPUR', mb_strtoupper((string) $byFirm['BHAVISHA MANOJ TIKYANI & CO']['city']));
        $this->assertStringContainsStringIgnoringCase('BHAVISHA', (string) $byFirm['BHAVISHA MANOJ TIKYANI & CO']['ca_name']);
        $this->assertNotSame('ADIPUR', mb_strtoupper((string) $byFirm['BHAVISHA MANOJ TIKYANI & CO']['ca_name']));

        $nagarkarKey = null;
        foreach (array_keys($byFirm) as $key) {
            if (str_contains($key, 'NAGARKAR')) {
                $nagarkarKey = $key;
                break;
            }
        }
        $this->assertNotNull($nagarkarKey);
        $city = mb_strtoupper((string) $byFirm[$nagarkarKey]['city']);
        $this->assertSame('AHILYANAGAR', $city);
        $this->assertStringContainsStringIgnoringCase('ANIRUDDHA', (string) $byFirm[$nagarkarKey]['ca_name']);
        $this->assertFalse(str_contains(mb_strtoupper((string) $byFirm[$nagarkarKey]['ca_name']), 'AHILY'));
    }

    public function test_populated_city_clears_city_is_required_message(): void
    {
        $result = (new \App\Services\Ocr\OcrFieldCollisionService)->detect([
            'firm_name' => 'TEST & ASSOCIATES',
            'ca_name' => 'RAJESH SHARMA',
            'city' => 'ADIPUR',
            'missing_required_fields' => [],
        ]);
        $codes = $result['codes'] ?? ($result['collision_codes'] ?? []);
        $messages = $result['messages'] ?? ($result['errors'] ?? []);
        $this->assertNotContains('MISSING_CITY', $codes);
        foreach ($messages as $m) {
            $this->assertStringNotContainsStringIgnoringCase('city is required', (string) $m);
        }
    }

    public function test_address_lines_never_become_ca_name(): void
    {
        $text = <<<'TXT'
ADIPUR
TEST FIRM & ASSOCIATES
RAJESH KUMAR SHARMA
Shop No 12 Circular Road Near Market
370205
TXT;
        $firm = (new OcrStructureParserService)->parse($text)['firms'][0] ?? null;
        $this->assertNotNull($firm);
        $ca = mb_strtoupper((string) ($firm['ca_name'] ?? ''));
        $this->assertStringNotContainsString('ROAD', $ca);
        $this->assertStringNotContainsString('SHOP', $ca);
        $this->assertStringNotContainsString('MARKET', $ca);
        $this->assertSame('ADIPUR', mb_strtoupper((string) $firm['city']));
    }
}
