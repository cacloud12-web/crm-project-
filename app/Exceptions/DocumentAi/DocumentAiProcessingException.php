<?php

namespace App\Exceptions\DocumentAi;

use RuntimeException;

class DocumentAiProcessingException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'processing_failed',
        public readonly bool $retryable = true,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
