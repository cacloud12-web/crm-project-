<?php

namespace App\Services\Ocr;

/**
 * Normalized structured extraction facade over OcrStructureParserService.
 *
 * Never invents missing values — absent fields remain null.
 */
class OcrStructuredExtractionService
{
    public function __construct(
        private readonly OcrStructureParserService $parser,
    ) {}

    /**
     * @param  array<string, mixed>|null  $layoutMetadata
     * @return array{firms: list<array<string, mixed>>}
     */
    public function extract(?string $correctedText, ?string $extractedText, ?array $layoutMetadata = null): array
    {
        $text = trim((string) ($correctedText !== null && $correctedText !== '' ? $correctedText : $extractedText));
        if ($text === '') {
            return ['firms' => []];
        }

        $parsed = $this->parser->parse($text);
        $firms = [];

        foreach ($parsed['firms'] as $firm) {
            $members = [];
            foreach ($firm['members'] ?? [] as $member) {
                $members[] = [
                    'ca_name' => $member['ca_name'] ?? null,
                    'membership_number' => $member['membership_no'] ?? ($member['membership_number'] ?? null),
                    'role' => $member['role'] ?? 'unknown',
                    'mobile' => $member['mobile'] ?? null,
                    'email' => $member['email'] ?? null,
                    'confidence' => $member['overall_confidence'] ?? ($member['confidence'] ?? null),
                    'raw_ca_name' => $member['raw_ca_name'] ?? ($member['ca_name'] ?? null),
                    'pan_number' => $member['pan_no'] ?? ($member['pan_number'] ?? null),
                    'source_page' => $member['page_number'] ?? ($firm['page_number'] ?? null),
                    'is_primary' => (bool) ($member['is_primary'] ?? false),
                ];
            }

            $firms[] = [
                'firm_name' => $firm['firm_name'] ?? null,
                'firm_type' => $firm['firm_type'] ?? 'unknown',
                'frn_number' => $firm['frn'] ?? ($firm['frn_number'] ?? null),
                'gst_number' => $firm['gst_no'] ?? ($firm['gst_number'] ?? null),
                'pan_number' => $firm['pan_no'] ?? ($firm['pan_number'] ?? null),
                'address' => $firm['address'] ?? null,
                'city' => $firm['city'] ?? null,
                'district' => $firm['district'] ?? null,
                'state' => $firm['state'] ?? null,
                'postal_code' => $firm['pincode'] ?? ($firm['postal_code'] ?? null),
                'phone' => $firm['phone'] ?? null,
                'email' => $firm['email'] ?? null,
                'website' => $firm['website'] ?? null,
                'partner_count' => $firm['partner_count'] ?? (count($members) > 0 ? count($members) : null),
                'source_page' => $firm['page_number'] ?? null,
                'confidence' => $firm['overall_confidence'] ?? ($firm['confidence'] ?? null),
                'members' => $members,
                'unclassified_lines' => $firm['unclassified_lines'] ?? [],
                'layout' => is_array($layoutMetadata) ? ($layoutMetadata['pages'] ?? null) : null,
            ];
        }

        return ['firms' => $firms];
    }
}
