<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\OcrDirectoryRecordParser;
use App\Services\Ocr\OcrStructureParserService;
use PHPUnit\Framework\TestCase;

class OcrDineshPujaraContextAwareTest extends TestCase
{
    public function test_dinesh_pujara_record_reduces_unknown_with_context(): void
    {
        $raw = <<<'TXT'
DINESH PUJARA & ASSOCIATES
019083N
501946
PUJARA DINESH
NAI SADAK
BACKSIDE DHAYAL HOSPITAL
LAJPAT NAGAR-152116
TXT;
        $firm = (new OcrStructureParserService)->parse($raw)['firms'][0];

        $this->assertSame('DINESH PUJARA & ASSOCIATES', $firm['firm_name']);
        $this->assertSame('019083N', $firm['frn']);
        $this->assertSame('501946', $firm['membership_no']);
        $this->assertSame('PUJARA DINESH', $firm['ca_name']);
        $this->assertSame('152116', $firm['pincode']);
        $this->assertSame([], $firm['members']);
        $this->assertSame('Proprietor', $firm['ca_role']);
        $this->assertStringContainsStringIgnoringCase('BACKSIDE DHAYAL HOSPITAL', (string) $firm['address']);
        $this->assertStringContainsStringIgnoringCase('NAI SADAK', (string) $firm['address']);
        $this->assertStringContainsStringIgnoringCase('LAJPAT NAGAR', (string) $firm['address']);
        $this->assertSame([], $firm['unknown_tokens'] ?? []);

        // FRN never mistaken for membership/PIN.
        $this->assertNotSame('019083N', $firm['membership_no']);
        $this->assertNotSame('019083N', $firm['pincode']);
        // Membership never mistaken for PIN when address PIN exists.
        $this->assertNotSame('501946', $firm['pincode']);
        // Locality never partner.
        $this->assertNotContains('NAI SADAK', array_column($firm['members'], 'ca_name'));
    }

    public function test_layout_tokens_same_record_context(): void
    {
        $lines = [
            'DINESH PUJARA & ASSOCIATES', '019083N', '501946', 'PUJARA DINESH',
            'NAI SADAK', 'BACKSIDE DHAYAL HOSPITAL', 'LAJPAT NAGAR-152116',
        ];
        $tokens = [];
        foreach ($lines as $i => $text) {
            $tokens[] = [
                'text' => $text,
                'page' => 1,
                'x_center' => 0.2,
                'x_min' => 0.1,
                'x_max' => 0.35,
                'y_center' => 0.10 + ($i * 0.03),
                'y_min' => 0.09 + ($i * 0.03),
                'y_max' => 0.11 + ($i * 0.03),
                'ocr_confidence' => 0.94,
            ];
        }
        // Right-strip style FRN/membership geometry.
        $tokens[1]['x_center'] = 0.42;
        $tokens[1]['x_min'] = 0.38;
        $tokens[1]['x_max'] = 0.46;
        $tokens[2]['x_center'] = 0.42;
        $tokens[2]['x_min'] = 0.38;
        $tokens[2]['x_max'] = 0.46;

        $firm = (new OcrDirectoryRecordParser)->parseBlock($tokens, ['section_city' => 'ABOHAR']);
        $this->assertNotNull($firm);
        $this->assertSame('019083N', $firm['frn']);
        $this->assertSame('501946', $firm['membership_no']);
        $this->assertSame('152116', $firm['pincode']);
        $this->assertSame([], $firm['members']);
        $this->assertSame([], $firm['unknown_tokens']);

        $byRaw = [];
        foreach ($firm['entity_classifications'] as $row) {
            $byRaw[$row['raw']] = $row;
        }
        $this->assertSame('FRN', $byRaw['019083N']['entity_type']);
        $this->assertSame('frn', $byRaw['019083N']['assigned_field']);
        $this->assertSame('MEMBERSHIP_NUMBER', $byRaw['501946']['entity_type']);
        $this->assertSame('membership_no', $byRaw['501946']['assigned_field']);
        $this->assertSame('ADDRESS', $byRaw['NAI SADAK']['entity_type']);
        $this->assertSame('address', $byRaw['NAI SADAK']['assigned_field']);
    }
}
