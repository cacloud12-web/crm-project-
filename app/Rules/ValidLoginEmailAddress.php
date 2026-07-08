<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidLoginEmailAddress implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isDeliverable((string) $value)) {
            $fail('Please enter a valid email address.');
        }
    }

    public static function isDeliverable(string $email): bool
    {
        $email = strtolower(trim($email));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $domain = substr(strrchr($email, '@') ?: '', 1);

        if ($domain === '' || ! str_contains($domain, '.')) {
            return false;
        }

        if (in_array($domain, config('login_email_change.blocked_domains', []), true)) {
            return false;
        }

        foreach (config('login_email_change.blocked_domain_suffixes', []) as $suffix) {
            $suffix = strtolower((string) $suffix);
            if ($suffix !== '' && str_ends_with($domain, $suffix)) {
                return false;
            }
        }

        return true;
    }
}
