<?php

namespace App\Support\DocumentAi;

use Google\Cloud\DocumentAI\V1\Document\TextAnchor;

final class TextAnchorHelper
{
    public static function extract(?string $fullText, ?TextAnchor $anchor): string
    {
        if ($fullText === null || $fullText === '' || $anchor === null) {
            return '';
        }

        $segments = iterator_to_array($anchor->getTextSegments());
        if ($segments === []) {
            return '';
        }

        $parts = [];
        foreach ($segments as $segment) {
            $start = (int) ($segment->getStartIndex() ?? 0);
            $end = (int) $segment->getEndIndex();
            if ($end <= $start) {
                continue;
            }

            $parts[] = mb_substr($fullText, $start, $end - $start);
        }

        return trim(implode('', $parts));
    }
}
