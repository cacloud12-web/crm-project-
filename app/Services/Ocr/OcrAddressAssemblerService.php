<?php

namespace App\Services\Ocr;

/**
 * Merges consecutive address lines until a non-address entity begins.
 * Preserves exact OCR text — no normalization in display value.
 */
class OcrAddressAssemblerService
{
    public function __construct(
        private readonly ?OcrEntityClassificationService $classifier = null,
    ) {}

    /**
     * @param  list<array{text: string, token?: array, classified?: array}>  $lines
     * @return array{address: ?string, parts: list<string>, metas: list<array>, pincode: ?string, state: ?string, city: ?string, stopped_at: ?int}
     */
    public function assemble(array $lines, int $startIndex = 0, ?string $sectionCity = null): array
    {
        $entities = $this->classifier ?? new OcrEntityClassificationService;
        $parts = [];
        $metas = [];
        $pincode = null;
        $state = null;
        $city = $sectionCity;
        $stoppedAt = null;

        for ($i = $startIndex, $n = count($lines); $i < $n; $i++) {
            $line = $lines[$i];
            $text = trim((string) ($line['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $classified = $line['classified'] ?? $entities->classify($text);
            $type = $classified['entity_type'] ?? OcrEntityClassificationService::UNKNOWN;

            if ($type === OcrEntityClassificationService::FIRM_NAME || $type === OcrEntityClassificationService::PERSON) {
                $stoppedAt = $i;
                break;
            }
            if ($type === OcrEntityClassificationService::SECTION_HEADING) {
                $stoppedAt = $i;
                break;
            }

            $extractor = new OcrIdentifierExtractorService;
            if ($gst = $extractor->extractGst($text)) {
                $stoppedAt = $i;
                break;
            }
            if ($extractor->extractPhone($text) || $extractor->extractEmail($text)) {
                $stoppedAt = $i;
                break;
            }

            if ($type === OcrEntityClassificationService::STATE) {
                $state = $text;
                continue;
            }

            if ($pin = $extractor->extractPincode($text, ['in_address_context' => $parts !== []])) {
                $pincode ??= $pin['value'];
                if (! empty($pin['locality'])) {
                    $parts[] = $pin['locality'];
                } elseif ($type === OcrEntityClassificationService::ADDRESS || $entities->isAddress($text)) {
                    $parts[] = preg_replace('/\b[1-9]\d{5}\b/', '', $text) ? trim(preg_replace('/\b[1-9]\d{5}\b/', '', $text) ?? '') : $text;
                }
                continue;
            }

            if ($type === OcrEntityClassificationService::ADDRESS
                || $type === OcrEntityClassificationService::CITY
                || $entities->isAddress($text)
                || ($parts !== [] && $type === OcrEntityClassificationService::UNKNOWN && ! $entities->isPerson($text))) {
                $parts[] = $text;
                if ($type === OcrEntityClassificationService::CITY && $city === null) {
                    $city = $text;
                }
                continue;
            }

            if ($parts !== []) {
                $stoppedAt = $i;
                break;
            }
        }

        $address = $parts !== [] ? implode(', ', $parts) : null;

        return compact('address', 'parts', 'metas', 'pincode', 'state', 'city', 'stoppedAt');
    }
}
