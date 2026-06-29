<?php

namespace App\Rules;

use App\Services\Employee\EmployeeCredentialService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AssignableCrmRole implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $roles = app(EmployeeCredentialService::class)->assignableRoles(auth()->user());

        if ($roles === []) {
            $fail('You do not have permission to assign CRM login roles.');

            return;
        }

        if (! in_array((string) $value, $roles, true)) {
            $fail('You cannot assign the selected CRM role.');
        }
    }
}
