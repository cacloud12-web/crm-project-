<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\OcrCityHeadingDetector;
use App\Services\Ocr\OcrCityResolverService;
use App\Services\Ocr\OcrDirectoryProfileDetector;
use App\Services\Ocr\OcrEntityClassificationService;
use App\Services\Ocr\OcrLayoutDirectoryParser;
use PHPUnit\Framework\TestCase;

class OcrCityAccuracyGoldenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OcrCityResolverService::clearCache();
    }

    public function test_golden_city_accuracy_fixture(): void
    {
        $path = __DIR__.'/../../Fixtures/ocr/golden_city_accuracy.json';
        $fixture = json_decode((string) file_get_contents($path), true);
        $parser = new OcrLayoutDirectoryParser;

        foreach ($fixture['cases'] as $case) {
            $parsed = $parser->parse(
                ['pages' => $case['pages']],
                $case['profile'] ?? OcrDirectoryProfileDetector::PROPRIETOR,
            );
            $this->assertNotNull($parsed, $case['id']);
            $this->assertSame($case['expected']['firm_count'], $parsed['firm_count'], $case['id'].' firm_count');
            foreach ($case['expected']['firms'] as $i => $exp) {
                $firm = $parsed['firms'][$i];
                $this->assertSame($exp['firm_name'], $firm['firm_name'], $case['id']." firm $i");
                $this->assertSame($exp['city'], $firm['city'], $case['id']." city $i");
                $this->assertSame($exp['ca_name'], $firm['ca_name'], $case['id']." ca $i");
                if (($case['profile'] ?? '') === OcrDirectoryProfileDetector::PARTNERSHIP) {
                    $this->assertSame($exp['partners'] ?? [], $firm['partners'] ?? [], $case['id']." partners $i");
                }
            }
            $cities = array_column($parsed['firms'], 'city');
            foreach ($case['expected']['must_not_be_city'] as $bad) {
                $this->assertNotContains($bad, $cities, $case['id']." city must not be $bad");
            }
            foreach ($parsed['firms'] as $firm) {
                $this->assertNotSame($firm['city'] ?? null, $firm['ca_name'] ?? '___');
                $this->assertFalse(
                    (new OcrCityHeadingDetector)->isHeading((string) ($firm['ca_name'] ?? '')),
                    $case['id'].' ca must not be city heading: '.($firm['ca_name'] ?? ''),
                );
            }
        }
    }

    public function test_patel_road_and_circular_road_never_headings(): void
    {
        $detector = new OcrCityHeadingDetector(new OcrEntityClassificationService);
        foreach (['PATEL ROAD', 'CIRCULAR ROAD', 'SARASNAGAR ROAD', 'MANMAD ROAD', 'JAGADHARI ROAD', 'BALKESHWAR ROAD', 'SETLA ROAD', 'MIRAROAD', 'tagoreroad', 'MAINROAD'] as $bad) {
            $this->assertNull($detector->detect($bad), $bad);
            $this->assertFalse((new OcrEntityClassificationService)->isCity($bad), $bad.' isCity');
            $this->assertNull((new OcrCityResolverService)->sanitizeCity($bad), $bad.' sanitize');
        }
        foreach (['24 PARAGANAS NORTH', '24 PARAGANAS SOUTH', 'NORTH 24 PARAGANAS'] as $bad) {
            $this->assertNull($detector->detect($bad), $bad);
            $this->assertFalse((new OcrEntityClassificationService)->isCity($bad), $bad.' isCity');
            $this->assertNull((new OcrCityResolverService)->sanitizeCity($bad), $bad.' sanitize');
        }
        $this->assertNotNull($detector->detect('ABU ROAD'));
        $this->assertNotNull($detector->detect('ABU ROAD (M)'));
        $this->assertSame('ABU ROAD', (new OcrCityResolverService)->sanitizeCity('ABU ROAD'));
    }

    public function test_ahily_nagar_aliases_to_ahilyanagar(): void
    {
        $resolver = new OcrCityResolverService;
        foreach (['AHILY NAGAR', 'AHILYA NAGAR', 'AHILYANAGAR'] as $raw) {
            $hit = $resolver->resolve($raw);
            $this->assertNotNull($hit, $raw);
            $this->assertSame('AHILYANAGAR', $hit['canonical_city']);
        }
        $detector = new OcrCityHeadingDetector;
        $this->assertSame('AHILYANAGAR', $detector->detect('AHILY NAGAR')['city'] ?? null);
    }

    public function test_person_and_firm_never_city_heading(): void
    {
        $detector = new OcrCityHeadingDetector;
        foreach (['ANMOL SETIA', 'HARSHIL', 'SONIA', 'GUPTA & ASSOCIATES', 'ALPHA & CO'] as $bad) {
            $this->assertNull($detector->detect($bad), $bad);
        }
    }

    public function test_column_city_does_not_leak(): void
    {
        $structured = [
            'pages' => [[
                'page_number' => 1,
                'paragraphs' => [
                    ['text' => 'ABOHAR', 'bounding_box' => $this->box(0.10, 0.04)],
                    ['text' => 'LEFT & ASSOCIATES', 'bounding_box' => $this->box(0.10, 0.12)],
                    ['text' => 'LEFT CA', 'bounding_box' => $this->box(0.10, 0.15)],
                    ['text' => 'AMBALA', 'bounding_box' => $this->box(0.60, 0.04)],
                    ['text' => 'RIGHT & ASSOCIATES', 'bounding_box' => $this->box(0.60, 0.12)],
                    ['text' => 'RIGHT CA', 'bounding_box' => $this->box(0.60, 0.15)],
                ],
            ]],
        ];
        $parsed = (new OcrLayoutDirectoryParser)->parse($structured, OcrDirectoryProfileDetector::PROPRIETOR);
        $this->assertSame(2, $parsed['firm_count']);
        $byFirm = [];
        foreach ($parsed['firms'] as $f) {
            $byFirm[$f['firm_name']] = $f['city'];
        }
        $this->assertSame('ABOHAR', $byFirm['LEFT & ASSOCIATES'] ?? null);
        $this->assertSame('AMBALA', $byFirm['RIGHT & ASSOCIATES'] ?? null);
    }

    public function test_middle_column_inherits_section_city_when_heading_only_on_left(): void
    {
        $structured = [
            'pages' => [[
                'page_number' => 1,
                'paragraphs' => [
                    ['text' => 'AHILYANAGAR', 'bounding_box' => $this->box(0.10, 0.04)],
                    ['text' => 'LEFT FIRM & CO', 'bounding_box' => $this->box(0.10, 0.12)],
                    ['text' => 'LEFT PARTNER', 'bounding_box' => $this->box(0.10, 0.15)],
                    ['text' => 'MIDDLE FIRM & ASSOCIATES', 'bounding_box' => $this->box(0.40, 0.12)],
                    ['text' => 'MIDDLE PARTNER', 'bounding_box' => $this->box(0.40, 0.15)],
                    ['text' => 'RIGHT FIRM AND CO', 'bounding_box' => $this->box(0.70, 0.12)],
                    ['text' => 'RIGHT PARTNER', 'bounding_box' => $this->box(0.70, 0.15)],
                ],
            ]],
        ];
        $parsed = (new OcrLayoutDirectoryParser)->parse($structured, OcrDirectoryProfileDetector::PARTNERSHIP);
        $this->assertSame(3, $parsed['firm_count']);
        foreach ($parsed['firms'] as $firm) {
            $this->assertSame('AHILYANAGAR', $firm['city'], $firm['firm_name'].' missing inherited city');
        }
    }

    public function test_uncertain_page_continuation_does_not_blind_carry(): void
    {
        $structured = [
            'pages' => [
                [
                    'page_number' => 1,
                    'paragraphs' => [
                        ['text' => 'ADIPUR', 'bounding_box' => $this->box(0.10, 0.04)],
                        ['text' => 'PAGEONE & CO', 'bounding_box' => $this->box(0.10, 0.80)],
                        ['text' => 'PAGE ONE CA', 'bounding_box' => $this->box(0.10, 0.83)],
                    ],
                ],
                [
                    'page_number' => 2,
                    'paragraphs' => [
                        ['text' => 'AMBALA', 'bounding_box' => $this->box(0.10, 0.04)],
                        ['text' => 'PAGETWO & ASSOCIATES', 'bounding_box' => $this->box(0.10, 0.12)],
                        ['text' => 'PAGE TWO CA', 'bounding_box' => $this->box(0.10, 0.15)],
                    ],
                ],
            ],
        ];
        $parsed = (new OcrLayoutDirectoryParser)->parse($structured, OcrDirectoryProfileDetector::PROPRIETOR);
        $cities = array_column($parsed['firms'], 'city', 'firm_name');
        $this->assertSame('ADIPUR', $cities['PAGEONE & CO'] ?? null);
        $this->assertSame('AMBALA', $cities['PAGETWO & ASSOCIATES'] ?? null);
    }

    /** @return list<array{x: float, y: float}> */
    private function box(float $x, float $y): array
    {
        return [
            ['x' => $x, 'y' => $y],
            ['x' => $x + 0.28, 'y' => $y],
            ['x' => $x + 0.28, 'y' => $y + 0.02],
            ['x' => $x, 'y' => $y + 0.02],
        ];
    }
}
