<?php

namespace App\Exceptions\DocumentAi;

use RuntimeException;

class DocumentAiConfigurationException extends RuntimeException
{
    public function __construct(string $message = 'Google Document AI credentials are missing or invalid.')
    {
        parent::__construct($message);
    }
}
