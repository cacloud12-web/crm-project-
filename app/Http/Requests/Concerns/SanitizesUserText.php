<?php

namespace App\Http\Requests\Concerns;

use App\Support\Security\TextSanitizer;

trait SanitizesUserText
{
    /**
     * @param  list<string>  $keys
     */
    protected function sanitizeTextFields(array $keys): void
    {
        $this->merge(TextSanitizer::sanitizeKeys($this->all(), $keys));
    }
}
