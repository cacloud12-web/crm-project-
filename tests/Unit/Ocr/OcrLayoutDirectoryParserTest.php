<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\OcrLayoutDirectoryParser;
use PHPUnit\Framework\TestCase;

class OcrLayoutDirectoryParserTest extends TestCase
{
    public function test_layout_directory_golden_page_two_columns(): void
    {
        $path = __DIR__.'/../../Fixtures/ocr/golden_layout_directory_page.json';
        $fixture = json_decode((string) file_get_contents($path), true);
        $parser = new OcrLayoutDirectoryParser;
        $this->assertTrue($parser->canParse($fixture));

        $parsed = $parser->parse($fixture);
        $this->assertNotNull($parsed);
        $this->assertSame($fixture['expected']['firm_count'], $parsed['firm_count']);

        foreach ($fixture['expected']['firms'] as $i => $exp) {
            $firm = $parsed['firms'][$i];
            $this->assertSame($exp['firm_name'], $firm['firm_name'], 'firm '.$i);
            $this->assertSame($exp['ca_name'], $firm['ca_name'], 'ca '.$i);
            if (! empty($exp['partners_empty'])) {
                $this->assertSame([], $firm['members'], 'partners must be empty for sole proprietor');
            }
            foreach ($exp['partners_must_not_contain'] as $bad) {
                $partnerNames = array_column($firm['members'] ?? [], 'ca_name');
                $this->assertNotContains($bad, $partnerNames);
                $this->assertNotSame($bad, $firm['ca_name'] ?? null);
            }
            foreach ($exp['address_contains'] as $frag) {
                $this->assertStringContainsStringIgnoringCase($frag, (string) $firm['address']);
            }
            if (isset($exp['membership_no'])) {
                $this->assertSame($exp['membership_no'], $firm['membership_no'], 'membership '.$i);
            }
            if (isset($exp['city'])) {
                $this->assertSame($exp['city'], $firm['city']);
            }
        }

        $firmNames = array_column($parsed['firms'], 'firm_name');
        foreach ($fixture['expected']['section_headings_not_firms'] as $heading) {
            $this->assertNotContains($heading, $firmNames, $heading.' must not become a firm');
        }
    }

    public function test_northprop_three_column_page_isolates_firms(): void
    {
        $path = __DIR__.'/../../Fixtures/ocr/golden_northprop_page1_3col.json';
        $fixture = json_decode((string) file_get_contents($path), true);
        $parser = new OcrLayoutDirectoryParser;
        $parsed = $parser->parse($fixture);
        $this->assertNotNull($parsed);
        $this->assertSame($fixture['expected']['firm_count'], $parsed['firm_count']);

        foreach ($fixture['expected']['firms'] as $i => $exp) {
            $firm = $parsed['firms'][$i];
            $this->assertSame($exp['firm_name'], $firm['firm_name'], 'firm '.$i);
            if (isset($exp['ca_name'])) {
                $this->assertSame($exp['ca_name'], $firm['ca_name'], 'ca '.$i);
            }
            if (! empty($exp['partners_empty'])) {
                $this->assertSame([], $firm['members'], 'partners '.$i);
            }
            foreach ($exp['address_contains'] ?? [] as $frag) {
                $this->assertStringContainsStringIgnoringCase($frag, (string) $firm['address'], 'addr '.$i);
            }
            foreach ($exp['address_must_not_contain'] ?? [] as $bad) {
                $this->assertStringNotContainsStringIgnoringCase($bad, (string) $firm['address'], 'addr-clean '.$i);
            }
            if (isset($exp['membership_no'])) {
                $this->assertSame($exp['membership_no'], $firm['membership_no'], 'mem '.$i);
            }
            if (isset($exp['frn'])) {
                $this->assertSame($exp['frn'], $firm['frn'], 'frn '.$i);
            }
            if (isset($exp['pincode'])) {
                $this->assertSame($exp['pincode'], $firm['pincode'], 'pin '.$i);
            }
        }
    }
}
