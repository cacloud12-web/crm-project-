<?php

namespace Tests\Unit\Mapping;

use App\Services\Mapping\DataNormalizationService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DataNormalizationServiceTest extends TestCase
{
    private DataNormalizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DataNormalizationService;
    }

    #[Test]
    public function it_normalizes_firm_names_and_ocr_mistakes(): void
    {
        $this->assertSame('ABC & CO', $this->service->firmName('M/S ABC AND CO.'));
        $this->assertSame('XYZ & CO', $this->service->firmName('XYZ & CD'));
        $this->assertNull($this->service->firmName('   '));
    }

    #[Test]
    public function it_normalizes_sales_firm_and_city_for_auto_match(): void
    {
        $this->assertSame('AASTHA CO', $this->service->salesFirmName('Aastha & Co.'));
        $this->assertSame('AASTHA CO', $this->service->salesFirmName('Aastha Co'));
        $this->assertSame('JAIPUR', $this->service->salesCityName('Jaipur'));
        $this->assertSame('NEW DELHI', $this->service->salesCityName('New Delhi'));
    }

    #[Test]
    public function it_normalizes_identifiers(): void
    {
        $this->assertSame('ABCDE1234F', $this->service->pan('abcde1234f'));
        $this->assertSame('9876543210', $this->service->phone('+91 98765-43210'));
        $this->assertSame('test@example.com', $this->service->email('  Test@Example.com '));
        $this->assertSame('110001', $this->service->postalCode('110 001'));
    }
}
