<?php

namespace App\Exceptions\Ocr;

use RuntimeException;
use Throwable;

class OcrProviderException extends RuntimeException
{
    public function __construct(
        string $message = 'The document could not be processed. Please verify the OCR configuration or retry.',
        public readonly string $errorCode = 'provider_error',
        public readonly bool $retryable = true,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
