<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\OcrEntityClassificationService;
use App\Services\Ocr\OcrStructureParserService;
use PHPUnit\Framework\TestCase;

class OcrEntityClassificationServiceTest extends TestCase
{
    private function classifier(): OcrEntityClassificationService
    {
        return new OcrEntityClassificationService;
    }

    public function test_urban_estate_huda_classified_as_address_not_person(): void
    {
        $c = $this->classifier()->classify('URBAN ESTATE HUDA');
        $this->assertSame(OcrEntityClassificationService::ADDRESS, $c['entity_type']);
        $this->assertSame('address', $c['crm_field']);
        $this->assertTrue($c['invalid_as_person']);
        $this->assertFalse($this->classifier()->isPerson('URBAN ESTATE HUDA'));
    }

    public function test_anaj_mandi_classified_as_address(): void
    {
        $c = $this->classifier()->classify('ANAJ MANDI');
        $this->assertSame(OcrEntityClassificationService::ADDRESS, $c['entity_type']);
        $this->assertFalse($this->classifier()->isPerson('ANAJ MANDI'));
    }

    public function test_new_suraj_nagar_classified_as_address(): void
    {
        $c = $this->classifier()->classify('NEW SURAJ NAGAR');
        $this->assertSame(OcrEntityClassificationService::ADDRESS, $c['entity_type']);
        $this->assertFalse($this->classifier()->isPerson('NEW SURAJ NAGAR'));
    }

    public function test_ayushi_gupta_and_anmol_setia_classified_as_person(): void
    {
        foreach (['AYUSHI GUPTA', 'ANMOL SETIA'] as $name) {
            $c = $this->classifier()->classify($name);
            $this->assertSame(OcrEntityClassificationService::PERSON, $c['entity_type'], $name);
            $this->assertSame('ca_name', $c['crm_field']);
        }
    }

    public function test_anmol_setia_associates_block_does_not_put_address_in_partner(): void
    {
        $raw = <<<'TXT'
ANMOL SETIA & ASSOCIATES
URBAN ESTATE HUDA
ANMOL SETIA
ABOHAR - 562848
TXT;

        $parser = new OcrStructureParserService;
        $result = $parser->parse($raw);
        $this->assertGreaterThanOrEqual(1, $result['firm_count']);
        $firm = $result['firms'][0];
        $this->assertSame('ANMOL SETIA & ASSOCIATES', $firm['firm_name']);
        $partnerNames = array_map(static fn ($m) => $m['ca_name'] ?? '', $firm['members'] ?? []);
        $this->assertNotContains('URBAN ESTATE HUDA', $partnerNames);
        $this->assertSame('ANMOL SETIA', $firm['ca_name'] ?? null);
        $this->assertStringContainsStringIgnoringCase('URBAN ESTATE', (string) $firm['address']);
    }

    public function test_gupta_ayushi_block_does_not_put_anaj_mandi_in_partners(): void
    {
        $raw = <<<'TXT'
GUPTA AYUSHI & ASSOCIATES
AYUSHI GUPTA
1ST FLOOR SCO-4 5507-11/5555
NEW SURAJ NAGAR
ANAJ MANDI
AMBALA CANTT-133001
TXT;

        $parser = new OcrStructureParserService;
        $result = $parser->parse($raw);
        $firm = $result['firms'][0];
        $partnerNames = array_map(static fn ($m) => $m['ca_name'] ?? '', $firm['members'] ?? []);
        $this->assertSame('GUPTA AYUSHI & ASSOCIATES', $firm['firm_name']);
        $this->assertSame('AYUSHI GUPTA', $firm['ca_name'] ?? null);
        $this->assertNotContains('ANAJ MANDI', $partnerNames);
        $this->assertNotContains('NEW SURAJ NAGAR', $partnerNames);
        $this->assertStringContainsStringIgnoringCase('ANAJ MANDI', (string) $firm['address']);
    }

    public function test_map_lines_to_fields_uses_classification_not_line_order(): void
    {
        $mapped = $this->classifier()->mapLinesToFields([
            'GUPTA AYUSHI & ASSOCIATES',
            'AYUSHI GUPTA',
            'ANAJ MANDI',
        ]);
        $this->assertSame('GUPTA AYUSHI & ASSOCIATES', $mapped['firm_name']);
        $this->assertSame('AYUSHI GUPTA', $mapped['ca_name']);
        $this->assertStringContainsString('ANAJ MANDI', (string) $mapped['address']);
        $this->assertCount(0, $mapped['partners']);
    }

    public function test_pin_133001_is_pincode_only_not_membership(): void
    {
        // Bare 6-digit is ambiguous without record context — must not auto-claim as membership.
        $c = $this->classifier()->classify('133001');
        $this->assertSame(OcrEntityClassificationService::UNKNOWN, $c['entity_type']);

        $mapped = $this->classifier()->mapLinesToFields(['AMBALA CANTT-133001']);
        $this->assertSame('133001', $mapped['pincode']);
        $this->assertNull($mapped['membership_no']);
    }

    public function test_ca_surnames_are_not_cities_via_place_substring(): void
    {
        $c = $this->classifier();
        foreach (['ANIRUDDHA DHANANJAY NAGARKAR', 'AMBADAS KISAN KOHAK', 'AMIT RATANLAL POKHARNA'] as $name) {
            $this->assertFalse($c->isCity($name), $name.' must not be city');
            $this->assertTrue($c->isPerson($name), $name.' must be person');
        }
        $this->assertTrue($c->isCity('ADIPUR'));
        $this->assertTrue($c->isCity('AHILYANAGAR'));
        $this->assertFalse($c->isCity('LAJPAT NAGAR'), 'locality must not be city');
        $this->assertTrue($c->isCity('ABU ROAD'));
    }

    public function test_directory_block_keeps_ca_out_of_city_field(): void
    {
        $raw = <<<'TXT'
AHILYANAGAR
A D NAGARKAR & CO
ANIRUDDHA DHANANJAY NAGARKAR
123 MAIN ROAD
AHILYANAGAR-414001
AMIT R POKHARNA AND ASSOCIATES
AMIT RATANLAL POKHARNA
TULJAI NIWAS
AHILYANAGAR-414001
TXT;
        $parser = new OcrStructureParserService;
        $result = $parser->parse($raw);
        $this->assertGreaterThanOrEqual(2, $result['firm_count']);
        $byFirm = [];
        foreach ($result['firms'] as $firm) {
            $byFirm[(string) $firm['firm_name']] = $firm;
        }
        $this->assertArrayHasKey('A D NAGARKAR & CO', $byFirm);
        $this->assertSame('ANIRUDDHA DHANANJAY NAGARKAR', $byFirm['A D NAGARKAR & CO']['ca_name'] ?? null);
        $this->assertNotSame('ANIRUDDHA DHANANJAY NAGARKAR', $byFirm['A D NAGARKAR & CO']['city'] ?? null);
        $this->assertSame('AHILYANAGAR', $byFirm['A D NAGARKAR & CO']['city'] ?? null);

        $this->assertArrayHasKey('AMIT R POKHARNA AND ASSOCIATES', $byFirm);
        $this->assertSame('AMIT RATANLAL POKHARNA', $byFirm['AMIT R POKHARNA AND ASSOCIATES']['ca_name'] ?? null);
        $this->assertNotSame('ANIRUDDHA DHANANJAY NAGARKAR', $byFirm['AMIT R POKHARNA AND ASSOCIATES']['city'] ?? null);
        $this->assertNotSame('TULJAI NIWAS', $byFirm['AMIT R POKHARNA AND ASSOCIATES']['city'] ?? null);
        $this->assertSame('AHILYANAGAR', $byFirm['AMIT R POKHARNA AND ASSOCIATES']['city'] ?? null);
    }

    public function test_city_after_firm_block_is_assigned_to_that_firm(): void
    {
        $raw = <<<'TXT'
AMBADAS K KOHAK & ASSOCIATES
AMBADAS KISAN KOHAK
TULJAI NIWAS
NEAR SAMRUDDHI CLASSES
ADIPUR
VIJAY G BELLANI & ASSOCIATES
VIJAY GOPE BELLANI
82 WARD 6A
ADIPUR
TXT;
        $parser = new OcrStructureParserService;
        $result = $parser->parse($raw);
        $byFirm = [];
        foreach ($result['firms'] as $firm) {
            $byFirm[(string) $firm['firm_name']] = $firm;
        }
        $this->assertSame('ADIPUR', $byFirm['AMBADAS K KOHAK & ASSOCIATES']['city'] ?? null);
        $this->assertSame('AMBADAS KISAN KOHAK', $byFirm['AMBADAS K KOHAK & ASSOCIATES']['ca_name'] ?? null);
        $this->assertSame('ADIPUR', $byFirm['VIJAY G BELLANI & ASSOCIATES']['city'] ?? null);
        $this->assertSame('VIJAY GOPE BELLANI', $byFirm['VIJAY G BELLANI & ASSOCIATES']['ca_name'] ?? null);
    }

    public function test_golden_northprop_fixture_exact_output(): void
    {
        $path = __DIR__.'/../../Fixtures/ocr/golden_northprop_samples.json';
        $this->assertFileExists($path);
        $fixture = json_decode((string) file_get_contents($path), true);
        $parser = new OcrStructureParserService;

        foreach ($fixture['cases'] as $case) {
            $firm = $parser->parse($case['input'])['firms'][0];
            $exp = $case['expected'];
            $this->assertSame($exp['firm_name'], $firm['firm_name'], $case['name'].' firm_name');
            if (isset($exp['ca_name'])) {
                $this->assertSame($exp['ca_name'], $firm['ca_name'] ?? null, $case['name'].' ca_name');
            }
            $partners = array_column($firm['members'] ?? [], 'ca_name');
            foreach ($exp['partners'] as $p) {
                $this->assertContains($p, $partners, $case['name'].' missing partner '.$p);
            }
            foreach ($exp['partners_must_not_contain'] as $bad) {
                $this->assertNotContains($bad, $partners, $case['name'].' bad partner '.$bad);
            }
            foreach ($exp['address_contains'] as $frag) {
                $this->assertStringContainsStringIgnoringCase($frag, (string) $firm['address'], $case['name'].' address');
            }
            if (isset($exp['pincode'])) {
                $this->assertSame($exp['pincode'], $firm['pincode'], $case['name'].' pincode');
            }
            if (isset($exp['membership_no'])) {
                $this->assertSame($exp['membership_no'], $firm['membership_no'] ?? null, $case['name'].' membership_no');
            }
        }

        foreach ($fixture['classifications'] as $text => $type) {
            $entity = $type === 'PERSON' ? OcrEntityClassificationService::PERSON : $type;
            $this->assertSame($entity, $this->classifier()->classify($text)['entity_type'], $text);
        }
    }
}
