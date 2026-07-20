<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\OcrDirectoryProfileDetector;
use App\Services\Ocr\OcrHumanNameClassifier;
use App\Services\Ocr\OcrLayoutDirectoryParser;
use App\Services\Ocr\OcrPartnershipDirectoryExtractor;
use PHPUnit\Framework\TestCase;

class OcrPartnershipDirectoryExtractorTest extends TestCase
{
    public function test_golden_partnership_page_extracts_firm_city_ca_and_partners(): void
    {
        $path = __DIR__.'/../../Fixtures/ocr/golden_partnership_directory_page.json';
        $fixture = json_decode((string) file_get_contents($path), true);
        $parser = new OcrLayoutDirectoryParser;
        $parsed = $parser->parse($fixture, OcrDirectoryProfileDetector::PARTNERSHIP);

        $this->assertNotNull($parsed);
        $this->assertSame($fixture['expected']['firm_count'], $parsed['firm_count']);
        $this->assertSame(OcrDirectoryProfileDetector::PARTNERSHIP, $parsed['directory_profile']);

        foreach ($fixture['expected']['firms'] as $i => $exp) {
            $firm = $parsed['firms'][$i];
            $this->assertSame($exp['firm_name'], $firm['firm_name'], 'firm '.$i);
            $this->assertSame($exp['city'], $firm['city'], 'city '.$i);
            if (! empty($exp['wrapped_partner_or_ca'])) {
                $this->assertNotNull($firm['ca_name']);
                $this->assertStringContainsString('ANIL', (string) $firm['ca_name']);
            } else {
                $this->assertSame($exp['ca_name'], $firm['ca_name'], 'ca '.$i);
                $this->assertSame($exp['partners'], $firm['partners'] ?? [], 'partners '.$i);
                $this->assertSame($exp['partner_count'], count($firm['partners'] ?? []));
            }
            $personBag = array_merge(
                [mb_strtolower((string) ($firm['ca_name'] ?? ''))],
                array_map('mb_strtolower', $firm['partners'] ?? []),
            );
            foreach ($exp['must_not_contain_as_person'] as $bad) {
                $this->assertNotContains(mb_strtolower($bad), $personBag, $bad.' must not be person');
            }
        }

        $firmNames = array_column($parsed['firms'], 'firm_name');
        foreach ($fixture['expected']['section_headings_not_firms'] as $heading) {
            $this->assertNotContains($heading, $firmNames);
        }
        foreach ($parsed['firms'] as $firm) {
            foreach ($fixture['expected']['section_headings_not_persons'] as $heading) {
                $this->assertNotSame($heading, $firm['ca_name'] ?? null);
                $this->assertNotContains($heading, $firm['partners'] ?? []);
            }
        }
    }

    public function test_city_heading_never_becomes_ca_or_partner(): void
    {
        $extractor = new OcrPartnershipDirectoryExtractor;
        $firm = $extractor->extract([
            ['text' => 'MEHTA & CO', 'page' => 1, 'column' => 1],
            ['text' => 'AMBALA', 'page' => 1, 'column' => 1],
            ['text' => 'RIYA MEHTA', 'page' => 1, 'column' => 1],
            ['text' => '12 ROAD STREET', 'page' => 1, 'column' => 1],
        ], ['section_city' => 'AMBALA', 'directory_profile' => OcrDirectoryProfileDetector::PARTNERSHIP]);

        $this->assertNotNull($firm);
        $this->assertSame('AMBALA', $firm['city']);
        $this->assertSame('RIYA MEHTA', $firm['ca_name']);
        $this->assertNotContains('AMBALA', $firm['partners']);
        $this->assertNotSame('AMBALA', $firm['ca_name']);
    }

    public function test_address_never_becomes_firm_ca_or_partner(): void
    {
        $extractor = new OcrPartnershipDirectoryExtractor;
        $firm = $extractor->extract([
            ['text' => 'GUPTA ASSOCIATES', 'page' => 1],
            ['text' => 'NEHA GUPTA', 'page' => 1],
            ['text' => '1ST FLOOR SCO-4 NEW SURAJ NAGAR', 'page' => 1],
            ['text' => 'ANAJ MANDI AMBALA', 'page' => 1],
            ['text' => 'FAKE PARTNER AFTER ADDRESS', 'page' => 1],
        ], ['section_city' => 'AMBALA']);

        $this->assertNotNull($firm);
        $this->assertSame('GUPTA ASSOCIATES', $firm['firm_name']);
        $this->assertSame('NEHA GUPTA', $firm['ca_name']);
        $this->assertSame([], $firm['partners']);
        $this->assertStringNotContainsStringIgnoringCase('FLOOR', (string) $firm['ca_name']);
        $this->assertStringNotContainsStringIgnoringCase('MANDI', implode(' ', $firm['partners']));
    }

    public function test_partners_do_not_leak_across_firms(): void
    {
        $parser = new OcrLayoutDirectoryParser;
        $structured = [
            'pages' => [[
                'page_number' => 1,
                'paragraphs' => [
                    $this->para('ABOHAR', 0.1, 0.04),
                    $this->para('ALPHA & ASSOCIATES', 0.1, 0.10),
                    $this->para('ALPHA ONE', 0.1, 0.13),
                    $this->para('ALPHA TWO', 0.1, 0.15),
                    $this->para('12 ROAD COLONY', 0.1, 0.18),
                    $this->para('BETA AND CO', 0.1, 0.28),
                    $this->para('BETA ONE', 0.1, 0.31),
                    $this->para('PLOT 9 MARKET', 0.1, 0.34),
                ],
            ]],
        ];
        $parsed = $parser->parse($structured, OcrDirectoryProfileDetector::PARTNERSHIP);
        $this->assertSame(2, $parsed['firm_count']);
        $this->assertSame(['ALPHA TWO'], $parsed['firms'][0]['partners']);
        $this->assertNotContains('BETA ONE', $parsed['firms'][0]['partners']);
        $this->assertSame('BETA ONE', $parsed['firms'][1]['ca_name']);
        $this->assertNotContains('ALPHA TWO', $parsed['firms'][1]['partners']);
    }

    public function test_duplicate_partner_line_removed(): void
    {
        $extractor = new OcrPartnershipDirectoryExtractor;
        $firm = $extractor->extract([
            ['text' => 'SHAH ASSOCIATES'],
            ['text' => 'RAJ SHAH'],
            ['text' => 'MEERA SHAH'],
            ['text' => 'MEERA SHAH'],
        ], ['section_city' => 'SURAT']);

        $this->assertSame(['MEERA SHAH'], $firm['partners']);
    }

    public function test_unclear_primary_ca_not_guessed_from_address(): void
    {
        $extractor = new OcrPartnershipDirectoryExtractor;
        $firm = $extractor->extract([
            ['text' => 'SOLO LLP'],
            ['text' => 'HOUSE NO 12 ROAD'],
            ['text' => 'NEAR SCHOOL'],
        ], ['section_city' => 'SURAT']);

        $this->assertNotNull($firm);
        $this->assertNull($firm['ca_name']);
        $this->assertSame([], $firm['partners']);
    }

    public function test_human_name_classifier_rejects_city_and_address(): void
    {
        $names = new OcrHumanNameClassifier;
        $this->assertFalse($names->isValid('AMBALA'));
        $this->assertFalse($names->isValid('1ST FLOOR ROAD'));
        $this->assertFalse($names->isValid('GUPTA & ASSOCIATES'));
        $this->assertTrue($names->isValid('AYUSHI GUPTA'));
    }

    public function test_synthetic_thousand_firm_partnership_fixture_count(): void
    {
        $paragraphs = [];
        $y = 0.02;
        $paragraphs[] = $this->para('SURAT', 0.1, $y);
        $y += 0.01;
        for ($i = 1; $i <= 1000; $i++) {
            $paragraphs[] = $this->para(sprintf('FIRM %04d & ASSOCIATES', $i), 0.1, $y);
            $y += 0.0008;
            $paragraphs[] = $this->para(sprintf('PARTNER %04d A', $i), 0.1, $y);
            $y += 0.0008;
            $paragraphs[] = $this->para(sprintf('PARTNER %04d B', $i), 0.1, $y);
            $y += 0.0008;
            $paragraphs[] = $this->para(sprintf('%d ROAD COLONY PIN 395001', $i), 0.1, $y);
            $y += 0.0012;
            if ($y > 0.98) {
                $y = 0.02;
            }
        }
        $structured = ['pages' => [['page_number' => 1, 'paragraphs' => $paragraphs]]];
        $parsed = (new OcrLayoutDirectoryParser)->parse($structured, OcrDirectoryProfileDetector::PARTNERSHIP);
        $this->assertNotNull($parsed);
        $this->assertSame(1000, $parsed['firm_count']);
    }

    public function test_filename_hint_detects_part_not_prop(): void
    {
        $detector = new OcrDirectoryProfileDetector;
        $doc = new class
        {
            public $original_filename = 'westpart.pdf';

            public $structured_data = [];

            public $extracted_text = null;

            public function displayText(): string
            {
                return '';
            }
        };
        // Avoid content override — empty sample falls back to filename.
        $profile = $detector->detect(
            new \App\Models\OcrDocument(['original_filename' => 'westpart.pdf', 'structured_data' => [], 'extracted_text' => '']),
            [],
            '',
        );
        $this->assertSame(OcrDirectoryProfileDetector::PARTNERSHIP, $profile);

        $prop = $detector->detect(
            new \App\Models\OcrDocument(['original_filename' => 'westprop.pdf', 'structured_data' => [], 'extracted_text' => '']),
            [],
            '',
        );
        $this->assertSame(OcrDirectoryProfileDetector::PROPRIETOR, $prop);
    }

    public function test_membership_numbers_do_not_end_partner_collection(): void
    {
        $extractor = new OcrPartnershipDirectoryExtractor;
        $firm = $extractor->extract([
            ['text' => 'BANERJEE SARKAR & CO', 'page' => 1],
            ['text' => '329018E', 'page' => 1],
            ['text' => 'BASU HIRALAL', 'page' => 1],
            ['text' => '005376', 'page' => 1],
            ['text' => 'DUTTA BISWAJIT', 'page' => 1],
            ['text' => '053167', 'page' => 1],
            ['text' => 'SOUMYA BANERJEE', 'page' => 1],
            ['text' => '303233', 'page' => 1],
            ['text' => 'AVISHEK SARKAR', 'page' => 1],
            ['text' => 'BD-386 SECTOR-I', 'page' => 1],
        ], ['section_city' => 'KOLKATA']);

        $this->assertSame('BASU HIRALAL', $firm['ca_name']);
        $this->assertSame(['DUTTA BISWAJIT', 'SOUMYA BANERJEE', 'AVISHEK SARKAR'], $firm['partners']);
    }

    public function test_multiline_ocr_firm_token_does_not_swallow_partners(): void
    {
        $extractor = new OcrPartnershipDirectoryExtractor;
        $firm = $extractor->extract([
            [
                'text' => "AGRAWAL GOYAL & CO\nVISHNU BABOO AGRAWAL\nPRADEEP KUMAR GUPTA\nDHARAMVEER SHARMA",
                'page' => 1,
                'y_min' => 0.47,
                'y_max' => 0.49,
            ],
            ['text' => 'FLAT NO 201 2ND FLOOR', 'page' => 1],
            ['text' => 'PRATEEK TOWER', 'page' => 1],
        ], ['section_city' => 'ABHANPUR', 'page' => 1, 'column' => 2]);

        $this->assertSame('AGRAWAL GOYAL & CO', $firm['firm_name']);
        $this->assertSame('VISHNU BABOO AGRAWAL', $firm['ca_name']);
        $this->assertSame(['PRADEEP KUMAR GUPTA', 'DHARAMVEER SHARMA'], $firm['partners']);
        $this->assertSame(2, $firm['partner_count']);
        $this->assertStringNotContainsString('VISHNU', (string) $firm['firm_name']);
    }

    public function test_space_glued_firm_and_partners_are_split(): void
    {
        $extractor = new OcrPartnershipDirectoryExtractor;
        $firm = $extractor->extract([
            [
                'text' => 'AGRAWAL GOYAL & CO VISHNU BABOO AGRAWAL PRADEEP KUMAR GUPTA DHARAMVEER SHARMA',
                'page' => 1,
            ],
            ['text' => 'FLAT NO 201 2ND FLOOR', 'page' => 1],
        ], ['section_city' => 'ABHANPUR']);

        $this->assertSame('AGRAWAL GOYAL & CO', $firm['firm_name']);
        $this->assertSame('VISHNU BABOO AGRAWAL', $firm['ca_name']);
        $this->assertSame(['PRADEEP KUMAR GUPTA', 'DHARAMVEER SHARMA'], $firm['partners']);
    }

    /** @return array<string, mixed> */
    private function para(string $text, float $x, float $y): array
    {
        return [
            'text' => $text,
            'bounding_box' => [
                ['x' => $x, 'y' => $y],
                ['x' => $x + 0.3, 'y' => $y],
                ['x' => $x + 0.3, 'y' => $y + 0.01],
                ['x' => $x, 'y' => $y + 0.01],
            ],
            'confidence' => 0.95,
        ];
    }
}
