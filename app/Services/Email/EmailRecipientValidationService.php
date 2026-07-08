<?php

namespace App\Services\Email;

use Illuminate\Support\Facades\Validator;

class EmailRecipientValidationService
{
    public const STATUS_SENT = 'Sent';

    public const STATUS_FAILED = 'Failed';

    public const STATUS_INVALID_EMAIL = 'Invalid Email';

    public const STATUS_INVALID_DOMAIN = 'Invalid Domain';

    public const STATUS_DUPLICATE = 'Duplicate';

    public const STATUS_SKIPPED = 'Skipped';

    public const STATUS_QUEUED = 'Queued';

    public const STATUS_PROCESSING = 'Processing';

    public const STATUS_DELIVERED = 'Delivered';

    public const STATUS_REPLY_RECEIVED = 'Reply Received';

    public const STATUS_BOUNCED = 'Bounced';

    public const STATUS_SPAM = 'Spam';

  /** @var array<string, bool> */
    private array $mxCache = [];

    public static function normalize(?string $email): string
    {
        return strtolower(trim((string) $email));
    }

    /**
     * @param  array<string, bool>  $seenInCampaign  normalized email => true
     * @return array{valid: bool, status: string, reason: ?string, normalized: ?string}
     */
    public function validate(?string $email, bool $checkMx = true, array &$seenInCampaign = []): array
    {
        $normalized = self::normalize($email);

        if ($normalized === '') {
            return $this->invalid(self::STATUS_INVALID_EMAIL, 'Email address is blank or missing.');
        }

        if (isset($seenInCampaign[$normalized])) {
            return $this->invalid(self::STATUS_DUPLICATE, 'Duplicate email address in this campaign.');
        }

        if (! $this->hasValidFormat($normalized)) {
            return $this->invalid(self::STATUS_INVALID_EMAIL, 'Email address format is invalid.');
        }

        $domain = $this->extractDomain($normalized);

        if ($domain === null) {
            return $this->invalid(self::STATUS_INVALID_EMAIL, 'Email domain is invalid.');
        }

        if ($this->isBlockedDomain($domain)) {
            return $this->invalid(
                self::STATUS_INVALID_EMAIL,
                'Email uses a blocked dummy or test domain ('.$domain.').',
            );
        }

        if ($checkMx && ! $this->domainHasMxRecords($domain)) {
            return $this->invalid(self::STATUS_INVALID_DOMAIN, 'Email domain has no valid MX records.');
        }

        $seenInCampaign[$normalized] = true;

        return [
            'valid' => true,
            'status' => self::STATUS_QUEUED,
            'reason' => null,
            'normalized' => $normalized,
        ];
    }

    public function hasValidFormat(string $email): bool
    {
        return Validator::make(
            ['email' => $email],
            ['email' => ['required', 'email:rfc,filter']],
        )->passes();
    }

    public function isBlockedDomain(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        $blocked = config('email_smtp.blocked_domains', []);

        if (in_array($domain, $blocked, true)) {
            return true;
        }

        foreach ($blocked as $blockedDomain) {
            if (str_ends_with($domain, '.'.$blockedDomain)) {
                return true;
            }
        }

        return false;
    }

    public function domainHasMxRecords(string $domain): bool
    {
        if (config('email_smtp.skip_mx_check', false)) {
            return true;
        }

        $domain = strtolower(trim($domain));

        if (isset($this->mxCache[$domain])) {
            return $this->mxCache[$domain];
        }

        $hasMx = checkdnsrr($domain, 'MX');

        if (! $hasMx) {
            $hasMx = checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA');
        }

        $this->mxCache[$domain] = $hasMx;

        return $hasMx;
    }

    public function extractDomain(string $email): ?string
    {
        $at = strrpos($email, '@');

        if ($at === false) {
            return null;
        }

        $domain = substr($email, $at + 1);

        return $domain !== '' ? strtolower($domain) : null;
    }

    /**
     * @return array{valid: false, status: string, reason: string, normalized: null}
     */
    private function invalid(string $status, string $reason): array
    {
        return [
            'valid' => false,
            'status' => $status,
            'reason' => $reason,
            'normalized' => null,
        ];
    }
}
