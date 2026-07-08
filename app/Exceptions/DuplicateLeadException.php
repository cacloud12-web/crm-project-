<?php

namespace App\Exceptions;

use Exception;

class DuplicateLeadException extends Exception
{
    /**
     * @param  array<string, mixed>  $duplicateInfo
     */
    public function __construct(
        string $message,
        private readonly array $duplicateInfo = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function duplicateInfo(): array
    {
        return $this->duplicateInfo;
    }
}
