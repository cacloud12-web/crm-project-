<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidMobileNumber implements ValidationRule
{
    public function __construct(
        private readonly bool $required = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || trim((string) $value) === '') {
            if ($this->required) {
                $fail('Mobile number is required.');
            }

            return;
        }

        $digits = preg_replace('/\D/', '', (string) $value) ?? '';
        if (strlen($digits) > 10 && str_starts_with($digits, '91')) {
            $digits = substr($digits, -10);
        }

        if (strlen($digits) < 10) {
            $fail('Mobile number must be at least 10 digits.');
        }
    }
}
