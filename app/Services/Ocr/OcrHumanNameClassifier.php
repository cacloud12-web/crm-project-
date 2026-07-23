<?php

namespace App\Services\Ocr;

/**
 * Strict human-name gate for partnership ca_name and partners.
 * Address / city / firm titles never pass.
 */
class OcrHumanNameClassifier
{
    public function __construct(
        private readonly ?OcrEntityClassificationService $entities = null,
        private readonly ?OcrCityHeadingDetector $cityHeadings = null,
        private readonly ?OcrUnicodeNormalizationService $unicode = null,
    ) {}

    public function isValid(?string $text, ?string $firmName = null, ?string $city = null): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }
        $entities = $this->entities ?? new OcrEntityClassificationService;
        $unicode = $this->unicode ?? new OcrUnicodeNormalizationService;
        $text = $unicode->classificationValue(trim($text));
        if ($text === '' || preg_match('/\d/u', $text)) {
            return false;
        }
        if (mb_strlen($text) < 3 || mb_strlen($text) > 80) {
            return false;
        }
        if ($entities->isFirmName($text) || $entities->isAddressShape($text) || $entities->isAddress($text)) {
            return false;
        }
        if ($entities->isCity($text) || ($this->cityHeadings ?? new OcrCityHeadingDetector($entities))->isHeading($text)) {
            return false;
        }
        if ($city !== null && mb_strtolower($text) === mb_strtolower(trim($city))) {
            return false;
        }
        if ($firmName !== null && mb_strtolower($text) === mb_strtolower(trim($firmName))) {
            return false;
        }
        if (preg_match('/\b(?:road|street|floor|ward|plot|building|colony|market|mandi|hospital|school|society|near|opp|opposite|house|shop|sector|nagar|complex|tower|plaza|square|centre|center|tenament|tenement|business|chambers|comm|commercial|arcade|mall|highway|pin|frn|membership|associates|llp|&\s*co|and\s+co|company|chartered)\b/iu', $text)) {
            return false;
        }
        if (! preg_match('/^[A-Za-z][A-Za-z .\'\-]{1,78}$/u', $text)) {
            return false;
        }
        $words = preg_split('/\s+/u', $text) ?: [];
        if (count($words) < 1 || count($words) > 5) {
            return false;
        }

        return $entities->isPerson($text) || $this->looksLikePersonShape($text, $words);
    }

    /** @param  list<string>  $words */
    private function looksLikePersonShape(string $text, array $words): bool
    {
        if (count($words) === 1) {
            return mb_strlen($words[0]) >= 3 && mb_strlen($words[0]) <= 24;
        }
        foreach ($words as $word) {
            if (mb_strlen($word) < 2) {
                return false;
            }
        }

        return true;
    }
}
