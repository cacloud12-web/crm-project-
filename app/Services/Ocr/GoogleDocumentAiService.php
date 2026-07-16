<?php

namespace App\Services\Ocr;

use App\Contracts\Ocr\OcrProcessorInterface;
use App\Services\DocumentAi\GoogleDocumentAiService as BaseGoogleDocumentAiService;

/**
 * Application OCR provider adapter (Document AI).
 * Keeps DocumentAi namespace implementation reusable while exposing the Ocr contract.
 */
class GoogleDocumentAiService extends BaseGoogleDocumentAiService implements OcrProcessorInterface
{
}
