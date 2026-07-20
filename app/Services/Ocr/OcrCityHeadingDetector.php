<?php

namespace App\Services\Ocr;

/**
 * Detects ICAI directory city section headings (ADIPUR, AHILYANAGAR, ABOHAR).
 * A heading is section context only — never Firm / CA / Partner.
 *
 * Acceptance requires resolver evidence (master / alias / directory list /
 * approved ROAD city / safe single-token place suffix). Capitalization alone is not enough.
 */
class OcrCityHeadingDetector
{
    public function __construct(
        private readonly ?OcrEntityClassificationService $classifier = null,
        private readonly ?OcrCityResolverService $resolver = null,
    ) {}

    /**
     * @param  array<string, mixed>  $token  Optional geometry: width, y_center, x_center
     * @return array{raw_city: string, city: string, normalized_city: string, confidence: float, evidence: string, city_match_type?: string}|null
     */
    public function detect(string $text, array $token = []): ?array
    {
        $raw = trim($text);
        if ($raw === '') {
            return null;
        }

        // Address lines embed city+PIN (AMBALA CITY-134003) — never section headings.
        if (preg_match('/[-–]\s*\d{5,6}[A-Z]?\s*$/u', $raw)) {
            return null;
        }

        $entities = $this->classifier ?? new OcrEntityClassificationService;
        $resolver = $this->resolver ?? new OcrCityResolverService;

        if ($entities->isFirmName($raw) || $entities->isPerson($raw)) {
            return null;
        }
        // Digits are membership/FRN/PIN noise — never city headings (incl. 24 PARAGANAS).
        if (preg_match('/\d/u', $raw)) {
            return null;
        }
        if ($this->looksLikeAddressNotHeading($raw)) {
            return null;
        }

        $resolved = $resolver->resolve($raw);
        if ($resolved === null) {
            return null;
        }

        // Multi-word * ROAD only when approved (ABU ROAD), never street lines.
        $words = preg_split('/\s+/u', $resolved['canonical_city']) ?: [];
        if (count($words) >= 2 && preg_match('/\broad\b/iu', $resolved['canonical_city'])
            && ($resolved['city_match_type'] ?? '') !== 'approved_road_city'
            && ($resolved['city_match_type'] ?? '') !== 'alias'
            && ($resolved['city_match_type'] ?? '') !== 'city_master') {
            return null;
        }

        // Address localities (KRISHNA NAGAR) without resolver acceptance already null.
        if (count($words) >= 2 && preg_match('/\b(?:nagar|colony|vihar|enclave|mohalla|chowk|crossing)\b/iu', $raw)
            && ! preg_match('/\b(?:ROAD|CITY|CANTT)$/i', $resolved['canonical_city'])
            && ! in_array($resolved['city_match_type'] ?? '', ['alias', 'alias_joined', 'city_master', 'directory_list'], true)) {
            return null;
        }

        return [
            'raw_city' => $resolved['raw_city_heading'],
            'city' => $resolved['canonical_city'],
            'normalized_city' => $resolved['normalized_city'],
            'confidence' => (float) $resolved['city_confidence'],
            'evidence' => (string) $resolved['city_match_type'],
            'city_match_type' => (string) $resolved['city_match_type'],
        ];
    }

    public function isHeading(string $text, array $token = []): bool
    {
        return $this->detect($text, $token) !== null;
    }

    private function looksLikeAddressNotHeading(string $text): bool
    {
        if (preg_match('/\b(?:street\s*no|house|h\.?\s*no|shop|floor|near|opp|behind|backside|hospital|sector\s*\d|ward\s*no|plot|building|school|market|mandi|society|apartment|complex|tower|plaza)\b/iu', $text)) {
            return true;
        }
        // Street-style * ROAD that is not an approved city — reject early.
        if (preg_match('/\broad\b/iu', $text)) {
            $resolver = $this->resolver ?? new OcrCityResolverService;
            $hit = $resolver->resolve($text);
            if ($hit === null) {
                return true;
            }
            $type = $hit['city_match_type'] ?? '';
            if (! in_array($type, ['approved_road_city', 'alias', 'alias_joined', 'city_master', 'directory_list'], true)) {
                return true;
            }
        }

        return false;
    }
}
