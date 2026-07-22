<?php

namespace Tests\Unit\Mapping;

use App\Services\Mapping\DataNormalizationService;
use App\Services\Mapping\SalesImportMatchingService;
use App\Services\Master\LookupResolverService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SalesImportMatchingServiceTest extends TestCase
{
    #[Test]
    public function it_returns_unmatched_when_firm_or_city_missing(): void
    {
        $service = new SalesImportMatchingService(
            new DataNormalizationService,
            $this->createMock(LookupResolverService::class),
        );

        $result = $service->match(null, 'Jaipur');

        $this->assertSame('unmatched', $result['status']);
        $this->assertNull($result['ca_id']);
        $this->assertSame([], $result['candidates']);
    }

    #[Test]
    public function it_normalizes_before_matching_keys(): void
    {
        $service = new SalesImportMatchingService(
            new DataNormalizationService,
            $this->createMock(LookupResolverService::class),
        );

        $result = $service->match('Aastha & Co.', 'Jaipur');

        $this->assertSame('AASTHA CO', $result['normalized_firm_name']);
        $this->assertSame('JAIPUR', $result['normalized_city']);
        $this->assertContains($result['status'], ['matched', 'needs_review', 'unmatched']);
    }
}
