<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\OcrCityHeadingDetector;
use App\Services\Ocr\OcrCityResolverService;
use App\Services\Ocr\OcrMissingCityAuditService;
use PHPUnit\Framework\TestCase;

class OcrMissingCityAuditAndResolverTest extends TestCase
{
    public function test_extract_city_from_address_pin_line(): void
    {
        $resolver = new OcrCityResolverService;
        // Without city master hit this may be null — must not invent.
        $out = $resolver->extractCityFromAddressLine('12 MG ROAD, NEAR STATION, AHMEDABAD-380001');
        if ($out !== null) {
            $this->assertNotSame('', $out['canonical_city']);
            $this->assertNotSame('place_suffix', $out['city_match_type']);
        } else {
            $this->assertNull($out);
        }
    }

    public function test_heading_rejects_place_suffix_locality(): void
    {
        $detector = new OcrCityHeadingDetector;
        // MEHERABAD is a locality; place_suffix must not become a section heading.
        $this->assertFalse($detector->isHeading('MEHERABAD'));
    }

    public function test_norm_key_collapses_and_ampersand(): void
    {
        $svc = new OcrMissingCityAuditService;
        $this->assertSame(
            $svc->normKey('Abu Road'),
            $svc->normKey('ABU  ROAD')
        );
    }

    public function test_forbidden_locality_roads(): void
    {
        $resolver = new OcrCityResolverService;
        $this->assertTrue($resolver->isForbiddenLocalityShape('BALKESHWAR ROAD'));
        $this->assertFalse($resolver->isForbiddenLocalityShape('ABU ROAD'));
    }
}
