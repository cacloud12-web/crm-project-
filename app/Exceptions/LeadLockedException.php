<?php

namespace App\Exceptions;

use Exception;

class LeadLockedException extends Exception
{
    /**
     * @param  array<string, mixed>  $lockInfo
     */
    public function __construct(
        string $message,
        private readonly array $lockInfo = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function lockInfo(): array
    {
        return $this->lockInfo;
    }
}
