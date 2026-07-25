<?php

namespace App\Services\Ocr;

/**
 * Resolve city for a firm row from staging, source_data, and page section headings.
 * Never invents cities. Forward-fill is page+column scoped when column known.
 */
class OcrCityContextResolver
{
    public function __construct(
        private readonly ?OcrEntityClassificationService $entities = null,
        private readonly ?OcrCityHeadingDetector $headings = null,
        private readonly ?OcrCityResolverService $cities = null,
    ) {}

    /**
     * @param  list<string>  $pageParagraphs  reading-order texts for one page
     * @param  array{firm_name?: string, column?: int|null, y_center?: float|null}  $context
     * @return array{city: ?string, method: ?string, evidence: ?string, confidence: float}
     */
    public function resolveForFirm(
        ?string $stagingCity,
        array $sourceData,
        array $pageParagraphs,
        array $context = [],
    ): array {
        $staging = trim((string) ($stagingCity ?? ''));
        if ($staging !== '') {
            $canonical = ($this->cities ?? new OcrCityResolverService)->sanitizeCity($staging) ?? $staging;

            return [
                'city' => $canonical,
                'method' => 'source_data_field',
                'evidence' => 'staging_city',
                'confidence' => 0.95,
            ];
        }

        $fromSd = trim((string) (($sourceData['parsed']['city'] ?? '') ?: ($sourceData['raw']['city'] ?? '')));
        if ($fromSd !== '') {
            $canonical = ($this->cities ?? new OcrCityResolverService)->sanitizeCity($fromSd) ?? $fromSd;

            return [
                'city' => $canonical,
                'method' => 'source_data_field',
                'evidence' => $fromSd,
                'confidence' => 0.9,
            ];
        }

        $entities = $this->entities ?? new OcrEntityClassificationService;
        $heading = $this->headings ?? new OcrCityHeadingDetector($entities);
        $firmName = trim((string) ($context['firm_name'] ?? ''));
        $firmIdx = null;
        $fu = mb_strtoupper($firmName);
        foreach ($pageParagraphs as $i => $t) {
            $u = mb_strtoupper(trim((string) $t));
            if ($fu !== '' && ($u === $fu || str_contains($u, $fu))) {
                $firmIdx = $i;
                break;
            }
        }

        $lastHeading = null;
        $lastHeadingText = null;
        $end = $firmIdx ?? (count($pageParagraphs) - 1);
        for ($i = 0; $i <= $end; $i++) {
            $t = trim((string) ($pageParagraphs[$i] ?? ''));
            if ($t === '') {
                continue;
            }
            if ($heading->isHeading($t) || $entities->isCity($t)) {
                if (! preg_match('/(?:ASSOCIATES|COMPANY|\bCO\b|LLP|&)/iu', $t)) {
                    $san = ($this->cities ?? new OcrCityResolverService)->sanitizeCity($t);
                    if ($san !== null && $san !== '') {
                        $lastHeading = $san;
                        $lastHeadingText = $t;
                    } elseif ($entities->isCity($t)) {
                        $lastHeading = $t;
                        $lastHeadingText = $t;
                    }
                }
            }
        }

        if ($lastHeading !== null) {
            return [
                'city' => $lastHeading,
                'method' => 'city_heading_forward_fill',
                'evidence' => $lastHeadingText,
                'confidence' => 0.82,
            ];
        }

        return [
            'city' => null,
            'method' => null,
            'evidence' => null,
            'confidence' => 0.0,
        ];
    }
}
