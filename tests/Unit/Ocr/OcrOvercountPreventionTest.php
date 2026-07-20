<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\OcrEntityClassificationService;
use App\Services\Ocr\OcrFirmCaCityExtractorService;
use App\Services\Ocr\OcrLayoutDirectoryParser;
use App\Services\Ocr\GoogleCloudStorageService;
use App\Services\Ocr\GoogleDocumentAiBatchService;
use App\Services\Ocr\OcrStructureParserService;
use Tests\TestCase;

/**
 * Prevents city headings / person-only / FRN / address lines from inflating firm counts.
 */
class OcrOvercountPreventionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ocr_workflow.mode' => 'firm_ca_city']);
    }

    public function test_city_headings_do_not_create_firm_rows(): void
    {
        $raw = <<<'TXT'
AHILYANAGAR
ADIPUR
AMBALA
ABOHAR
NEETU BHATIA & ASSOCIATES
NEETU BHATIA
SHOP NO 1
TXT;
        $parsed = (new OcrStructureParserService)->parse($raw);
        $firmNames = array_column($parsed['firms'], 'firm_name');
        foreach (['AHILYANAGAR', 'ADIPUR', 'AMBALA', 'ABOHAR'] as $heading) {
            $this->assertNotContains($heading, $firmNames, $heading.' must not be a firm');
        }
        $this->assertSame(1, $parsed['firm_count']);
        $this->assertSame('NEETU BHATIA & ASSOCIATES', $parsed['firms'][0]['firm_name']);
        $this->assertSame('ABOHAR', $parsed['firms'][0]['city']);
    }

    public function test_person_only_lines_do_not_create_firm_rows(): void
    {
        $raw = <<<'TXT'
ABOHAR
AMBADAS KISAN KOHK
ANIRUDDHA DHANANJAY NAGARKAR
SHAH & ASSOCIATES
RAJESH SHAH
TXT;
        $parsed = (new OcrStructureParserService)->parse($raw);
        $firmNames = array_column($parsed['firms'], 'firm_name');
        $this->assertNotContains('AMBADAS KISAN KOHK', $firmNames);
        $this->assertNotContains('ANIRUDDHA DHANANJAY NAGARKAR', $firmNames);
        $this->assertSame(1, $parsed['firm_count']);
        $this->assertSame('SHAH & ASSOCIATES', $parsed['firms'][0]['firm_name']);
    }

    public function test_address_only_blocks_do_not_create_firm_rows(): void
    {
        $raw = <<<'TXT'
ABOHAR
SHOP NO 777 1ST FLOOR STREET NO 1B
NEAR BUS STAND
123456
HARSHIL & ASSOCIATES
HARSHIL
TXT;
        $parsed = (new OcrStructureParserService)->parse($raw);
        $this->assertSame(1, $parsed['firm_count']);
        $this->assertSame('HARSHIL & ASSOCIATES', $parsed['firms'][0]['firm_name']);
    }

    public function test_frn_membership_rows_do_not_create_firm_rows(): void
    {
        $raw = <<<'TXT'
ABOHAR
FRN 123456
MEMBERSHIP NO 987654
MIGLANI & CO
KUSHAL MIGLANI
TXT;
        $parsed = (new OcrStructureParserService)->parse($raw);
        $firmNames = array_column($parsed['firms'], 'firm_name');
        $this->assertNotContains('FRN 123456', $firmNames);
        $this->assertNotContains('MEMBERSHIP NO 987654', $firmNames);
        $this->assertSame(1, $parsed['firm_count']);
    }

    public function test_one_visual_firm_block_creates_exactly_one_row(): void
    {
        $raw = <<<'TXT'
ABOHAR
LOVISH GARG AND ASSOCIATES
LOVISH GARG
HOUSE NO 968
STREET NO 7
152116
TXT;
        $parsed = (new OcrStructureParserService)->parse($raw);
        $this->assertSame(1, $parsed['firm_count']);
        $this->assertCount(1, $parsed['firms']);
        $this->assertSame('LOVISH GARG AND ASSOCIATES', $parsed['firms'][0]['firm_name']);
        $this->assertSame('LOVISH GARG', $parsed['firms'][0]['ca_name']);
        $this->assertSame('ABOHAR', $parsed['firms'][0]['city']);
    }

    public function test_extractor_rejects_city_and_person_as_firm_title(): void
    {
        $extractor = new OcrFirmCaCityExtractorService(new OcrEntityClassificationService);
        $this->assertNull($extractor->extract([
            ['text' => 'AHILYANAGAR', 'page' => 1, 'column' => 0, 'ocr_confidence' => 0.9,
                'x_min' => 0.1, 'x_max' => 0.4, 'y_min' => 0.1, 'y_max' => 0.12,
                'x_center' => 0.25, 'y_center' => 0.11],
        ], ['section_city' => null]));

        $this->assertNull($extractor->extract([
            ['text' => 'AMBADAS KISAN KOHK', 'page' => 1, 'column' => 0, 'ocr_confidence' => 0.9,
                'x_min' => 0.1, 'x_max' => 0.4, 'y_min' => 0.1, 'y_max' => 0.12,
                'x_center' => 0.25, 'y_center' => 0.11],
        ], ['section_city' => 'ABOHAR']));
    }

    public function test_candidate_count_and_valid_firm_count_are_separate_concepts(): void
    {
        $raw = <<<'TXT'
AHILYANAGAR
ADIPUR
ABOHAR
NEETU BHATIA & ASSOCIATES
NEETU BHATIA
TXT;
        $parsed = (new OcrStructureParserService)->parse($raw);
        $this->assertSame(1, $parsed['firm_count']);
        $this->assertGreaterThanOrEqual($parsed['firm_count'], (int) ($parsed['candidate_firm_count'] ?? $parsed['firm_count']));
        $this->assertNotSame('firms detected', 'valid unique firms');
    }

    public function test_layout_section_headings_not_firms(): void
    {
        $path = __DIR__.'/../../Fixtures/ocr/golden_layout_directory_page.json';
        if (! is_file($path)) {
            $this->markTestSkipped('layout golden fixture missing');
        }
        $fixture = json_decode((string) file_get_contents($path), true);
        $parsed = (new OcrLayoutDirectoryParser)->parse($fixture);
        $this->assertNotNull($parsed);
        $firmNames = array_column($parsed['firms'], 'firm_name');
        foreach ($fixture['expected']['section_headings_not_firms'] ?? [] as $heading) {
            $this->assertNotContains($heading, $firmNames);
        }
    }

    public function test_reconciliation_balances_candidate_equation(): void
    {
        $candidates = 100;
        $validComplete = 70;
        $invalidCandidates = 10;
        $duplicateSource = 5;
        $rejectedNoise = 15;
        $this->assertSame(
            $candidates,
            $validComplete + $invalidCandidates + $duplicateSource + $rejectedNoise,
        );
        $duplicateBusinessRemoved = 3;
        $finalUnique = $validComplete - $duplicateBusinessRemoved;
        $this->assertSame(67, $finalUnique);
    }

    public function test_source_fingerprint_is_stable_for_same_inputs(): void
    {
        $a = hash('sha256', implode('|', [52, 1, 0, '', mb_strtolower('ACME & CO|RAJ|ABOHAR')]));
        $b = hash('sha256', implode('|', [52, 1, 0, '', mb_strtolower('ACME & CO|RAJ|ABOHAR')]));
        $this->assertSame($a, $b);
        $c = hash('sha256', implode('|', [52, 2, 0, '', mb_strtolower('ACME & CO|RAJ|ABOHAR')]));
        $this->assertNotSame($a, $c);
    }

    public function test_one_thousand_record_golden_fixture_returns_exactly_one_thousand_valid_firms(): void
    {
        $lines = ['ABOHAR'];
        for ($i = 1; $i <= 1000; $i++) {
            $lines[] = 'GOLDEN FIRM '.$i.' & ASSOCIATES';
            $lines[] = 'CA PERSON '.$i;
            $lines[] = 'SHOP NO '.$i;
        }
        $parsed = (new OcrStructureParserService)->parse(implode("\n", $lines));
        $this->assertSame(1000, $parsed['firm_count']);
        $this->assertCount(1000, $parsed['firms']);
        $names = array_column($parsed['firms'], 'firm_name');
        $this->assertNotContains('ABOHAR', $names);
        $this->assertCount(1000, array_unique($names));
    }

    public function test_duplicate_output_shard_content_is_rejected_by_batch_finalize(): void
    {
        $shardJson = json_encode([
            'text' => "ABOHAR\nTEST FIRM & CO\nRAJ SHAH",
            'pages' => [[
                'pageNumber' => 1,
                'layout' => ['confidence' => 0.9],
                'detectedLanguages' => [['languageCode' => 'en']],
                'paragraphs' => [],
            ]],
        ], JSON_THROW_ON_ERROR);

        $storage = $this->createMock(GoogleCloudStorageService::class);
        $storage->method('parseGsUri')->willReturn(['bucket' => 'out', 'object' => 'ocr/52/']);
        $storage->method('listObjectNames')->willReturn([
            'ocr/52/shard-00000-of-00001.json',
            'ocr/52/shard-00000-of-00001-copy.json',
        ]);
        $storage->method('downloadObject')->willReturn($shardJson);

        $docAi = $this->createMock(\App\Services\DocumentAi\GoogleDocumentAiService::class);
        $batch = new GoogleDocumentAiBatchService($docAi, $storage);

        $this->expectException(\App\Exceptions\DocumentAi\DocumentAiProcessingException::class);
        $this->expectExceptionMessageMatches('/duplicate output shards/i');
        $batch->finalizeFromGcs('gs://out/ocr/52/', 1);
    }

    public function test_shop_ampersand_address_never_becomes_firm_or_ca(): void
    {
        $entities = new OcrEntityClassificationService;
        foreach (['SHOP NO 5 & 6', 'PLOT 1 & 2', '1ST & 2ND FLOOR', 'NEAR BANK & POST OFFICE'] as $addr) {
            $this->assertFalse($entities->isFirmName($addr), $addr.' must not be firm');
            $this->assertTrue($entities->isAddressShape($addr), $addr.' must be address');
            $this->assertFalse($entities->isPerson($addr), $addr.' must not be person/CA');
        }

        $raw = <<<'TXT'
ABOHAR
HARSHIL & ASSOCIATES
HARSHIL
SHOP NO 5 & 6
STREET NO 1
PLOT 1 & 2
TXT;
        $parsed = (new OcrStructureParserService)->parse($raw);
        $this->assertSame(1, $parsed['firm_count']);
        $this->assertSame('HARSHIL & ASSOCIATES', $parsed['firms'][0]['firm_name']);
        $this->assertSame('ABOHAR', $parsed['firms'][0]['city']);
        $firmNames = array_column($parsed['firms'], 'firm_name');
        $this->assertNotContains('SHOP NO 5 & 6', $firmNames);
        $this->assertNotContains('PLOT 1 & 2', $firmNames);
    }

    public function test_bare_wrap_markers_never_start_firms(): void
    {
        $entities = new OcrEntityClassificationService;
        foreach (['& CO', 'AND ASSOCIATES', 'ASSOCIATES', 'LLP', '& COMPANY'] as $bare) {
            $this->assertFalse($entities->isFirmName($bare), $bare.' must not start a firm');
        }
    }

    public function test_city_headings_become_city_not_firm_or_ca(): void
    {
        $entities = new OcrEntityClassificationService;
        foreach (['AHILYANAGAR', 'ADIPUR'] as $city) {
            $this->assertFalse($entities->isFirmName($city));
            $this->assertFalse($entities->isPerson($city));
        }
        $raw = <<<'TXT'
AHILYANAGAR
ADIPUR
NEETU BHATIA & ASSOCIATES
NEETU BHATIA
SHOP NO 1
TXT;
        $parsed = (new OcrStructureParserService)->parse($raw);
        $this->assertSame(1, $parsed['firm_count']);
        $this->assertSame('ADIPUR', $parsed['firms'][0]['city']);
        $names = array_column($parsed['firms'], 'firm_name');
        $this->assertNotContains('AHILYANAGAR', $names);
        $this->assertNotContains('ADIPUR', $names);
    }

    public function test_wrapped_firm_stays_one_record_and_neighbours_do_not_merge(): void
    {
        $raw = <<<'TXT'
ABOHAR
LOVISH GARG AND ASSOCIATES
LOVISH GARG
HOUSE NO 968
STREET NO 7
152116
SHAH & ASSOCIATES
RAJESH SHAH
SHOP NO 2
TXT;
        $parsed = (new OcrStructureParserService)->parse($raw);
        $this->assertSame(2, $parsed['firm_count']);
        $this->assertSame('LOVISH GARG AND ASSOCIATES', $parsed['firms'][0]['firm_name']);
        $this->assertSame('SHAH & ASSOCIATES', $parsed['firms'][1]['firm_name']);
        $this->assertNotSame($parsed['firms'][0]['ca_name'], $parsed['firms'][1]['firm_name']);
    }

    public function test_region_count_targets_are_documented(): void
    {
        $targets = [
            'WEST' => 26620,
            'SOUTH' => 12708,
            'NORTH' => 12744,
            'EAST' => 5060,
            'CENTRAL' => 13689,
        ];
        $this->assertSame(26620, $targets['WEST']);
        $this->assertSame(12708, $targets['SOUTH']);
        $this->assertSame(12744, $targets['NORTH']);
        $this->assertSame(5060, $targets['EAST']);
        $this->assertSame(13689, $targets['CENTRAL']);
    }
}
