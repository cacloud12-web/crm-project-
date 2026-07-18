<?php

namespace App\Services\Ocr;

/**
 * Production proprietor / partnership rules.
 *
 * Proprietor: exactly one CA, zero partner records, never infer partners.
 * Partnership: partners only from explicit partner evidence OR 2+ verified persons.
 * Never treat address / locality lines as partners.
 */
class OcrFirmRoleResolverService
{
    public const ROLE_PROPRIETOR = 'Proprietor';

    public const ROLE_PARTNER = 'Partner';

    public const FIRM_PROPRIETORSHIP = 'Proprietorship';

    public const FIRM_PARTNERSHIP = 'Partnership';

    /**
     * @param  list<array{name: string, token?: array, text?: string, membership_no?: ?string}>  $verifiedPersons
     * @return array{
     *     firm_type: ?string,
     *     ca_name: ?string,
     *     ca_role: ?string,
     *     members: list<array<string, mixed>>,
     *     rejected_as_address: list<string>,
     *     explicit_partnership: bool,
     *     role_violation: bool
     * }
     */
    public function resolve(
        ?string $firmName,
        array $verifiedPersons,
        bool $explicitPartnershipEvidence,
        ?OcrEntityClassificationService $classifier = null,
    ): array {
        $entities = $classifier ?? new OcrEntityClassificationService;
        $rejectedAsAddress = [];
        $persons = [];
        $seen = [];
        foreach ($verifiedPersons as $p) {
            $name = trim((string) ($p['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if ($entities->isAddress($name) || ! $entities->isPerson($name)) {
                $rejectedAsAddress[] = $name;
                continue;
            }
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $persons[] = $p + ['name' => $name];
        }

        $caName = $persons[0]['name'] ?? null;
        // Partnership only with explicit evidence OR 2+ verified real persons (never address noise).
        $explicitPartnership = $explicitPartnershipEvidence || count($persons) >= 2;

        if (! $explicitPartnership) {
            return [
                'firm_type' => $this->resolveFirmTypeLabel($firmName, false),
                'ca_name' => $caName,
                'ca_role' => $caName !== null ? self::ROLE_PROPRIETOR : null,
                'members' => [],
                'rejected_as_address' => $rejectedAsAddress,
                'explicit_partnership' => false,
                'role_violation' => false,
            ];
        }

        $members = [];
        foreach ($persons as $p) {
            $members[] = [
                'sequence_no' => count($members) + 1,
                'ca_name' => $p['name'],
                'membership_no' => $p['membership_no'] ?? null,
                'mobile' => null,
                'email' => null,
                'role' => self::ROLE_PARTNER,
                'overall_confidence' => 0.84,
                'field_meta' => null,
            ];
        }

        return [
            'firm_type' => self::FIRM_PARTNERSHIP,
            'ca_name' => $caName,
            'ca_role' => $caName !== null ? self::ROLE_PARTNER : null,
            'members' => $members,
            'rejected_as_address' => $rejectedAsAddress,
            'explicit_partnership' => true,
            'role_violation' => false,
        ];
    }

    /**
     * Proprietor firm cannot have partner records — enforce after any downstream mutation.
     *
     * @param  list<array<string, mixed>>  $members
     * @return list<array<string, mixed>>
     */
    public function enforceProprietorNoPartners(?string $firmType, ?string $caRole, array $members): array
    {
        if ($firmType === self::FIRM_PROPRIETORSHIP || $caRole === self::ROLE_PROPRIETOR) {
            return [];
        }
        if ($firmType !== self::FIRM_PARTNERSHIP && count($members) <= 1) {
            return [];
        }

        return array_values(array_filter($members, static function (array $m) {
            $name = trim((string) ($m['ca_name'] ?? ''));

            return $name !== '';
        }));
    }

    private function resolveFirmTypeLabel(?string $firmName, bool $explicitPartnership): ?string
    {
        if ($explicitPartnership) {
            return self::FIRM_PARTNERSHIP;
        }
        $lower = mb_strtolower((string) $firmName);
        if (str_contains($lower, 'llp')) {
            return 'LLP';
        }
        if (str_contains($lower, 'pvt') || str_contains($lower, 'private limited')) {
            return 'Private Limited';
        }

        return self::FIRM_PROPRIETORSHIP;
    }
}
