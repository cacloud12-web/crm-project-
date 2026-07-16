<?php

namespace App\Exceptions\Ocr;

use Throwable;

class OcrPermissionException extends OcrProviderException
{
    public function __construct(
        string $message = 'The document could not be processed. Please verify the OCR configuration or retry.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 'permission_denied', false, 0, $previous);
    }
}
