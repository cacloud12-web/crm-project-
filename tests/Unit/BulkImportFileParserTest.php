<?php

namespace Tests\Unit;

use App\Services\Bulk\BulkImportFileParser;
use App\Services\Bulk\BulkImportTemplateService;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class BulkImportFileParserTest extends TestCase
{
    public function test_parses_namespaced_excel_template(): void
    {
        $binary = app(BulkImportTemplateService::class)->sampleXlsx();
        $file = UploadedFile::fake()->createWithContent('sample.xlsx', $binary);

        $parsed = app(BulkImportFileParser::class)->parse($file);

        $this->assertSame(BulkImportTemplateService::TEMPLATE_HEADERS, $parsed['headers']);
        $this->assertCount(1, $parsed['rows']);
        $this->assertSame('Sample Firm Pvt Ltd', $parsed['rows'][0]['firm_name']);
        $this->assertSame('Sample CA', $parsed['rows'][0]['ca_name']);
    }
}
