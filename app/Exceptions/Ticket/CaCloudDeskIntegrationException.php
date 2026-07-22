<?php

namespace App\Exceptions\Ticket;

use RuntimeException;
use Throwable;

class CaCloudDeskIntegrationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 500,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
