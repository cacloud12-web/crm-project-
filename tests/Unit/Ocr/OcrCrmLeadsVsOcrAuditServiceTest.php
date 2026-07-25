<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\OcrCrmLeadsVsOcrAuditService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class OcrCrmLeadsVsOcrAuditServiceTest extends TestCase
{
    public function test_normalize_and_ampersand(): void
    {
        $svc = new OcrCrmLeadsVsOcrAuditService;
        $this->assertSame(
            $svc->normalize('Manish Chandak & Associates'),
            $svc->normalize('MANISH CHANDAK AND ASSOCIATES')
        );
        $this->assertSame('DELHI', $svc->normalize('  delhi,  '));
    }

    public function test_does_not_treat_firm_title_as_ca_without_member(): void
    {
        $svc = new OcrCrmLeadsVsOcrAuditService;
        $ref = new ReflectionClass($svc);
        $method = $ref->getMethod('classifyLead');
        $method->setAccessible(true);

        $lead = (object) [
            'ca_id' => 1,
            'firm_name' => 'MANISH CHANDAK & ASSOCIATES',
            'ca_name' => 'MANISH CHANDAK',
            'city_id' => null,
            'ocr_city_text' => 'INDORE',
            'frn' => '',
            'membership_no' => '',
        ];

        // OCR has firm + city but NO explicit CA (peel must not invent).
        $ocrIndex = [
            $svc->normalize('MANISH CHANDAK & ASSOCIATES') => [
                'cas' => [],
                'cities' => [$svc->normalize('INDORE') => 'INDORE'],
                'member_count' => 0,
                'frns' => [],
                'memberships' => [],
                'firm_ids' => [10],
            ],
        ];

        $row = $method->invoke($svc, $lead, $ocrIndex, []);
        $this->assertSame(OcrCrmLeadsVsOcrAuditService::OCR_MEMBER_MISSING, $row['category']);
    }

    public function test_exact_match_when_firm_ca_city_present(): void
    {
        $svc = new OcrCrmLeadsVsOcrAuditService;
        $ref = new ReflectionClass($svc);
        $method = $ref->getMethod('classifyLead');
        $method->setAccessible(true);

        $lead = (object) [
            'ca_id' => 2,
            'firm_name' => 'GKC & COMPANY',
            'ca_name' => 'RUCHI KHATRI',
            'city_id' => null,
            'ocr_city_text' => 'JAIPUR',
            'frn' => '',
            'membership_no' => '',
        ];

        $firmKey = $svc->normalize('GKC & COMPANY');
        $ocrIndex = [
            $firmKey => [
                'cas' => [
                    $svc->normalize('RUCHI KHATRI') => 'RUCHI KHATRI',
                    $svc->normalize('RASHI KOTHARI') => 'RASHI KOTHARI',
                ],
                'cities' => [$svc->normalize('JAIPUR') => 'JAIPUR'],
                'member_count' => 2,
                'frns' => [],
                'memberships' => [],
                'firm_ids' => [20],
            ],
        ];

        $row = $method->invoke($svc, $lead, $ocrIndex, []);
        $this->assertSame(OcrCrmLeadsVsOcrAuditService::EXACT_MATCH, $row['category']);
    }

    public function test_ca_different(): void
    {
        $svc = new OcrCrmLeadsVsOcrAuditService;
        $ref = new ReflectionClass($svc);
        $method = $ref->getMethod('classifyLead');
        $method->setAccessible(true);

        $lead = (object) [
            'ca_id' => 3,
            'firm_name' => 'GKC & COMPANY',
            'ca_name' => 'SOMEONE ELSE',
            'city_id' => null,
            'ocr_city_text' => 'JAIPUR',
        ];
        $firmKey = $svc->normalize('GKC & COMPANY');
        $ocrIndex = [
            $firmKey => [
                'cas' => [$svc->normalize('RUCHI KHATRI') => 'RUCHI KHATRI'],
                'cities' => [$svc->normalize('JAIPUR') => 'JAIPUR'],
                'member_count' => 1,
                'frns' => [],
                'memberships' => [],
                'firm_ids' => [21],
            ],
        ];

        $row = $method->invoke($svc, $lead, $ocrIndex, []);
        $this->assertSame(OcrCrmLeadsVsOcrAuditService::CA_DIFFERENT, $row['category']);
    }
}
