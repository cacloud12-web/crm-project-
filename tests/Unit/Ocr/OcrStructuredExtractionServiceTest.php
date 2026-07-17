<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\OcrStructureParserService;
use App\Services\Ocr\OcrStructuredExtractionService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OcrStructuredExtractionServiceTest extends TestCase
{
    #[Test]
    public function it_prefers_corrected_text_and_keeps_missing_values_null(): void
    {
        $service = new OcrStructuredExtractionService(new OcrStructureParserService);
        $result = $service->extract(
            "ABC & ASSOCIATES\nFRN: 001234\nCA JOHN DOE\nM.No. 123456",
            'ignored extracted text',
        );

        $this->assertNotEmpty($result['firms']);
        $firm = $result['firms'][0];
        $this->assertArrayHasKey('firm_name', $firm);
        $this->assertArrayHasKey('members', $firm);
        $this->assertArrayHasKey('unclassified_lines', $firm);
        $this->assertNull($firm['district']);
        $this->assertIsArray($firm['members']);
    }

    #[Test]
    public function it_returns_empty_firms_for_blank_text(): void
    {
        $service = new OcrStructuredExtractionService(new OcrStructureParserService);
        $this->assertSame(['firms' => []], $service->extract(null, null));
    }
}
