<?php

namespace App\Exceptions\Ocr;

use Throwable;

class OcrFileException extends OcrProviderException
{
    public function __construct(
        string $message = 'The uploaded document could not be read.',
        string $errorCode = 'file_error',
        bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $retryable, 0, $previous);
    }
}
