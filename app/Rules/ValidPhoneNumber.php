<?php

namespace App\Rules;

use App\Services\Leads\PhoneClassificationService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidPhoneNumber implements ValidationRule
{
    public function __construct(
        private readonly bool $required = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || trim((string) $value) === '') {
            if ($this->required) {
                $fail('Phone number is required.');
            }

            return;
        }

        $error = app(PhoneClassificationService::class)->validateForSave($value, $attribute);

        if ($error !== null) {
            $fail($error);
        }
    }
}
