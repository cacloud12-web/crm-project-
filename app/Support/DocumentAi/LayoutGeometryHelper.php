<?php

namespace App\Support\DocumentAi;

/**
 * Extracts bounding boxes / confidence from Document AI layout objects.
 */
final class LayoutGeometryHelper
{
    /**
     * @return array{vertices: list<array{x: float, y: float}>, confidence: float|null}|null
     */
    public static function fromLayout(?object $layout): ?array
    {
        if ($layout === null) {
            return null;
        }

        $confidence = null;
        try {
            if (method_exists($layout, 'getConfidence') && $layout->getConfidence() !== null) {
                $confidence = round((float) $layout->getConfidence(), 4);
            }
        } catch (\Throwable) {
            $confidence = null;
        }

        $vertices = [];
        try {
            $poly = method_exists($layout, 'getBoundingPoly') ? $layout->getBoundingPoly() : null;
            if ($poly && method_exists($poly, 'getNormalizedVertices')) {
                foreach ($poly->getNormalizedVertices() as $vertex) {
                    $vertices[] = [
                        'x' => round((float) ($vertex->getX() ?? 0), 4),
                        'y' => round((float) ($vertex->getY() ?? 0), 4),
                    ];
                }
            }
            if ($vertices === [] && $poly && method_exists($poly, 'getVertices')) {
                foreach ($poly->getVertices() as $vertex) {
                    $vertices[] = [
                        'x' => (float) ($vertex->getX() ?? 0),
                        'y' => (float) ($vertex->getY() ?? 0),
                    ];
                }
            }
        } catch (\Throwable) {
            $vertices = [];
        }

        if ($vertices === [] && $confidence === null) {
            return null;
        }

        return [
            'vertices' => $vertices,
            'confidence' => $confidence,
        ];
    }

    /**
     * @param  list<array{x: float, y: float}>  $vertices
     * @return array{x_min: float, x_max: float, y_min: float, y_max: float, x_center: float, y_center: float, width: float, height: float}|null
     */
    public static function bboxFromVertices(array $vertices): ?array
    {
        if ($vertices === []) {
            return null;
        }
        $xs = array_map(static fn (array $v) => (float) ($v['x'] ?? 0), $vertices);
        $ys = array_map(static fn (array $v) => (float) ($v['y'] ?? 0), $vertices);
        $xMin = min($xs);
        $xMax = max($xs);
        $yMin = min($ys);
        $yMax = max($ys);

        return [
            'x_min' => round($xMin, 4),
            'x_max' => round($xMax, 4),
            'y_min' => round($yMin, 4),
            'y_max' => round($yMax, 4),
            'x_center' => round(($xMin + $xMax) / 2, 4),
            'y_center' => round(($yMin + $yMax) / 2, 4),
            'width' => round(max(0.0, $xMax - $xMin), 4),
            'height' => round(max(0.0, $yMax - $yMin), 4),
        ];
    }

    /**
     * @param  list<array{x_min?: float, x_max?: float, y_min?: float, y_max?: float}>  $boxes
     * @return array{x_min: float, x_max: float, y_min: float, y_max: float}|null
     */
    public static function mergeBboxes(array $boxes): ?array
    {
        $valid = array_values(array_filter($boxes, static fn ($b) => is_array($b) && isset($b['x_min'], $b['x_max'], $b['y_min'], $b['y_max'])));
        if ($valid === []) {
            return null;
        }

        return [
            'x_min' => round(min(array_column($valid, 'x_min')), 4),
            'x_max' => round(max(array_column($valid, 'x_max')), 4),
            'y_min' => round(min(array_column($valid, 'y_min')), 4),
            'y_max' => round(max(array_column($valid, 'y_max')), 4),
        ];
    }
}
