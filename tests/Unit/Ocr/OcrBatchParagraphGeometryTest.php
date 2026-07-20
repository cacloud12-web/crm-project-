<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\GoogleDocumentAiBatchService;
use App\Services\Ocr\OcrLayoutDirectoryParser;
use ReflectionMethod;
use Tests\TestCase;

class OcrBatchParagraphGeometryTest extends TestCase
{
    public function test_batch_json_paragraphs_preserve_bounding_boxes_for_layout_parser(): void
    {
        $service = app(GoogleDocumentAiBatchService::class);
        $method = new ReflectionMethod(GoogleDocumentAiBatchService::class, 'extractParagraphsFromJsonPage');
        $method->setAccessible(true);

        $fullText = "ADIPUR\nTEST FIRM & CO\nRAJESH KUMAR";
        $page = [
            'paragraphs' => [
                [
                    'layout' => [
                        'textAnchor' => ['textSegments' => [['startIndex' => 0, 'endIndex' => 6]]],
                        'boundingPoly' => [
                            'normalizedVertices' => [
                                ['x' => 0.10, 'y' => 0.05],
                                ['x' => 0.40, 'y' => 0.05],
                                ['x' => 0.40, 'y' => 0.08],
                                ['x' => 0.10, 'y' => 0.08],
                            ],
                        ],
                        'confidence' => 0.94,
                    ],
                ],
                [
                    'layout' => [
                        'textAnchor' => ['textSegments' => [['startIndex' => 7, 'endIndex' => 21]]],
                        'boundingPoly' => [
                            'normalizedVertices' => [
                                ['x' => 0.10, 'y' => 0.12],
                                ['x' => 0.55, 'y' => 0.12],
                                ['x' => 0.55, 'y' => 0.16],
                                ['x' => 0.10, 'y' => 0.16],
                            ],
                        ],
                        'confidence' => 0.91,
                    ],
                ],
                [
                    'layout' => [
                        'textAnchor' => ['textSegments' => [['startIndex' => 22, 'endIndex' => 34]]],
                        'boundingPoly' => [
                            'normalizedVertices' => [
                                ['x' => 0.10, 'y' => 0.18],
                                ['x' => 0.50, 'y' => 0.18],
                                ['x' => 0.50, 'y' => 0.22],
                                ['x' => 0.10, 'y' => 0.22],
                            ],
                        ],
                        'confidence' => 0.90,
                    ],
                ],
            ],
        ];

        $paragraphs = $method->invoke($service, $page, $fullText);
        $this->assertCount(3, $paragraphs);
        $this->assertSame('ADIPUR', $paragraphs[0]['text']);
        $this->assertCount(4, $paragraphs[0]['bounding_box']);
        $this->assertSame('TEST FIRM & CO', $paragraphs[1]['text']);

        $structured = [
            'pages' => [[
                'page_number' => 1,
                'paragraphs' => $paragraphs,
            ]],
        ];
        $parser = new OcrLayoutDirectoryParser;
        $this->assertTrue($parser->canParse($structured));
    }
}
