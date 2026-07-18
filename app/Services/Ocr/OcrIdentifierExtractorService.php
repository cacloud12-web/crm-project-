<?php

namespace App\Services\Ocr;

/**
 * Dedicated identifier extractors — each field type has its own parser with evidence.
 * Never guess: returns null when pattern/position/context evidence is insufficient.
 */
class OcrIdentifierExtractorService
{
    /** ICAI FRN regional suffix letters commonly seen in directories. */
    private const FRN_SUFFIXES = 'NCWES';

    /**
     * @return array{value: string, raw: string, confidence: float, evidence: string}|null
     */
    public function extractMembership(string $text, array $context = []): ?array
    {
        $raw = trim($text);
        if ($raw === '') {
            return null;
        }
        // Digit+letter is FRN, never membership.
        if ($this->isIcaiFrnPattern($raw)) {
            return null;
        }
        if (preg_match('/\b(?:m\.?\s*no\.?|mem(?:bership)?\.?\s*no\.?|membership(?:\s*no\.?)?|icai)\s*[:\-]?\s*([A-Z0-9\/\-]{4,20})\b/i', $raw, $m)) {
            return $this->hit(strtoupper($m[1]), $raw, 0.92, 'labeled_membership');
        }
        if ($cityLine = $this->extractCityMembershipLine($raw)) {
            return $cityLine;
        }
        if (preg_match('/[-–]\s*(\d{5,8})\s*$/i', $raw, $m) && preg_match('/&|associates|company|\bco\.?\b/i', $raw)
            && ! preg_match('/[A-Z]$/i', trim($m[1]))) {
            return $this->hit($m[1], $raw, 0.9, 'firm_line_identifier');
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        $isRight = (bool) ($context['is_right_aligned'] ?? false);
        $inAddress = (bool) ($context['in_address_context'] ?? false);
        $confirmedPin = isset($context['confirmed_pin']) ? (string) $context['confirmed_pin'] : null;
        $preferMembership = (bool) ($context['prefer_membership'] ?? false);

        if (! preg_match('/^\s*\d{5,8}\s*$/', $raw)) {
            return null;
        }
        if ($confirmedPin !== null && $digits === $confirmedPin) {
            return null;
        }
        if ($inAddress) {
            return null;
        }
        // Record already has an address-attached PIN → other standalone 5–8 digit = membership.
        if ($confirmedPin !== null && $confirmedPin !== '' && $digits !== $confirmedPin) {
            return $this->hit($digits, $raw, 0.88, 'membership_with_confirmed_pin');
        }
        if ($preferMembership && strlen($digits) >= 5 && strlen($digits) <= 8) {
            return $this->hit($digits, $raw, 0.84, 'membership_record_context');
        }
        if ($isRight && strlen($digits) >= 5 && strlen($digits) <= 8) {
            return $this->hit($digits, $raw, 0.85, 'right_column_membership');
        }

        return null;
    }

    /**
     * @return array{value: string, raw: string, confidence: float, evidence: string}|null
     */
    public function extractFrn(string $text, array $context = []): ?array
    {
        $raw = trim($text);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/\bfrn\s*[:\-]?\s*([A-Z0-9\/\-]{4,20})\b/i', $raw, $m)) {
            return $this->hit(strtoupper($m[1]), $raw, 0.9, 'labeled_frn');
        }
        if (preg_match('/\bfirm\s*reg(?:istration)?\s*(?:no|number)?\s*[:\-]?\s*([A-Z0-9\/\-]{4,20})\b/i', $raw, $m)) {
            return $this->hit(strtoupper($m[1]), $raw, 0.88, 'labeled_firm_registration');
        }
        // ICAI FRN: 5–6 digits + regional letter (019083N, 037378C).
        if ($this->isIcaiFrnPattern($raw)) {
            return $this->hit(strtoupper(preg_replace('/\s+/', '', $raw) ?? $raw), $raw, 0.91, 'icai_frn_pattern');
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        $isRight = (bool) ($context['is_right_aligned'] ?? false);
        if ($isRight && strlen($digits) >= 4 && strlen($digits) <= 8 && ! $this->looksLikePin($digits) && ! $this->looksLikeMobile($digits)
            && ! preg_match('/^\d{5,8}$/', trim($raw))) {
            return $this->hit(strtoupper(preg_replace('/\s+/', '', $raw) ?? $raw), $raw, 0.78, 'right_column_frn');
        }

        return null;
    }

    /**
     * @return array{value: string, raw: string, confidence: float, evidence: string}|null
     */
    public function extractGst(string $text): ?array
    {
        if (preg_match('/\b([0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z])\b/i', $text, $m)) {
            return $this->hit(strtoupper($m[1]), $text, 0.95, 'gst_pattern');
        }
        if (preg_match('/\bgst(?:in| no\.?| number)?\s*[:\-]?\s*([0-9A-Z]{15})\b/i', $text, $m)) {
            return $this->hit(strtoupper($m[1]), $text, 0.93, 'labeled_gst');
        }

        return null;
    }

    /**
     * @return array{value: string, raw: string, confidence: float, evidence: string}|null
     */
    public function extractPan(string $text): ?array
    {
        if (preg_match('/\bpan\s*[:\-]?\s*([A-Z]{5}[0-9]{4}[A-Z])\b/i', $text, $m)) {
            return $this->hit(strtoupper($m[1]), $text, 0.94, 'labeled_pan');
        }
        if (preg_match('/\b([A-Z]{5}[0-9]{4}[A-Z])\b/', strtoupper($text), $m) && ! $this->extractGst($text)) {
            return $this->hit($m[1], $text, 0.88, 'pan_pattern');
        }

        return null;
    }

    /**
     * @return array{value: string, raw: string, confidence: float, evidence: string}|null
     */
    public function extractPhone(string $text): ?array
    {
        if (preg_match('/(?<!\d)(?:\+91[\-\s]?|0)?([6-9](?:[\s\-]?\d){9})(?!\d)/u', $text, $m)) {
            $digits = preg_replace('/\D+/', '', $m[1]) ?? '';

            return strlen($digits) === 10 ? $this->hit($digits, $text, 0.9, 'mobile_pattern') : null;
        }

        return null;
    }

    /**
     * @return array{value: string, raw: string, confidence: float, evidence: string}|null
     */
    public function extractEmail(string $text): ?array
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m)) {
            return $this->hit(strtolower($m[0]), $text, 0.96, 'email_pattern');
        }

        return null;
    }

    /**
     * @return array{value: string, raw: string, confidence: float, evidence: string, locality: ?string}|null
     */
    public function extractPincode(string $text, array $context = []): ?array
    {
        if (preg_match('/\b(?:m\.?\s*no\.?|mem(?:bership)?\.?\s*no\.?|membership)\b/i', $text)) {
            return null;
        }
        if ($this->isIcaiFrnPattern($text)) {
            return null;
        }
        if (preg_match('/\b([1-9]\d{5})\b/', $text, $m)) {
            $pin = $m[1];
            $locality = trim(preg_replace('/\b[1-9]\d{5}\b/', '', $text) ?? '');
            $locality = trim(preg_replace('/[\-\s,]+$/', '', $locality) ?? $locality);
            $inAddress = (bool) ($context['in_address_context'] ?? false);
            $isRight = (bool) ($context['is_right_aligned'] ?? false);
            // Standalone 6-digit without locality is NOT a PIN unless inside an address block.
            if ($locality === '') {
                if ($isRight && ! $inAddress) {
                    return null;
                }
                if (! $inAddress) {
                    return null;
                }
            }
            if ($locality !== '' && ! $inAddress && $this->isCityOnlyLocality($locality)) {
                return null;
            }

            return [
                'value' => $pin,
                'raw' => $text,
                'confidence' => $locality !== '' ? 0.94 : 0.88,
                'evidence' => $locality !== '' ? 'address_line_pin' : 'standalone_pin',
                'locality' => $locality !== '' ? $locality : null,
            ];
        }

        return null;
    }

    public function isIcaiFrnPattern(string $text): bool
    {
        return (bool) preg_match('/^\d{5,6}\s*['.self::FRN_SUFFIXES.']$/i', trim($text));
    }

    public function looksLikePin(string $digits): bool
    {
        return (bool) preg_match('/^[1-9]\d{5}$/', $digits);
    }

    public function looksLikeMobile(string $digits): bool
    {
        return strlen($digits) === 10 && (bool) preg_match('/^[6-9]/', $digits);
    }

    /**
     * ICAI directory lines: "ABOHAR - 562848" → city + membership (not PIN).
     *
     * @return array{value: string, raw: string, confidence: float, evidence: string, locality: string}|null
     */
    private function extractCityMembershipLine(string $raw): ?array
    {
        if (! preg_match('/^([A-Z][A-Z\s]{1,40}?)\s*-\s*(\d{5,8})$/i', $raw, $m)) {
            return null;
        }
        $city = trim($m[1]);
        $num = $m[2];
        if ($city === '' || ! $this->isCityOnlyLocality($city)) {
            return null;
        }

        return [
            'value' => $num,
            'raw' => $raw,
            'confidence' => 0.9,
            'evidence' => 'city_dash_membership',
            'locality' => strtoupper($city),
        ];
    }

    private function isCityOnlyLocality(string $locality): bool
    {
        $u = strtoupper(trim($locality));
        if ($u === '' || preg_match('/\d/', $u)) {
            return false;
        }
        $addressMarkers = '/\b(?:FLOOR|SCO|STREET|ROAD|SADAK|NAGAR|MANDI|ESTATE|SECTOR|COLONY|CANTT|PLAZA|BUILDING|BHAWAN|CHOWK|LANE|BLOCK|PHASE|HUDA|CROSSING|HOSPITAL|TEMPLE|SCHOOL|MARKET)\b/';

        return ! preg_match($addressMarkers, $u);
    }

    /**
     * @return array{value: string, raw: string, confidence: float, evidence: string}
     */
    private function hit(string $value, string $raw, float $confidence, string $evidence): array
    {
        return ['value' => $value, 'raw' => $raw, 'confidence' => $confidence, 'evidence' => $evidence];
    }
}
