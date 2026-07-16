<?php

namespace App\Services\Ocr;

use Smalot\PdfParser\Parser;
use Throwable;

class PdfPageCounter
{
    /**
     * Count PDF pages using smalot/pdfparser, with a light regex fallback.
     */
    public function count(string $binary): ?int
    {
        if ($binary === '' || ! str_starts_with($binary, '%PDF')) {
            return null;
        }

        try {
            $pdf = (new Parser)->parseContent($binary);
            $pages = $pdf->getPages();
            $count = is_array($pages) ? count($pages) : 0;
            if ($count > 0) {
                return $count;
            }
        } catch (Throwable) {
            // Fall through to a lightweight heuristic.
        }

        return $this->heuristicPageCount($binary);
    }

    private function heuristicPageCount(string $binary): ?int
    {
        if (! preg_match_all('/\/Type\s*\/Page(?![s\w])/i', $binary, $matches)) {
            return null;
        }

        $count = count($matches[0]);

        return $count > 0 ? $count : null;
    }
}
