<?php

namespace App\Services\Mapping;

use App\Models\CaAddress;
use App\Models\CaFirm;
use App\Models\CaMaster;
use App\Models\CaPartner;
use App\Services\Master\LookupResolverService;
use Illuminate\Support\Facades\Schema;

/**
 * Exact match on normalized firm_name + ca_name + city only.
 * Prefers official ca_reference (ca_firms/partners/addresses), then CaMaster.
 * No FRN/GST/PAN/phone/address/membership influence.
 */
class FirmCaCityMatchingProfile
{
    public const PROFILE = 'firm_ca_city';

    private static ?bool $caReferenceReady = null;

    private static ?bool $hasNormalizedCaName = null;

    /** @var array{firm?: bool, partner?: bool, city?: bool}|null */
    private static ?array $caReferenceNormColumns = null;

    public function __construct(
        private readonly DataNormalizationService $normalizer,
        private readonly LookupResolverService $lookups,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function match(array $payload): MatchResult
    {
        $firm = $this->normalizer->firmName($payload['firm_name'] ?? ($payload['normalized_firm_name'] ?? null));
        $ca = $this->normalizer->caName($payload['ca_name'] ?? ($payload['normalized_ca_name'] ?? null));
        $cityRaw = $payload['city'] ?? ($payload['raw_city'] ?? null);
        $cityNorm = $this->normalizer->city(is_string($cityRaw) ? $cityRaw : null);

        if ($firm === null || $firm === '' || $ca === null || $ca === '' || $cityNorm === null || $cityNorm === '') {
            return MatchResult::unmatched('missing_firm_ca_or_city');
        }

        $reference = $this->matchCaReference($firm, $ca, $cityNorm);
        if ($reference !== null) {
            return $reference;
        }

        return $this->matchCaMaster($firm, $ca, $cityRaw, $cityNorm);
    }

    private function caReferenceReady(): bool
    {
        if (self::$caReferenceReady !== null) {
            return self::$caReferenceReady;
        }

        try {
            self::$caReferenceReady = Schema::connection('ca_reference')->hasTable('ca_firms')
                && Schema::connection('ca_reference')->hasTable('ca_partners')
                && Schema::connection('ca_reference')->hasTable('ca_addresses');
        } catch (\Throwable) {
            self::$caReferenceReady = false;
        }

        return self::$caReferenceReady;
    }

    private function matchCaReference(string $firm, string $ca, string $cityNorm): ?MatchResult
    {
        if (! $this->caReferenceReady()) {
            return null;
        }

        if (self::$caReferenceNormColumns === null) {
            self::$caReferenceNormColumns = [
                'firm' => Schema::connection('ca_reference')->hasColumn('ca_firms', 'normalized_firm_name'),
                'partner' => Schema::connection('ca_reference')->hasColumn('ca_partners', 'normalized_partner_name'),
                'city' => Schema::connection('ca_reference')->hasColumn('ca_addresses', 'normalized_city'),
            ];
        }
        $hasNormFirm = self::$caReferenceNormColumns['firm'];
        $hasNormPartner = self::$caReferenceNormColumns['partner'];
        $hasNormCity = self::$caReferenceNormColumns['city'];

        $firmQuery = CaFirm::query()->select(['id', 'firm_name']);
        if ($hasNormFirm) {
            $firmQuery->where('normalized_firm_name', $firm);
        } else {
            $firmQuery->whereRaw('UPPER(TRIM(firm_name)) = ?', [mb_strtoupper($firm)]);
        }
        $firmIds = $firmQuery->limit(20)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($firmIds === []) {
            return null;
        }

        $partnerQuery = CaPartner::query()->whereIn('firm_id', $firmIds)->select(['id', 'firm_id', 'partner_name']);
        if ($hasNormPartner) {
            $partnerQuery->where('normalized_partner_name', $ca);
        } else {
            $partnerQuery->whereRaw('UPPER(TRIM(partner_name)) = ?', [mb_strtoupper($ca)]);
        }
        $partnerFirmIds = $partnerQuery->limit(50)->pluck('firm_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($partnerFirmIds === []) {
            return null;
        }

        $addressQuery = CaAddress::query()->whereIn('firm_id', $partnerFirmIds)->select(['id', 'firm_id', 'city']);
        if ($hasNormCity) {
            $addressQuery->where('normalized_city', $cityNorm);
        } else {
            $addressQuery->whereRaw('UPPER(TRIM(city)) = ?', [$cityNorm]);
        }
        $hitFirmIds = $addressQuery->limit(50)->pluck('firm_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($hitFirmIds === []) {
            return null;
        }

        $firms = CaFirm::query()->whereIn('id', $hitFirmIds)->get(['id', 'firm_name']);
        $candidates = $firms->map(static fn (CaFirm $row) => [
            'ca_id' => null,
            'score' => 1.0,
            'matched_on' => 'ca_reference_firm_ca_city_exact',
            'firm_name' => $row->firm_name,
            'ca_name' => null,
            'reference_firm_id' => (int) $row->id,
        ])->values()->all();

        if (count($candidates) === 1) {
            $referenceFirmId = (int) $candidates[0]['reference_firm_id'];
            $masterId = $this->findLinkedMasterId($firm, $ca, $cityNorm);

            return MatchResult::exactReference($referenceFirmId, 'ca_reference_firm_ca_city_exact', $candidates, $masterId);
        }

        return MatchResult::conflict($candidates, 'multiple_ca_reference_firm_ca_city');
    }

    private function matchCaMaster(string $firm, string $ca, mixed $cityRaw, string $cityNorm): MatchResult
    {
        $cityId = $this->lookups->resolveCityId(is_string($cityRaw) ? $cityRaw : null);
        if ($cityId === null) {
            return MatchResult::unmatched('city_not_in_master');
        }

        $query = CaMaster::query()
            ->where('normalized_firm_name', $firm)
            ->where('city_id', $cityId);

        if ($this->hasNormalizedCaName()) {
            $query->where('normalized_ca_name', $ca);
        } else {
            $query->whereRaw('UPPER(TRIM(ca_name)) = ?', [mb_strtoupper($ca)]);
        }

        $hits = $query->limit(5)->get(['ca_id', 'firm_name', 'ca_name', 'city_id', 'normalized_firm_name']);
        if ($hits->isEmpty()) {
            return MatchResult::unmatched('no_exact_firm_ca_city');
        }

        $candidates = $hits->map(static fn (CaMaster $row) => [
            'ca_id' => (int) $row->ca_id,
            'score' => 1.0,
            'matched_on' => 'firm_ca_city_exact',
            'firm_name' => $row->firm_name,
            'ca_name' => $row->ca_name,
            'city_id' => $row->city_id,
        ])->values()->all();

        if (count($candidates) === 1) {
            return MatchResult::exact((int) $candidates[0]['ca_id'], 'firm_ca_city_exact', $candidates);
        }

        return MatchResult::conflict($candidates, 'multiple_firm_ca_city');
    }

    private function hasNormalizedCaName(): bool
    {
        if (self::$hasNormalizedCaName !== null) {
            return self::$hasNormalizedCaName;
        }

        self::$hasNormalizedCaName = Schema::hasColumn('ca_masters', 'normalized_ca_name');

        return self::$hasNormalizedCaName;
    }

    private function findLinkedMasterId(string $firm, string $ca, string $cityNorm): ?int
    {
        if (! Schema::hasTable('ca_masters') || ! Schema::hasColumn('ca_masters', 'normalized_firm_name')) {
            return null;
        }

        $cityId = $this->lookups->resolveCityId($cityNorm);
        $query = CaMaster::query()->where('normalized_firm_name', $firm);
        if ($cityId !== null) {
            $query->where('city_id', $cityId);
        }
        if ($this->hasNormalizedCaName()) {
            $query->where('normalized_ca_name', $ca);
        } else {
            $query->whereRaw('UPPER(TRIM(ca_name)) = ?', [mb_strtoupper($ca)]);
        }

        $id = $query->limit(2)->pluck('ca_id');
        if ($id->count() !== 1) {
            return null;
        }

        return (int) $id->first();
    }
}
