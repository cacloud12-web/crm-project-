<?php

namespace App\Exceptions\Ocr;

use RuntimeException;
use Throwable;

class OcrConfigurationException extends RuntimeException
{
    public function __construct(
        string $message = 'The document could not be processed. Please verify the OCR configuration or retry.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
