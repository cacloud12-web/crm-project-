<?php

namespace App\Exceptions\DocumentAi;

use App\Exceptions\Ocr\OcrConfigurationException;
use Throwable;

class DocumentAiConfigurationException extends OcrConfigurationException
{
    public function __construct(
        string $message = 'Large-document processing is not configured. Please contact the administrator.',
        public readonly ?string $adminDetail = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function publicMessage(): string
    {
        return $this->getMessage();
    }

    public function detailForAdministrators(): string
    {
        return $this->adminDetail ?: $this->getMessage();
    }
}
