<?php

namespace App\Services\SalesMapping;

use App\Models\CaMaster;
use App\Services\Mapping\DataNormalizationService;
use App\Services\Master\LookupResolverService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales List → ca_masters matcher (enrichment only).
 * Never creates or updates ca_masters.
 *
 * Priority:
 * A. Firm + CA + City
 * B. Firm + Mobile
 * C. CA + Mobile
 * D. Normalized Firm + CA + City
 * E. Email
 */
class SalesMasterMatcher
{
    public const TIER_FIRM_CA_CITY = 'firm_ca_city';

    public const TIER_FIRM_MOBILE = 'firm_mobile';

    public const TIER_CA_MOBILE = 'ca_mobile';

    public const TIER_NORMALIZED_FIRM_CA_CITY = 'normalized_firm_ca_city';

    public const TIER_EMAIL = 'email';

    public function __construct(
        private readonly DataNormalizationService $normalizer,
        private readonly LookupResolverService $lookups,
    ) {}

    /**
     * @param  array{
     *   ca_name?: string|null,
     *   firm_name?: string|null,
     *   city_name?: string|null,
     *   mobile_no?: string|null,
     *   email?: string|null
     * }  $input
     * @return array{
     *   status: string,
     *   ca_id: int|null,
     *   matched_reference_firm_id: int|null,
     *   matched_on: string|null,
     *   match_tier: string|null,
     *   confidence_tier: string|null,
     *   score: float|null,
     *   reason: string|null,
     *   candidates: list<array<string, mixed>>,
     *   normalized_firm_name: string|null,
     *   normalized_city: string|null,
     *   normalized_ca_name: string|null,
     *   normalized_mobile: string|null,
     *   normalized_email: string|null
     * }
     */
    public function match(array $input): array
    {
        $normFirm = $this->normalizer->salesFirmName($input['firm_name'] ?? null);
        $normCity = $this->normalizer->salesCityName($input['city_name'] ?? null);
        $normCa = $this->normalizer->caName($input['ca_name'] ?? null);
        if ($normCa !== null) {
            $normCa = mb_strtoupper($normCa);
        }
        $normMobile = $this->normalizer->phone($input['mobile_no'] ?? null);
        $normEmail = $this->normalizer->email($input['email'] ?? null);

        $base = [
            'normalized_firm_name' => $normFirm,
            'normalized_city' => $normCity,
            'normalized_ca_name' => $normCa,
            'normalized_mobile' => $normMobile,
            'normalized_email' => $normEmail,
            'matched_reference_firm_id' => null,
            'candidates' => [],
            'match_tier' => null,
            'confidence_tier' => null,
        ];

        if (! Schema::hasTable('ca_masters')) {
            return array_merge($base, [
                'status' => 'unmatched',
                'ca_id' => null,
                'matched_on' => null,
                'score' => null,
                'reason' => 'ca_masters table is unavailable.',
            ]);
        }

        $threshold = (float) config('sales_imports.matching.auto_match_min_confidence', 0.90);
        $tierScores = (array) config('sales_imports.matching.tier_confidence', []);
        $cityId = $this->lookups->resolveCityId($input['city_name'] ?? $normCity);

        $tiers = [
            self::TIER_FIRM_CA_CITY => fn () => $this->findByFirmCaCity($normFirm, $normCa, $normCity, $cityId, false),
            self::TIER_FIRM_MOBILE => fn () => $this->findByFirmMobile($normFirm, $normMobile),
            self::TIER_CA_MOBILE => fn () => $this->findByCaMobile($normCa, $normMobile),
            self::TIER_NORMALIZED_FIRM_CA_CITY => fn () => $this->findByFirmCaCity($normFirm, $normCa, $normCity, $cityId, true),
            self::TIER_EMAIL => fn () => $this->findByEmail($normEmail),
        ];

        foreach ($tiers as $tier => $finder) {
            $ids = $finder();
            if ($ids === []) {
                continue;
            }

            $confidence = (float) ($tierScores[$tier] ?? 0.90);
            $candidates = $this->candidatePayloads($ids, $tier, $confidence);

            if (count($ids) > 1) {
                return array_merge($base, [
                    'status' => 'needs_review',
                    'ca_id' => null,
                    'matched_on' => 'multiple_'.$tier,
                    'match_tier' => $tier,
                    'confidence_tier' => $tier,
                    'score' => $confidence,
                    'reason' => 'Multiple ca_masters candidates for tier '.$tier.'.',
                    'candidates' => $candidates,
                ]);
            }

            if ($confidence < $threshold) {
                return array_merge($base, [
                    'status' => 'needs_review',
                    'ca_id' => null,
                    'matched_on' => $tier,
                    'match_tier' => $tier,
                    'confidence_tier' => $tier,
                    'score' => $confidence,
                    'reason' => 'Single candidate found but confidence is below auto-match threshold.',
                    'candidates' => $candidates,
                ]);
            }

            return array_merge($base, [
                'status' => 'matched',
                'ca_id' => $ids[0],
                'matched_on' => $tier,
                'match_tier' => $tier,
                'confidence_tier' => $tier,
                'score' => $confidence,
                'reason' => null,
                'candidates' => $candidates,
            ]);
        }

        return array_merge($base, [
            'status' => 'unmatched',
            'ca_id' => null,
            'matched_on' => null,
            'score' => null,
            'reason' => 'No ca_masters candidate matched any Sales Mapping tier.',
        ]);
    }

    /**
     * @return list<int>
     */
    private function findByFirmCaCity(
        ?string $normFirm,
        ?string $normCa,
        ?string $normCity,
        ?int $cityId,
        bool $storedNormalizedOnly,
    ): array {
        if ($normFirm === null || $normCa === null || ($normCity === null && $cityId === null)) {
            return [];
        }

        $limit = (int) config('sales_imports.matching.candidate_limit', 25);
        $select = ['ca_id', 'ca_name', 'firm_name', 'normalized_ca_name', 'normalized_firm_name', 'city_id'];
        if (Schema::hasColumn('ca_masters', 'ocr_city_text')) {
            $select[] = 'ocr_city_text';
        }
        $query = CaMaster::query()->select($select);

        if ($storedNormalizedOnly) {
            if (! Schema::hasColumn('ca_masters', 'normalized_firm_name')
                || ! Schema::hasColumn('ca_masters', 'normalized_ca_name')) {
                return [];
            }
            $query->where('normalized_firm_name', $normFirm)
                ->where('normalized_ca_name', $normCa);
        } else {
            $query->where(function ($q) use ($normFirm) {
                if (Schema::hasColumn('ca_masters', 'normalized_firm_name')) {
                    $q->where('normalized_firm_name', $normFirm)
                        ->orWhereRaw('UPPER(TRIM(firm_name)) = ?', [$normFirm]);
                } else {
                    $q->whereRaw('UPPER(TRIM(firm_name)) = ?', [$normFirm]);
                }
            })->where(function ($q) use ($normCa) {
                if (Schema::hasColumn('ca_masters', 'normalized_ca_name')) {
                    $q->where('normalized_ca_name', $normCa)
                        ->orWhereRaw('UPPER(TRIM(ca_name)) = ?', [$normCa]);
                } else {
                    $q->whereRaw('UPPER(TRIM(ca_name)) = ?', [$normCa]);
                }
            });
        }

        if ($cityId !== null) {
            $query->where('city_id', $cityId);
        }

        $rows = $query->limit($limit * 2)->get();

        return $rows->filter(function (CaMaster $row) use ($normFirm, $normCa, $normCity, $cityId, $storedNormalizedOnly) {
            $firm = $this->normalizer->salesFirmName(
                ($storedNormalizedOnly ? $row->normalized_firm_name : null) ?: ($row->normalized_firm_name ?: $row->firm_name)
            );
            $ca = $this->normalizer->caName(
                ($storedNormalizedOnly ? $row->normalized_ca_name : null) ?: ($row->normalized_ca_name ?: $row->ca_name)
            );
            $ca = $ca !== null ? mb_strtoupper($ca) : null;
            if ($firm !== $normFirm || $ca !== $normCa) {
                return false;
            }
            if ($cityId !== null) {
                return (int) $row->city_id === (int) $cityId;
            }

            return $this->masterCityMatches($row, $normCity);
        })
            ->pluck('ca_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function findByFirmMobile(?string $normFirm, ?string $normMobile): array
    {
        if ($normFirm === null || $normMobile === null) {
            return [];
        }

        $limit = (int) config('sales_imports.matching.candidate_limit', 25);
        $query = CaMaster::query()->select([
            'ca_id', 'firm_name', 'normalized_firm_name', 'mobile_no', 'normalized_mobile',
            'alternate_mobile_no', 'normalized_alternate_mobile',
        ]);

        $query->where(function ($q) use ($normFirm) {
            if (Schema::hasColumn('ca_masters', 'normalized_firm_name')) {
                $q->where('normalized_firm_name', $normFirm)
                    ->orWhereRaw('UPPER(TRIM(firm_name)) = ?', [$normFirm]);
            } else {
                $q->whereRaw('UPPER(TRIM(firm_name)) = ?', [$normFirm]);
            }
        });

        $this->constrainMobile($query, $normMobile);

        return $query->limit($limit * 2)->get()
            ->filter(function (CaMaster $row) use ($normFirm, $normMobile) {
                $firm = $this->normalizer->salesFirmName($row->normalized_firm_name ?: $row->firm_name);
                if ($firm !== $normFirm) {
                    return false;
                }

                return $this->rowHasMobile($row, $normMobile);
            })
            ->pluck('ca_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function findByCaMobile(?string $normCa, ?string $normMobile): array
    {
        if ($normCa === null || $normMobile === null) {
            return [];
        }

        $limit = (int) config('sales_imports.matching.candidate_limit', 25);
        $query = CaMaster::query()->select([
            'ca_id', 'ca_name', 'normalized_ca_name', 'mobile_no', 'normalized_mobile',
            'alternate_mobile_no', 'normalized_alternate_mobile',
        ]);

        $query->where(function ($q) use ($normCa) {
            if (Schema::hasColumn('ca_masters', 'normalized_ca_name')) {
                $q->where('normalized_ca_name', $normCa)
                    ->orWhereRaw('UPPER(TRIM(ca_name)) = ?', [$normCa]);
            } else {
                $q->whereRaw('UPPER(TRIM(ca_name)) = ?', [$normCa]);
            }
        });

        $this->constrainMobile($query, $normMobile);

        return $query->limit($limit * 2)->get()
            ->filter(function (CaMaster $row) use ($normCa, $normMobile) {
                $ca = $this->normalizer->caName($row->normalized_ca_name ?: $row->ca_name);
                $ca = $ca !== null ? mb_strtoupper($ca) : null;
                if ($ca !== $normCa) {
                    return false;
                }

                return $this->rowHasMobile($row, $normMobile);
            })
            ->pluck('ca_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function findByEmail(?string $normEmail): array
    {
        if ($normEmail === null) {
            return [];
        }

        $limit = (int) config('sales_imports.matching.candidate_limit', 25);
        $query = CaMaster::query()->select(['ca_id', 'email_id', 'normalized_email']);

        if (Schema::hasColumn('ca_masters', 'normalized_email')) {
            $query->where(function ($q) use ($normEmail) {
                $q->where('normalized_email', $normEmail)
                    ->orWhereRaw('LOWER(TRIM(email_id)) = ?', [$normEmail]);
            });
        } else {
            $query->whereRaw('LOWER(TRIM(email_id)) = ?', [$normEmail]);
        }

        return $query->limit($limit)->get()
            ->filter(function (CaMaster $row) use ($normEmail) {
                $email = $this->normalizer->email($row->normalized_email ?: $row->email_id);

                return $email === $normEmail;
            })
            ->pluck('ca_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function constrainMobile($query, string $normMobile): void
    {
        $query->where(function ($q) use ($normMobile) {
            if (Schema::hasColumn('ca_masters', 'normalized_mobile')) {
                $q->where('normalized_mobile', $normMobile)
                    ->orWhere('normalized_alternate_mobile', $normMobile);
            }
            $q->orWhereRaw("REPLACE(REPLACE(REPLACE(COALESCE(mobile_no,''), ' ', ''), '-', ''), '+', '') LIKE ?", ['%'.$normMobile])
                ->orWhereRaw("REPLACE(REPLACE(REPLACE(COALESCE(alternate_mobile_no,''), ' ', ''), '-', ''), '+', '') LIKE ?", ['%'.$normMobile]);
        });
    }

    private function rowHasMobile(CaMaster $row, string $normMobile): bool
    {
        foreach ([$row->normalized_mobile, $row->normalized_alternate_mobile, $row->mobile_no, $row->alternate_mobile_no] as $value) {
            $mobile = $this->normalizer->phone(is_string($value) ? $value : null);
            if ($mobile === $normMobile) {
                return true;
            }
        }

        return false;
    }

    private function masterCityMatches(CaMaster $row, ?string $normCity): bool
    {
        if ($normCity === null) {
            return false;
        }
        if (Schema::hasColumn('ca_masters', 'ocr_city_text') && is_string($row->ocr_city_text)) {
            if ($this->normalizer->salesCityName($row->ocr_city_text) === $normCity) {
                return true;
            }
        }
        if (! $row->city_id) {
            return false;
        }
        $cityName = DB::table('cities')->where('city_id', $row->city_id)->value('city_name');

        return $this->normalizer->salesCityName(is_string($cityName) ? $cityName : null) === $normCity;
    }

    /**
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function candidatePayloads(array $ids, string $tier, float $confidence): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = CaMaster::query()
            ->whereIn('ca_id', $ids)
            ->get(['ca_id', 'ca_name', 'firm_name', 'city_id', 'verification_status', 'is_verified']);

        return $rows->map(static fn (CaMaster $row) => [
            'ca_id' => (int) $row->ca_id,
            'ca_name' => $row->ca_name,
            'firm_name' => $row->firm_name,
            'city_id' => $row->city_id,
            'verification_status' => $row->verification_status,
            'is_verified' => $row->is_verified,
            'matched_on' => $tier,
            'match_tier' => $tier,
            'score' => $confidence,
            'match_score' => $confidence,
        ])->values()->all();
    }
}
