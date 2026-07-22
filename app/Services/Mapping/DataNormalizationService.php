<?php

namespace App\Services\Mapping;

/**
 * Normalizes OCR / CA reference values while preserving raw originals elsewhere.
 */
class DataNormalizationService
{
    public function firmName(?string $value): ?string
    {
        $value = $this->collapseWhitespace($value);
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\bM\/S\.?\b/iu', '', $value) ?? $value;
        $value = str_ireplace(['& CD', '&CD'], '& CO', $value);
        $value = preg_replace('/\bAND\b/iu', '&', $value) ?? $value;
        $value = preg_replace('/\bCOMPANY\b/iu', 'CO', $value) ?? $value;
        $value = preg_replace('/\bCO\.?\b/iu', 'CO', $value) ?? $value;
        $value = preg_replace('/\bPVT\.?\s*LTD\.?\b/iu', 'PVT LTD', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N}\s&.\-]/iu', ' ', $value) ?? $value;
        $value = rtrim($value, ' .');

        return $this->collapseWhitespace($value);
    }

    public function caName(?string $value): ?string
    {
        $value = $this->collapseWhitespace($value);
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/^(CA\.?|MR\.?|MRS\.?|MS\.?|SHRI|SMT)\s+/iu', '', $value) ?? $value;

        return $this->collapseWhitespace($value);
    }

    public function frn(?string $value): ?string
    {
        $value = $this->collapseWhitespace($value);
        if ($value === null) {
            return null;
        }

        $value = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $value) ?? $value);

        return $value !== '' ? $value : null;
    }

    public function membershipNumber(?string $value): ?string
    {
        return $this->frn($value);
    }

    public function gst(?string $value): ?string
    {
        $value = $this->collapseWhitespace($value);
        if ($value === null) {
            return null;
        }

        $value = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $value) ?? $value);
        if ($value === '' || strlen($value) !== 15) {
            return $value !== '' ? $value : null;
        }

        return $value;
    }

    public function pan(?string $value): ?string
    {
        $value = $this->collapseWhitespace($value);
        if ($value === null) {
            return null;
        }

        $value = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $value) ?? $value);
        if ($value === '' || strlen($value) !== 10) {
            return $value !== '' ? $value : null;
        }

        return $value;
    }

    public function phone(?string $value): ?string
    {
        $value = $this->collapseWhitespace($value);
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (str_starts_with($digits, '91') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = substr($digits, 1);
        }

        return $digits !== '' ? $digits : null;
    }

    public function email(?string $value): ?string
    {
        $value = $this->collapseWhitespace($value);
        if ($value === null) {
            return null;
        }

        $value = strtolower($value);

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    public function postalCode(?string $value): ?string
    {
        $value = $this->collapseWhitespace($value);
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits !== '' ? $digits : null;
    }

    public function city(?string $value): ?string
    {
        $value = $this->collapseWhitespace($value);

        return $value !== null ? mb_strtoupper($value) : null;
    }

    /**
     * Sales-list Auto Match firm key.
     * Uppercase, strip punctuation, collapse spaces; firm connectors & / AND removed so
     * "Aastha & Co." and "Aastha Co" both become AASTHA CO (product Auto Match example).
     */
    public function salesFirmName(?string $value): ?string
    {
        $value = $this->collapseWhitespace($value);
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\bM\/S\.?\b/iu', ' ', $value) ?? $value;
        $value = str_ireplace(['& CD', '&CD'], ' CO ', $value);
        $value = preg_replace('/\bCOMPANY\b/iu', ' CO ', $value) ?? $value;
        $value = preg_replace('/\bCO\.?\b/iu', ' CO ', $value) ?? $value;
        $value = str_replace(['&', '+'], ' ', $value);
        $value = preg_replace('/\bAND\b/iu', ' ', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N}\s]/iu', ' ', $value) ?? $value;
        $value = mb_strtoupper((string) $this->collapseWhitespace($value));

        return $value !== '' ? $value : null;
    }

    /** Sales-list Auto Match city key (uppercase, strip punctuation, collapse spaces). */
    public function salesCityName(?string $value): ?string
    {
        $value = $this->collapseWhitespace($value);
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[^\p{L}\p{N}\s]/iu', ' ', $value) ?? $value;
        $value = mb_strtoupper((string) $this->collapseWhitespace($value));

        return $value !== '' ? $value : null;
    }

    public function state(?string $value): ?string
    {
        return $this->city($value);
    }

    /**
     * @return array{raw: ?string, normalized: ?string}
     */
    public function pair(?string $raw, string $type): array
    {
        $normalized = match ($type) {
            'firm_name' => $this->firmName($raw),
            'ca_name' => $this->caName($raw),
            'frn' => $this->frn($raw),
            'membership_number' => $this->membershipNumber($raw),
            'gst' => $this->gst($raw),
            'pan' => $this->pan($raw),
            'phone' => $this->phone($raw),
            'email' => $this->email($raw),
            'postal_code' => $this->postalCode($raw),
            'city' => $this->city($raw),
            'state' => $this->state($raw),
            default => $this->collapseWhitespace($raw),
        };

        return ['raw' => $this->collapseWhitespace($raw), 'normalized' => $normalized];
    }

    private function collapseWhitespace(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value !== '' ? $value : null;
    }
}
