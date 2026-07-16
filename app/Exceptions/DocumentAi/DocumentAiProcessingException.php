<?php

namespace App\Exceptions\DocumentAi;

use App\Exceptions\Ocr\OcrProviderException;

class DocumentAiProcessingException extends OcrProviderException
{
    public function __construct(
        string $message,
        string $errorCode = 'processing_failed',
        bool $retryable = true,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $retryable, 0, $previous);
    }
}
