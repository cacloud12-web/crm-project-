<?php

namespace App\Services\Ocr;

use Illuminate\Http\UploadedFile;

/**
 * Routes imports away from OCR when structured sources are available.
 * Excel/CSV must never be converted to PDF for OCR.
 */
class OcrImportRouterService
{
    public const ROUTE_STRUCTURED_BULK = 'structured_bulk';

    public const ROUTE_NATIVE_PDF_TEXT = 'native_pdf_text';

    public const ROUTE_DOCUMENT_AI_OCR = 'document_ai_ocr';

    public const ROUTE_REJECTED = 'rejected';

    /**
     * @return array{route: string, reason: string, bypass_ocr: bool}
     */
    public function classify(UploadedFile $file): array
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $mime = strtolower((string) ($file->getMimeType() ?? ''));

        if (in_array($ext, ['csv', 'xlsx', 'xls'], true)
            || str_contains($mime, 'csv')
            || str_contains($mime, 'spreadsheet')
            || str_contains($mime, 'excel')) {
            return [
                'route' => self::ROUTE_STRUCTURED_BULK,
                'reason' => 'Excel/CSV must use structured bulk import — OCR is disabled for spreadsheets.',
                'bypass_ocr' => true,
            ];
        }

        if (in_array($ext, ['pdf'], true) || str_contains($mime, 'pdf')) {
            return [
                'route' => self::ROUTE_DOCUMENT_AI_OCR,
                'reason' => 'PDF routed to Document AI (native text extraction preferred inside processor when available).',
                'bypass_ocr' => false,
            ];
        }

        if (in_array($ext, ['png', 'jpg', 'jpeg', 'tif', 'tiff', 'webp'], true) || str_starts_with($mime, 'image/')) {
            return [
                'route' => self::ROUTE_DOCUMENT_AI_OCR,
                'reason' => 'Scanned image routed to Google Document AI OCR.',
                'bypass_ocr' => false,
            ];
        }

        return [
            'route' => self::ROUTE_REJECTED,
            'reason' => 'Unsupported file type for OCR import.',
            'bypass_ocr' => true,
        ];
    }
}
