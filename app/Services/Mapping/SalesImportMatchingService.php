<?php

namespace App\Services\Mapping;

use App\Models\CaAddress;
use App\Models\CaFirm;
use App\Models\CaMaster;
use App\Models\CaPartner;
use App\Services\Master\LookupResolverService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employee Sales List Auto Match: normalized firm + city against CA Reference.
 * Never creates or updates CA / reference records.
 */
class SalesImportMatchingService
{
    public const MATCHED_ON = 'exact_normalized_firm_city';

    private static ?bool $caReferenceReady = null;

    public function __construct(
        private readonly DataNormalizationService $normalizer,
        private readonly LookupResolverService $lookups,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     ca_id: int|null,
     *     matched_reference_firm_id: int|null,
     *     matched_on: string|null,
     *     score: float|null,
     *     reason: string|null,
     *     candidates: list<array<string, mixed>>,
     *     normalized_firm_name: string|null,
     *     normalized_city: string|null
     * }
     */
    public function match(?string $rawFirmName, ?string $rawCityName): array
    {
        $firm = $this->normalizer->salesFirmName($rawFirmName);
        $city = $this->normalizer->salesCityName($rawCityName);

        $base = [
            'normalized_firm_name' => $firm,
            'normalized_city' => $city,
            'candidates' => [],
            'matched_reference_firm_id' => null,
        ];

        if ($firm === null || $city === null) {
            return array_merge($base, [
                'status' => 'unmatched',
                'ca_id' => null,
                'matched_on' => null,
                'score' => null,
                'reason' => 'Firm name or city is missing after normalization.',
            ]);
        }

        $referenceHits = $this->findCaReferenceFirmIds($firm, $city);

        if (count($referenceHits) > 1) {
            $candidates = $this->referenceCandidates($referenceHits, $firm, $city);

            return array_merge($base, [
                'status' => 'needs_review',
                'ca_id' => null,
                'matched_on' => 'multiple_exact_normalized_firm_city',
                'score' => 1.0,
                'reason' => 'More than one CA Reference record matches the normalized firm name and city.',
                'candidates' => $candidates,
            ]);
        }

        if (count($referenceHits) === 1) {
            $referenceFirmId = $referenceHits[0];
            $masterIds = $this->findCaMasterIds($firm, $city, $rawCityName);

            if (count($masterIds) === 1) {
                return array_merge($base, [
                    'status' => 'matched',
                    'ca_id' => $masterIds[0],
                    'matched_reference_firm_id' => $referenceFirmId,
                    'matched_on' => self::MATCHED_ON,
                    'score' => 1.0,
                    'reason' => null,
                    'candidates' => [[
                        'ca_id' => $masterIds[0],
                        'reference_firm_id' => $referenceFirmId,
                        'matched_on' => self::MATCHED_ON,
                        'score' => 1.0,
                    ]],
                ]);
            }

            $candidates = $this->referenceCandidates([$referenceFirmId], $firm, $city);
            foreach ($masterIds as $caId) {
                $candidates[] = [
                    'ca_id' => $caId,
                    'reference_firm_id' => $referenceFirmId,
                    'matched_on' => self::MATCHED_ON,
                    'score' => 0.9,
                ];
            }

            return array_merge($base, [
                'status' => 'needs_review',
                'ca_id' => null,
                'matched_reference_firm_id' => $referenceFirmId,
                'matched_on' => self::MATCHED_ON,
                'score' => 1.0,
                'reason' => count($masterIds) === 0
                    ? 'CA Reference matched, but no linked CRM CA master was found for firm + city. Manual link required.'
                    : 'CA Reference matched, but multiple CRM CA masters share the same firm + city.',
                'candidates' => $candidates,
            ]);
        }

        // No CA Reference hit — do not create; leave unmatched (Auto Match rule).
        return array_merge($base, [
            'status' => 'unmatched',
            'ca_id' => null,
            'matched_on' => null,
            'score' => null,
            'reason' => 'No CA Reference record matches the normalized firm name and city.',
        ]);
    }

    /**
     * @return list<int>
     */
    private function findCaReferenceFirmIds(string $firm, string $city): array
    {
        if (! $this->caReferenceReady()) {
            return [];
        }

        $hasNormFirm = Schema::connection('ca_reference')->hasColumn('ca_firms', 'normalized_firm_name');
        $hasNormCity = Schema::connection('ca_reference')->hasColumn('ca_addresses', 'normalized_city');

        // Match using sales normalizer on both stored and raw values so OCR-style
        // normalized_firm_name (AND→&) still aligns with sales Auto Match keys.
        $firmIds = CaFirm::query()
            ->select(['id', 'firm_name', 'normalized_firm_name'])
            ->when($hasNormFirm, function ($q) use ($firm) {
                $q->where(function ($inner) use ($firm) {
                    $inner->where('normalized_firm_name', $firm)
                        ->orWhereRaw('UPPER(TRIM(firm_name)) = ?', [$firm]);
                });
            }, function ($q) use ($firm) {
                $q->whereRaw('UPPER(TRIM(firm_name)) = ?', [$firm]);
            })
            ->limit(100)
            ->get()
            ->filter(function (CaFirm $row) use ($firm) {
                $fromStored = $this->normalizer->salesFirmName($row->normalized_firm_name ?: $row->firm_name);

                return $fromStored === $firm;
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($firmIds === []) {
            return [];
        }

        $addressFirmIds = CaAddress::query()
            ->whereIn('firm_id', $firmIds)
            ->select(['firm_id', 'city', 'normalized_city'])
            ->limit(200)
            ->get()
            ->filter(function (CaAddress $row) use ($city, $hasNormCity) {
                $raw = $hasNormCity && $row->normalized_city
                    ? $row->normalized_city
                    : $row->city;

                return $this->normalizer->salesCityName(is_string($raw) ? $raw : null) === $city;
            })
            ->pluck('firm_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $addressFirmIds;
    }

    /**
     * @return list<int>
     */
    private function findCaMasterIds(string $firm, string $city, ?string $rawCityName): array
    {
        if (! Schema::hasTable('ca_masters')) {
            return [];
        }

        $cityId = $this->lookups->resolveCityId($rawCityName ?: $city);
        $query = CaMaster::query()->select(['ca_id', 'firm_name', 'normalized_firm_name', 'city_id']);

        if (Schema::hasColumn('ca_masters', 'normalized_firm_name')) {
            $query->where(function ($q) use ($firm) {
                $q->where('normalized_firm_name', $firm)
                    ->orWhereRaw('UPPER(TRIM(firm_name)) = ?', [$firm]);
            });
        } else {
            $query->whereRaw('UPPER(TRIM(firm_name)) = ?', [$firm]);
        }

        if ($cityId !== null) {
            $query->where('city_id', $cityId);
        }

        $hits = $query->limit(50)->get()->filter(function (CaMaster $row) use ($firm, $city, $cityId) {
            $firmOk = $this->normalizer->salesFirmName($row->normalized_firm_name ?: $row->firm_name) === $firm;
            if (! $firmOk) {
                return false;
            }
            if ($cityId !== null) {
                return (int) $row->city_id === (int) $cityId;
            }
            $cityName = DB::table('cities')->where('city_id', $row->city_id)->value('city_name');

            return $this->normalizer->salesCityName(is_string($cityName) ? $cityName : null) === $city;
        });

        return $hits->pluck('ca_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * @param  list<int>  $firmIds
     * @return list<array<string, mixed>>
     */
    private function referenceCandidates(array $firmIds, string $firm, string $city): array
    {
        $firms = CaFirm::query()->whereIn('id', $firmIds)->get(['id', 'firm_name']);

        return $firms->map(static fn (CaFirm $row) => [
            'ca_id' => null,
            'reference_firm_id' => (int) $row->id,
            'firm_name' => $row->firm_name,
            'normalized_firm_name' => $firm,
            'normalized_city' => $city,
            'matched_on' => self::MATCHED_ON,
            'score' => 1.0,
        ])->values()->all();
    }

    private function caReferenceReady(): bool
    {
        if (self::$caReferenceReady !== null) {
            return self::$caReferenceReady;
        }

        try {
            self::$caReferenceReady = Schema::connection('ca_reference')->hasTable('ca_firms')
                && Schema::connection('ca_reference')->hasTable('ca_addresses');
        } catch (\Throwable) {
            self::$caReferenceReady = false;
        }

        return self::$caReferenceReady;
    }

    /**
     * Ranked review candidates (exact + controlled fuzzy). Does not auto-confirm.
     *
     * @return list<array<string, mixed>>
     */
    public function findReviewCandidates(
        ?string $rawFirmName,
        ?string $rawCityName,
        ?string $rawCaName = null,
        int $limit = 15,
    ): array {
        $limit = max(1, min(20, $limit));
        $firm = $this->normalizer->salesFirmName($rawFirmName);
        $city = $this->normalizer->salesCityName($rawCityName);
        $ca = $this->normalizer->salesFirmName($rawCaName); // reuse sales scrubbing for person/firm tokens

        if ($firm === null && $ca === null) {
            return [];
        }

        if (! $this->caReferenceReady()) {
            return $this->masterOnlyCandidates($firm, $city, $ca, $limit);
        }

        try {
            $byId = [];

            if ($firm !== null && $city !== null) {
                foreach ($this->findCaReferenceFirmIds($firm, $city) as $firmId) {
                    $byId[$firmId] = $this->scoreCandidate($firmId, $firm, $city, $ca, true, true);
                }
            }

            if ($firm !== null) {
                foreach ($this->lookupReferenceFirmsByFirmPrefix($firm, 40) as $firmId) {
                    if (isset($byId[$firmId])) {
                        continue;
                    }
                    $byId[$firmId] = $this->scoreCandidate($firmId, $firm, $city, $ca, true, false);
                }
            }

            if ($ca !== null) {
                foreach ($this->lookupReferenceFirmsByPartner($ca, 30) as $firmId) {
                    $byId[$firmId] = $this->scoreCandidate($firmId, $firm, $city, $ca, false, false);
                }
            }

            $ranked = array_values(array_filter($byId));
            usort($ranked, static fn ($a, $b) => ($b['match_score'] <=> $a['match_score']) ?: (($a['reference_firm_id'] ?? 0) <=> ($b['reference_firm_id'] ?? 0)));

            return array_slice($ranked, 0, $limit);
        } catch (\Throwable) {
            self::$caReferenceReady = false;

            return $this->masterOnlyCandidates($firm, $city, $ca, $limit);
        }
    }

    /**
     * Server-side paginated CA Reference search for the review modal.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>}
     */
    public function searchCaReference(
        ?string $firmQuery,
        ?string $caQuery,
        ?string $cityQuery,
        int $page = 1,
        int $perPage = 20,
    ): array {
        $page = max(1, $page);
        $perPage = max(5, min(50, $perPage));

        if (! $this->caReferenceReady()) {
            return [
                'items' => [],
                'pagination' => [
                    'current_page' => $page,
                    'last_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                ],
            ];
        }

        $firmNorm = $this->normalizer->salesFirmName($firmQuery);
        $caNorm = $this->normalizer->salesFirmName($caQuery);
        $cityNorm = $this->normalizer->salesCityName($cityQuery);

        $firmIds = null;

        if ($firmNorm !== null) {
            $firmIds = CaFirm::query()
                ->where(function ($q) use ($firmQuery, $firmNorm) {
                    $q->where('firm_name', 'like', '%'.addcslashes((string) $firmQuery, '%_').'%');
                    if (Schema::connection('ca_reference')->hasColumn('ca_firms', 'normalized_firm_name')) {
                        $q->orWhere('normalized_firm_name', 'like', $firmNorm.'%');
                    }
                })
                ->limit(200)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if ($caNorm !== null) {
            $partnerFirmIds = CaPartner::query()
                ->where(function ($q) use ($caQuery, $caNorm) {
                    $q->where('partner_name', 'like', '%'.addcslashes((string) $caQuery, '%_').'%');
                    if (Schema::connection('ca_reference')->hasColumn('ca_partners', 'normalized_partner_name')) {
                        $q->orWhere('normalized_partner_name', 'like', $caNorm.'%');
                    }
                })
                ->limit(200)
                ->pluck('firm_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
            $firmIds = $firmIds === null ? $partnerFirmIds : array_values(array_intersect($firmIds, $partnerFirmIds));
        }

        if ($cityNorm !== null) {
            $cityFirmIds = CaAddress::query()
                ->where(function ($q) use ($cityQuery, $cityNorm) {
                    $q->where('city', 'like', '%'.addcslashes((string) $cityQuery, '%_').'%');
                    if (Schema::connection('ca_reference')->hasColumn('ca_addresses', 'normalized_city')) {
                        $q->orWhere('normalized_city', 'like', $cityNorm.'%');
                    }
                })
                ->limit(300)
                ->pluck('firm_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
            $firmIds = $firmIds === null ? $cityFirmIds : array_values(array_intersect($firmIds, $cityFirmIds));
        }

        if ($firmIds === null) {
            $firmIds = CaFirm::query()->orderBy('id')->limit(200)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $total = count($firmIds);
        $slice = array_slice($firmIds, ($page - 1) * $perPage, $perPage);
        $items = [];
        foreach ($slice as $firmId) {
            $scored = $this->scoreCandidate($firmId, $firmNorm, $cityNorm, $caNorm, false, false);
            if ($scored !== null) {
                $items[] = $scored;
            }
        }

        return [
            'items' => $items,
            'pagination' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    /**
     * @return list<int>
     */
    private function lookupReferenceFirmsByFirmPrefix(string $firm, int $limit): array
    {
        $prefix = mb_substr($firm, 0, min(12, mb_strlen($firm)));
        if ($prefix === '') {
            return [];
        }

        return CaFirm::query()
            ->when(
                Schema::connection('ca_reference')->hasColumn('ca_firms', 'normalized_firm_name'),
                fn ($q) => $q->where('normalized_firm_name', 'like', $prefix.'%'),
                fn ($q) => $q->whereRaw('UPPER(firm_name) like ?', [$prefix.'%'])
            )
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    private function lookupReferenceFirmsByPartner(string $ca, int $limit): array
    {
        $prefix = mb_substr($ca, 0, min(12, mb_strlen($ca)));
        if ($prefix === '') {
            return [];
        }

        return CaPartner::query()
            ->when(
                Schema::connection('ca_reference')->hasColumn('ca_partners', 'normalized_partner_name'),
                fn ($q) => $q->where('normalized_partner_name', 'like', $prefix.'%'),
                fn ($q) => $q->whereRaw('UPPER(partner_name) like ?', [$prefix.'%'])
            )
            ->limit($limit)
            ->pluck('firm_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function masterOnlyCandidates(?string $firm, ?string $city, ?string $ca, int $limit): array
    {
        if ($firm === null || ! Schema::hasTable('ca_masters')) {
            return [];
        }

        $rows = CaMaster::query()
            ->with('city:city_id,city_name')
            ->where(function ($q) use ($firm) {
                $q->where('firm_name', 'like', '%'.addcslashes($firm, '%_').'%');
                if (Schema::hasColumn('ca_masters', 'normalized_firm_name')) {
                    $q->orWhere('normalized_firm_name', 'like', $firm.'%');
                }
            })
            ->limit($limit)
            ->get(['ca_id', 'ca_name', 'firm_name', 'normalized_firm_name', 'normalized_ca_name', 'city_id']);

        $out = [];
        foreach ($rows as $row) {
            $nf = $this->normalizer->salesFirmName($row->normalized_firm_name ?: $row->firm_name);
            $nc = $this->normalizer->salesCityName($row->city?->city_name);
            $nca = $this->normalizer->salesFirmName($row->normalized_ca_name ?: $row->ca_name);
            $score = 0;
            $exact = [];
            $diff = [];
            if ($firm !== null && $nf === $firm) {
                $score += 50;
                $exact[] = 'firm_name';
            } else {
                $diff[] = 'firm_name';
            }
            if ($city !== null && $nc === $city) {
                $score += 30;
                $exact[] = 'city';
            } elseif ($city !== null) {
                $diff[] = 'city';
            }
            if ($ca !== null && $nca === $ca) {
                $score += 20;
                $exact[] = 'ca_name';
            } elseif ($ca !== null) {
                $diff[] = 'ca_name';
            }
            if ($score <= 0) {
                continue;
            }
            $out[] = [
                'reference_firm_id' => null,
                'ca_id' => (int) $row->ca_id,
                'ca_name' => $row->ca_name,
                'firm_name' => $row->firm_name,
                'city' => $row->city?->city_name,
                'normalized_firm_name' => $nf,
                'normalized_ca_name' => $nca,
                'normalized_city' => $nc,
                'match_score' => $score,
                'match_reason' => 'crm_master_candidate',
                'exact_fields' => $exact,
                'different_fields' => $diff,
            ];
        }

        usort($out, static fn ($a, $b) => $b['match_score'] <=> $a['match_score']);

        return array_slice($out, 0, $limit);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function scoreCandidate(
        int $referenceFirmId,
        ?string $wantFirm,
        ?string $wantCity,
        ?string $wantCa,
        bool $preferExactFirm = false,
        bool $preferExactCity = false,
    ): ?array {
        $firm = CaFirm::query()->where('id', $referenceFirmId)->first(['id', 'firm_name', 'normalized_firm_name']);
        if (! $firm) {
            return null;
        }

        $nf = $this->normalizer->salesFirmName($firm->normalized_firm_name ?: $firm->firm_name);
        $address = CaAddress::query()->where('firm_id', $referenceFirmId)->orderBy('id')->first(['city', 'normalized_city']);
        $nc = $this->normalizer->salesCityName($address?->normalized_city ?: $address?->city);

        $partner = CaPartner::query()->where('firm_id', $referenceFirmId)->orderBy('id')->first(['partner_name', 'normalized_partner_name']);
        $nca = $this->normalizer->salesFirmName($partner?->normalized_partner_name ?: $partner?->partner_name);

        $score = 0;
        $exact = [];
        $diff = [];
        $firmExact = $wantFirm !== null && $nf === $wantFirm;
        $cityExact = $wantCity !== null && $nc === $wantCity;
        $caExact = $wantCa !== null && $nca === $wantCa;

        if ($firmExact) {
            $score += 50;
            $exact[] = 'firm_name';
        } elseif ($wantFirm !== null) {
            $diff[] = 'firm_name';
            if ($nf && str_starts_with($nf, mb_substr($wantFirm, 0, 6))) {
                $score += 20;
            }
        }

        if ($cityExact) {
            $score += 30;
            $exact[] = 'city';
        } elseif ($wantCity !== null) {
            $diff[] = 'city';
        }

        if ($caExact) {
            $score += 20;
            $exact[] = 'ca_name';
        } elseif ($wantCa !== null) {
            $diff[] = 'ca_name';
        }

        if ($preferExactFirm && ! $firmExact) {
            return null;
        }
        if ($preferExactCity && ! $cityExact) {
            // keep for multi-candidate exact firm lists only when city also exact path
        }

        if ($score <= 0) {
            return null;
        }

        $linkedMasterIds = ($nf && $nc)
            ? $this->findCaMasterIds($nf, $nc, $address?->city)
            : [];

        return [
            'reference_firm_id' => $referenceFirmId,
            'ca_id' => count($linkedMasterIds) === 1 ? $linkedMasterIds[0] : null,
            'linked_ca_ids' => $linkedMasterIds,
            'ca_name' => $partner?->partner_name,
            'firm_name' => $firm->firm_name,
            'city' => $address?->city,
            'normalized_firm_name' => $nf,
            'normalized_ca_name' => $nca,
            'normalized_city' => $nc,
            'match_score' => $score,
            'match_reason' => $firmExact && $cityExact
                ? 'exact_normalized_firm_city'
                : ($firmExact ? 'firm_exact_partial_other' : 'fuzzy_or_partial'),
            'exact_fields' => $exact,
            'different_fields' => $diff,
        ];
    }
}
