<?php

namespace App\Exceptions\Ticket;

use RuntimeException;
use Throwable;

class CaCloudDeskIntegrationNotConfiguredException extends RuntimeException
{
    public function __construct(
        string $message = 'CA Cloud Desk organization lookup is not configured yet.',
        public readonly int $httpStatus = 503,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
