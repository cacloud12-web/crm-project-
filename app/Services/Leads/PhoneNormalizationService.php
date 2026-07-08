<?php

namespace App\Services\Leads;

class PhoneNormalizationService
{
    public function normalize(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) > 10 && str_starts_with($digits, (string) config('crm_duplicates.phone.country_code', '91'))) {
            $digits = substr($digits, -10);
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = substr($digits, 1);
        }

        $minDigits = (int) config('crm_duplicates.phone.min_digits', 10);

        return strlen($digits) >= $minDigits ? $digits : null;
    }

    /**
     * @return list<string>
     */
    public function normalizeMany(mixed ...$values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $number = $this->normalize($value);
            if ($number !== null) {
                $normalized[] = $number;
            }
        }

        return array_values(array_unique($normalized));
    }
}
