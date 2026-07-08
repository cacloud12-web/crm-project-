<?php

namespace App\Services\Leads;

use Illuminate\Validation\ValidationException;

class IndianMobileValidationService
{
  private const MESSAGE = 'Invalid mobile number. Please enter a valid Indian mobile number.';

  /** @var list<string> */
  private const FAKE_NUMBERS = [
    '0000000000', '1111111111', '2222222222', '3333333333', '4444444444',
    '5555555555', '6666666666', '7777777777', '8888888888', '9999999999',
    '1234567890', '0123456789',
  ];

  public function message(): string
  {
    return self::MESSAGE;
  }

  public function isValid(mixed $value): bool
  {
    return $this->validate($value) === null;
  }

  public function assertValid(mixed $value, string $attribute = 'mobile_no'): void
  {
    $error = $this->validate($value);
    if ($error !== null) {
      throw ValidationException::withMessages([$attribute => [$error]]);
    }
  }

  public function validate(mixed $value): ?string
  {
    if ($value === null || trim((string) $value) === '') {
      return null;
    }

    $digits = app(PhoneNormalizationService::class)->normalize($value);

    if ($digits === null || strlen($digits) !== 10) {
      return self::MESSAGE;
    }

    if (! preg_match('/^[6-9]\d{9}$/', $digits)) {
      return self::MESSAGE;
    }

    if (in_array($digits, self::FAKE_NUMBERS, true)) {
      return self::MESSAGE;
    }

    if ($this->isRepeatingDigit($digits)) {
      return self::MESSAGE;
    }

    return null;
  }

  private function isRepeatingDigit(string $digits): bool
  {
    return count(array_unique(str_split($digits))) === 1;
  }
}
