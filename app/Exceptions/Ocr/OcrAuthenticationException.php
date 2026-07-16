<?php

namespace App\Exceptions\Ocr;

use Throwable;

class OcrAuthenticationException extends OcrProviderException
{
    public function __construct(
        string $message = 'The document could not be processed. Please verify the OCR configuration or retry.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 'authentication_failed', false, 0, $previous);
    }
}
