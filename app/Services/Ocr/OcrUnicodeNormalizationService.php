<?php

namespace App\Services\Ocr;

/**
 * Safe Unicode NFKC + OCR confusable (Greek/Cyrillic look-alike) normalization.
 *
 * Used only for classification / matching. Raw Document AI text is never overwritten.
 */
class OcrUnicodeNormalizationService
{
    /**
     * Homoglyph map: Greek / Cyrillic capital letters that OCR often substitutes for Latin.
     *
     * @var array<string, string>
     */
    private const CONFUSABLES = [
        // Greek → Latin
        'Α' => 'A', 'Β' => 'B', 'Ε' => 'E', 'Ζ' => 'Z', 'Η' => 'H', 'Ι' => 'I',
        'Κ' => 'K', 'Μ' => 'M', 'Ν' => 'N', 'Ο' => 'O', 'Ρ' => 'P', 'Τ' => 'T',
        'Υ' => 'Y', 'Χ' => 'X',
        'α' => 'a', 'β' => 'b', 'ε' => 'e', 'ι' => 'i', 'κ' => 'k', 'ν' => 'n',
        'ο' => 'o', 'ρ' => 'p', 'τ' => 't', 'υ' => 'y', 'χ' => 'x',
        // Cyrillic → Latin
        'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H',
        'О' => 'O', 'Р' => 'P', 'С' => 'C', 'Т' => 'T', 'Х' => 'X', 'У' => 'Y',
        'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'х' => 'x',
        'у' => 'y',
    ];

    /**
     * @return array{
     *     raw_value: string,
     *     classification_value: string,
     *     unicode_normalized: bool,
     *     confusable_replaced: bool,
     *     replacements: list<array{from: string, to: string}>,
     *     unicode_confidence: float,
     *     reason: string|null
     * }
     */
    public function normalizeForClassification(string $raw): array
    {
        $rawValue = $raw;
        $value = $this->basicNormalize($raw);
        $replacements = [];
        $confusableReplaced = false;

        if ($this->shouldApplyConfusables($value)) {
            $mapped = $this->replaceConfusables($value);
            if ($mapped['value'] !== $value) {
                $value = $mapped['value'];
                $replacements = $mapped['replacements'];
                $confusableReplaced = true;
            }
        }

        $unicodeNormalized = $value !== $this->collapseWhitespace($rawValue);
        $reason = null;
        $confidence = 1.0;
        if ($confusableReplaced) {
            $reason = 'unicode_confusable_normalized';
            $confidence = max(0.82, 1.0 - (0.03 * count($replacements)));
        } elseif ($unicodeNormalized) {
            $reason = 'unicode_nfkc_normalized';
            $confidence = 0.97;
        }

        return [
            'raw_value' => $rawValue,
            'classification_value' => $value,
            'unicode_normalized' => $unicodeNormalized || $confusableReplaced,
            'confusable_replaced' => $confusableReplaced,
            'replacements' => $replacements,
            'unicode_confidence' => round($confidence, 4),
            'reason' => $reason,
        ];
    }

    public function classificationValue(string $raw): string
    {
        return $this->normalizeForClassification($raw)['classification_value'];
    }

    private function basicNormalize(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);
            if (is_string($normalized) && $normalized !== '') {
                $text = $normalized;
            }
        }

        // Zero-width / BOM / soft hyphen
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00AD}]/u', '', $text) ?? $text;
        // Non-breaking and exotic spaces → regular space
        $text = preg_replace('/[\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u', ' ', $text) ?? $text;
        // Smart quotes / apostrophes
        $text = str_replace(["\u{2018}", "\u{2019}", "\u{201A}", "\u{2032}", '`'], "'", $text);
        $text = str_replace(["\u{201C}", "\u{201D}", "\u{201E}"], '"', $text);
        // Unusual hyphens / dashes
        $text = str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}"], '-', $text);

        return $this->collapseWhitespace($text);
    }

    private function collapseWhitespace(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function shouldApplyConfusables(string $text): bool
    {
        if ($text === '') {
            return false;
        }
        // Never invent Latin out of purely numeric / identifier tokens.
        if (preg_match('/^\d[\d\s\-\/A-Za-z]*$/u', $text) && preg_match('/\d/', $text)) {
            // Allow only if mixed letters with confusables (not bare numbers).
            if (! preg_match('/\p{L}/u', $text)) {
                return false;
            }
        }

        $latin = preg_match_all('/\p{Latin}/u', $text) ?: 0;
        $greek = preg_match_all('/\p{Greek}/u', $text) ?: 0;
        $cyrillic = preg_match_all('/\p{Cyrillic}/u', $text) ?: 0;
        $letters = $latin + $greek + $cyrillic;
        if ($letters < 2) {
            return false;
        }
        if ($greek === 0 && $cyrillic === 0) {
            return false;
        }
        // Pure non-Latin personal names stay as-is. Homoglyph firm titles include Latin markers ("& CO").
        if ($latin === 0) {
            return (bool) preg_match('/(?:&|\band\b|\bco\b|associates|llp|company|sons)/iu', $text);
        }
        // Mixed lines: Latin markers with Greek/Cyrillic name glyphs (ΜΚΑΡ & CO).
        if (($greek + $cyrillic) >= 2) {
            return true;
        }

        return ($latin / $letters) >= 0.45;
    }

    /**
     * @return array{value: string, replacements: list<array{from: string, to: string}>}
     */
    private function replaceConfusables(string $text): array
    {
        $replacements = [];
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = '';
        foreach ($chars as $ch) {
            if (isset(self::CONFUSABLES[$ch])) {
                $to = self::CONFUSABLES[$ch];
                $replacements[] = ['from' => $ch, 'to' => $to];
                $out .= $to;
            } else {
                $out .= $ch;
            }
        }

        return ['value' => $out, 'replacements' => $replacements];
    }
}
