<?php

namespace App\Services\Leads\DuplicateDetection;

use App\Models\CaMaster;
use App\Repositories\Leads\LeadPhoneNumberRepository;
use App\Services\Leads\PhoneNormalizationService;

class PhoneDuplicateStrategy
{
    public function __construct(
        private readonly PhoneNormalizationService $phoneNormalization,
        private readonly LeadPhoneNumberRepository $phoneNumbers,
    ) {}

    public function key(): string
    {
        return 'phone';
    }

    public function normalize(mixed $value): ?string
    {
        return $this->phoneNormalization->normalize($value);
    }

    public function findDuplicate(mixed $value, ?int $excludeCaId = null): ?CaMaster
    {
        $normalized = $this->normalize($value);

        if (! $normalized) {
            return null;
        }

        return $this->phoneNumbers->findLeadByNormalizedNumber($normalized, $excludeCaId);
    }

    /**
     * @return array{normalized: ?string, duplicate: ?CaMaster}
     */
    public function inspect(mixed $value, ?int $excludeCaId = null): array
    {
        $normalized = $this->normalize($value);

        if (! $normalized) {
            return ['normalized' => null, 'duplicate' => null];
        }

        return [
            'normalized' => $normalized,
            'duplicate' => $this->phoneNumbers->findLeadByNormalizedNumber($normalized, $excludeCaId),
        ];
    }
}
