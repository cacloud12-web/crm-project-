<?php

namespace App\Services\Ocr;

use App\Models\OcrDocument;

/**
 * Detects ICAI directory layout profile for a document.
 *
 * PARTNERSHIP_DIRECTORY — multi-person firm blocks (PART PDFs).
 * PROPRIETOR_DIRECTORY — sole-CA firm blocks (PROP PDFs).
 *
 * Filename hints are secondary; content heuristics are primary.
 */
class OcrDirectoryProfileDetector
{
    public const PARTNERSHIP = 'partnership_directory';

    public const PROPRIETOR = 'proprietor_directory';

    public function __construct(
        private readonly ?OcrEntityClassificationService $classifier = null,
    ) {}

    public function detect(OcrDocument $document, ?array $structuredData = null, ?string $text = null): string
    {
        $structured = $structuredData ?? (is_array($document->structured_data) ? $document->structured_data : []);
        $existing = (string) ($structured['directory_profile'] ?? '');
        if (in_array($existing, [self::PARTNERSHIP, self::PROPRIETOR], true)) {
            return $existing;
        }

        $hint = $this->filenameHint((string) $document->original_filename);
        $content = $this->contentHint($structured, $text ?? (string) ($document->extracted_text ?? ''));

        if ($content === self::PARTNERSHIP || ($content === null && $hint === self::PARTNERSHIP)) {
            return self::PARTNERSHIP;
        }
        if ($content === self::PROPRIETOR || ($content === null && $hint === self::PROPRIETOR)) {
            return self::PROPRIETOR;
        }

        return $hint ?? self::PROPRIETOR;
    }

    public function isPartnership(OcrDocument $document, ?array $structuredData = null): bool
    {
        return $this->detect($document, $structuredData) === self::PARTNERSHIP;
    }

    private function filenameHint(string $filename): ?string
    {
        $base = mb_strtolower(pathinfo($filename, PATHINFO_FILENAME));
        if ($base === '') {
            return null;
        }
        // westpart / southpart / centralpart — ends with "part" (not westprop).
        if ((str_ends_with($base, 'part') || preg_match('/(^|[^a-z])part([^a-z]|$)/u', $base))
            && ! str_contains($base, 'prop')) {
            return self::PARTNERSHIP;
        }
        if (str_contains($base, 'prop')) {
            return self::PROPRIETOR;
        }

        return null;
    }

    /**
     * Sample early OCR text: multi-person density under firm markers ⇒ partnership.
     */
    private function contentHint(array $structured, string $text): ?string
    {
        $sample = $this->sampleText($structured, $text);
        if (trim($sample) === '') {
            return null;
        }

        $entities = $this->classifier ?? new OcrEntityClassificationService;
        $lines = preg_split('/\R+/u', $sample) ?: [];
        $firmBlocks = 0;
        $multiPersonBlocks = 0;
        $personsInBlock = 0;
        $inBlock = false;

        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '' || mb_strlen($t) > 120) {
                continue;
            }
            if ($entities->isFirmName($t)) {
                if ($inBlock) {
                    $firmBlocks++;
                    if ($personsInBlock >= 2) {
                        $multiPersonBlocks++;
                    }
                }
                $inBlock = true;
                $personsInBlock = 0;
                continue;
            }
            if (! $inBlock) {
                continue;
            }
            if ($entities->isAddressShape($t) || $entities->isAddress($t)) {
                $firmBlocks++;
                if ($personsInBlock >= 2) {
                    $multiPersonBlocks++;
                }
                $inBlock = false;
                $personsInBlock = 0;
                continue;
            }
            if ($entities->isPerson($t) && ! $entities->isFirmName($t) && ! $entities->isCity($t)) {
                $personsInBlock++;
            }
        }
        if ($inBlock) {
            $firmBlocks++;
            if ($personsInBlock >= 2) {
                $multiPersonBlocks++;
            }
        }

        if ($firmBlocks < 3) {
            return null;
        }
        $ratio = $multiPersonBlocks / max(1, $firmBlocks);
        if ($ratio >= 0.35) {
            return self::PARTNERSHIP;
        }
        if ($ratio <= 0.1) {
            return self::PROPRIETOR;
        }

        return null;
    }

    private function sampleText(array $structured, string $text): string
    {
        if (trim($text) !== '') {
            return mb_substr($text, 0, 80000);
        }
        $chunks = [];
        foreach (array_slice($structured['pages'] ?? [], 0, 8) as $page) {
            foreach (array_slice($page['paragraphs'] ?? [], 0, 80) as $para) {
                $t = trim((string) ($para['text'] ?? ''));
                if ($t !== '') {
                    $chunks[] = $t;
                }
            }
        }

        return implode("\n", $chunks);
    }
}
