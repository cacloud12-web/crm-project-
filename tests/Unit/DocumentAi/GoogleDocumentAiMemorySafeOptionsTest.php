<?php

namespace Tests\Unit\DocumentAi;

use App\Services\DocumentAi\GoogleDocumentAiService;
use Google\Cloud\DocumentAI\V1\ProcessRequest;
use Tests\TestCase;

class GoogleDocumentAiMemorySafeOptionsTest extends TestCase
{
    public function test_process_request_enables_imageless_mode_and_field_mask(): void
    {
        config([
            'document-ai.imageless_mode' => true,
            'document-ai.process_field_mask' => [
                'text',
                'entities',
                'pages.paragraphs',
                'pages.tables',
                'pages.detectedLanguages',
                'pages.layout',
            ],
        ]);

        $request = new ProcessRequest;
        app(GoogleDocumentAiService::class)->applyMemorySafeProcessOptions($request);

        $this->assertTrue($request->getImagelessMode());
        $paths = $request->getFieldMask()?->getPaths() ?? [];
        $this->assertContains('text', iterator_to_array($paths) ?: (array) $paths);
        $this->assertContains('pages.paragraphs', iterator_to_array($paths) ?: (array) $paths);
        $this->assertNotContains('pages.image', iterator_to_array($paths) ?: (array) $paths);
    }
}
