<?php

namespace App\Rules;

use App\Services\Leads\IndianMobileValidationService;
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

        $error = app(IndianMobileValidationService::class)->validate($value);

        if ($error !== null) {
            $fail($error);
        }
    }
}
