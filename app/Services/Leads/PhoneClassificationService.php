<?php

namespace App\Services\Leads;

class PhoneClassificationService
{
    public const TYPE_MOBILE = 'mobile';

    public const TYPE_LANDLINE = 'landline';

    /** @var list<string> */
    private const FAKE_MOBILES = [
        '0000000000', '1111111111', '2222222222', '3333333333', '4444444444',
        '5555555555', '6666666666', '7777777777', '8888888888', '9999999999',
        '1234567890', '0123456789',
    ];

    public function classify(mixed $value): ?string
    {
        $digits = $this->digitsOnly($value);

        if ($digits === null || $digits === '') {
            return null;
        }

        if ($this->isIndianMobile($digits)) {
            return self::TYPE_MOBILE;
        }

        if ($this->isIndianLandline($digits)) {
            return self::TYPE_LANDLINE;
        }

        return null;
    }

    public function isLandline(mixed $value): bool
    {
        return $this->classify($value) === self::TYPE_LANDLINE;
    }

    public function isMobile(mixed $value): bool
    {
        return $this->classify($value) === self::TYPE_MOBILE;
    }

    public function validateForSave(mixed $value, string $attribute = 'mobile_no'): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $type = $this->classify($value);

        if ($type === null) {
            return 'Enter a valid Indian mobile or landline number.';
        }

        if ($type === self::TYPE_MOBILE) {
            return app(IndianMobileValidationService::class)->validate($value);
        }

        return null;
    }

    public function assertValidForSave(mixed $value, string $attribute = 'mobile_no'): void
    {
        $error = $this->validateForSave($value, $attribute);

        if ($error !== null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                $attribute => [$error],
            ]);
        }
    }

    public function digitsOnly(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) > 10 && str_starts_with($digits, '91')) {
            $digits = substr($digits, -10);
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return $digits;
    }

    private function isIndianMobile(string $digits): bool
    {
        if (strlen($digits) !== 10) {
            return false;
        }

        if (! preg_match('/^[6-9]\d{9}$/', $digits)) {
            return false;
        }

        if (in_array($digits, self::FAKE_MOBILES, true)) {
            return false;
        }

        return count(array_unique(str_split($digits))) > 1;
    }

    private function isIndianLandline(string $digits): bool
    {
        $length = strlen($digits);

        if ($length < 8 || $length > 11) {
            return false;
        }

        if ($length === 10 && preg_match('/^[6-9]/', $digits)) {
            return false;
        }

        return true;
    }
}
