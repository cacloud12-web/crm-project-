<?php

namespace App\Services\Mapping;

use App\Models\CaMaster;
use Illuminate\Support\Facades\Schema;

/**
 * Matching profile for sales-team imports against Master CA records that often lack mobiles.
 *
 * Shortlists by state_id + indexed firm/CA prefixes — never full-table scans.
 */
class StateFirmCaMatchingProfile
{
    public const PROFILE = 'state_firm_ca';

    public function __construct(
        private readonly DataNormalizationService $normalizer,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @return array<string, mixed>
     */
    public function buildIndex(array $payloads): array
    {
        $chunk = (int) config('crm_mapping.index_chunk_size', 500);
        $prefixLen = (int) config('crm_mapping.profiles.state_firm_ca.prefix_length', config('crm_mapping.fuzzy_prefix_length', 8));
        $fuzzyLimit = (int) config('crm_mapping.profiles.state_firm_ca.prefix_limit', config('crm_mapping.fuzzy_prefix_limit', 25));

        $stateIds = [];
        $firms = [];
        $firmPrefixes = [];
        $caNames = [];
        $caPrefixes = [];
        $frns = [];
        $memberships = [];
        $phones = [];

        foreach ($payloads as $payload) {
            if (! empty($payload['state_id'])) {
                $stateIds[(int) $payload['state_id']] = true;
            }
            $firm = mb_strtoupper((string) ($payload['normalized_firm_name'] ?? ''));
            if ($firm !== '') {
                $firms[$firm] = true;
                if (mb_strlen($firm) >= $prefixLen) {
                    $firmPrefixes[mb_substr($firm, 0, $prefixLen)] = true;
                }
            }
            $ca = mb_strtoupper((string) ($payload['normalized_ca_name'] ?? ''));
            if ($ca !== '') {
                $caNames[$ca] = true;
                if (mb_strlen($ca) >= min(4, $prefixLen)) {
                    $caPrefixes[mb_substr($ca, 0, min(4, $prefixLen))] = true;
                }
            }
            foreach (['normalized_frn', 'frn'] as $field) {
                if (filled($payload[$field] ?? null)) {
                    $frns[(string) $payload[$field]] = true;
                }
            }
            foreach (['normalized_membership_no', 'membership_no'] as $field) {
                if (filled($payload[$field] ?? null)) {
                    $memberships[(string) $payload[$field]] = true;
                }
            }
            foreach (['normalized_mobile', 'normalized_alternate_mobile'] as $field) {
                if (filled($payload[$field] ?? null)) {
                    $phones[(string) $payload[$field]] = true;
                }
            }
        }

        $columns = [
            'ca_id', 'ca_name', 'firm_name', 'city_id', 'state_id',
            'mobile_no', 'normalized_mobile', 'alternate_mobile_no', 'normalized_alternate_mobile',
            'email_id', 'normalized_email', 'gst_no', 'pan_no', 'frn', 'membership_no',
            'address', 'pincode', 'status',
        ];
        if (Schema::hasColumn('ca_masters', 'normalized_firm_name')) {
            $columns[] = 'normalized_firm_name';
        }
        if (Schema::hasColumn('ca_masters', 'normalized_ca_name')) {
            $columns[] = 'normalized_ca_name';
        }
        if (Schema::hasColumn('ca_masters', 'normalized_state')) {
            $columns[] = 'normalized_state';
        }

        $byId = [];
        $collect = function ($rows) use (&$byId): void {
            foreach ($rows as $lead) {
                $byId[(int) $lead->ca_id] = $this->summarize($lead);
            }
        };

        foreach (array_chunk(array_keys($frns), $chunk) as $values) {
            $collect(CaMaster::query()->where(function ($q) use ($values) {
                $q->whereIn('frn', $values);
                foreach ($values as $value) {
                    $q->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(UPPER(COALESCE(frn, '')), '-', ''), ' ', ''), '/', '') = ?",
                        [$value],
                    );
                }
            })->limit(max(50, count($values) * 10))->get($columns));
        }
        foreach (array_chunk(array_keys($memberships), $chunk) as $values) {
            $collect(CaMaster::query()->whereIn('membership_no', $values)->limit(count($values) * 10)->get($columns));
        }
        foreach (array_chunk(array_keys($phones), $chunk) as $values) {
            $collect(CaMaster::query()->where(function ($q) use ($values) {
                $q->whereIn('normalized_mobile', $values)
                    ->orWhereIn('normalized_alternate_mobile', $values)
                    ->orWhereIn('mobile_no', $values);
            })->get($columns));
        }

        $stateList = array_keys($stateIds);
        $hasNormFirm = Schema::hasColumn('ca_masters', 'normalized_firm_name');
        $hasNormCa = Schema::hasColumn('ca_masters', 'normalized_ca_name');

        if ($stateList !== []) {
            foreach (array_chunk(array_keys($firms), $chunk) as $values) {
                $q = CaMaster::query()->whereIn('state_id', $stateList);
                if ($hasNormFirm) {
                    $placeholders = implode(',', array_fill(0, count($values), '?'));
                    $q->whereRaw('UPPER(TRIM(COALESCE(normalized_firm_name, firm_name))) IN ('.$placeholders.')', $values);
                } else {
                    $placeholders = implode(',', array_fill(0, count($values), '?'));
                    $q->whereRaw('UPPER(TRIM(firm_name)) IN ('.$placeholders.')', $values);
                }
                $collect($q->limit(count($values) * 8)->get($columns));
            }

            foreach (array_keys($firmPrefixes) as $prefix) {
                $q = CaMaster::query()->whereIn('state_id', $stateList);
                if ($hasNormFirm) {
                    $q->whereRaw('UPPER(TRIM(COALESCE(normalized_firm_name, firm_name))) LIKE ?', [$prefix.'%']);
                } else {
                    $q->whereRaw('UPPER(TRIM(firm_name)) LIKE ?', [$prefix.'%']);
                }
                $collect($q->limit($fuzzyLimit)->get($columns));
            }

            if ($hasNormCa) {
                foreach (array_chunk(array_keys($caNames), $chunk) as $values) {
                    $placeholders = implode(',', array_fill(0, count($values), '?'));
                    $collect(CaMaster::query()
                        ->whereIn('state_id', $stateList)
                        ->whereRaw('UPPER(TRIM(COALESCE(normalized_ca_name, ca_name))) IN ('.$placeholders.')', $values)
                        ->limit(count($values) * 8)
                        ->get($columns));
                }
                foreach (array_keys($caPrefixes) as $prefix) {
                    $collect(CaMaster::query()
                        ->whereIn('state_id', $stateList)
                        ->whereRaw('UPPER(TRIM(COALESCE(normalized_ca_name, ca_name))) LIKE ?', [$prefix.'%'])
                        ->limit($fuzzyLimit)
                        ->get($columns));
                }
            }
        }

        $this->hydratePartnerNames($byId);

        $index = [
            'profile' => self::PROFILE,
            'by_frn' => [],
            'by_membership' => [],
            'by_phone' => [],
            'by_state_firm' => [],
            'by_state_ca' => [],
            'by_state_firm_prefix' => [],
            'by_id' => $byId,
        ];

        foreach ($byId as $summary) {
            $this->indexSummary($index, $summary, $prefixLen);
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $index
     */
    public function match(array $payload, array $index): MatchResult
    {
        $weights = config('crm_mapping.profiles.state_firm_ca.weights', [
            'firm_exact' => 0.40,
            'ca_exact' => 0.40,
            'firm_fuzzy' => 0.25,
            'ca_fuzzy' => 0.25,
            'city' => 0.05,
        ]);
        $strongCa = (float) config('crm_mapping.profiles.state_firm_ca.strong_ca_similarity', 0.88);
        $strongFirm = (float) config('crm_mapping.profiles.state_firm_ca.strong_firm_similarity', 0.88);
        $autoMin = (float) config('crm_mapping.profiles.state_firm_ca.auto_update_min', 0.90);
        $reviewMin = (float) config('crm_mapping.profiles.state_firm_ca.review_min', 0.70);

        $stateId = isset($payload['state_id']) ? (int) $payload['state_id'] : 0;
        $firmKey = mb_strtoupper((string) ($payload['normalized_firm_name'] ?? ''));
        $caKey = mb_strtoupper((string) ($payload['normalized_ca_name'] ?? ''));

        // 1) Definitive identifiers (FRN / membership always win; phone only when firm/CA agree)
        foreach ([
            ['frn', 'by_frn', $payload['normalized_frn'] ?? ($payload['frn'] ?? null)],
            ['membership_no', 'by_membership', $payload['normalized_membership_no'] ?? ($payload['membership_no'] ?? null)],
        ] as [$matchedOn, $bucket, $value]) {
            if (! filled($value)) {
                continue;
            }
            $hits = $this->uniqueHits($index[$bucket][(string) $value] ?? [], $matchedOn, 1.0);
            if (count($hits) === 1) {
                return MatchResult::exact((int) $hits[0]['ca_id'], (string) $matchedOn, $hits);
            }
            if (count($hits) > 1) {
                return MatchResult::conflict($hits, 'multiple_exact_'.$matchedOn);
            }
        }

        $phone = $payload['normalized_mobile'] ?? null;
        if (filled($phone)) {
            $phoneHits = $this->uniqueHits($index['by_phone'][(string) $phone] ?? [], 'phone', 1.0);
            if (count($phoneHits) > 1) {
                return MatchResult::conflict($phoneHits, 'multiple_exact_phone');
            }
            if (count($phoneHits) === 1) {
                $phoneHit = $phoneHits[0];
                $phoneSummary = $index['by_id'][(int) $phoneHit['ca_id']] ?? null;
                // Rematch by mobile only when sales firm/CA agree with that Master.
                // Otherwise continue to state+firm+CA; apply layer conflicts if mobile owned elsewhere.
                if ($phoneSummary && $this->phoneOwnerCompatibleWithSalesNames($firmKey, $caKey, $phoneSummary)) {
                    return MatchResult::exact((int) $phoneHit['ca_id'], 'phone', $phoneHits);
                }
            }
        }

        if ($stateId < 1) {
            return MatchResult::unmatched('missing_state');
        }
        if ($firmKey === '' && $caKey === '') {
            return MatchResult::unmatched('missing_firm_and_ca_name');
        }

        // 2) Exact firm + exact CA + exact state
        if ($firmKey !== '' && $caKey !== '') {
            $exact = [];
            foreach ($index['by_state_firm'][$stateId.'|'.$firmKey] ?? [] as $summary) {
                if ($this->caMatches($caKey, $summary)) {
                    $exact[(int) $summary['ca_id']] = $this->candidate($summary, 1.0, 'state_firm_ca_exact', 1.0, 1.0);
                }
            }
            if (count($exact) === 1) {
                $hit = array_values($exact)[0];

                return MatchResult::exact((int) $hit['ca_id'], 'state_firm_ca_exact', array_values($exact));
            }
            if (count($exact) > 1) {
                return MatchResult::conflict(array_values($exact), 'multiple_state_firm_ca');
            }
        }

        // Gather state-scoped shortlist for fuzzy scoring
        $shortlist = [];
        if ($firmKey !== '') {
            foreach ($index['by_state_firm'][$stateId.'|'.$firmKey] ?? [] as $summary) {
                $shortlist[(int) $summary['ca_id']] = $summary;
            }
            $prefixLen = (int) config('crm_mapping.profiles.state_firm_ca.prefix_length', 8);
            $prefix = mb_substr($firmKey, 0, $prefixLen);
            foreach ($index['by_state_firm_prefix'][$stateId.'|'.$prefix] ?? [] as $summary) {
                $shortlist[(int) $summary['ca_id']] = $summary;
            }
        }
        if ($caKey !== '') {
            foreach ($index['by_state_ca'][$stateId.'|'.$caKey] ?? [] as $summary) {
                $shortlist[(int) $summary['ca_id']] = $summary;
            }
        }

        if ($shortlist === []) {
            return MatchResult::unmatched('no_state_candidates');
        }

        $scored = [];
        foreach ($shortlist as $summary) {
            if ((int) ($summary['state_id'] ?? 0) !== $stateId) {
                continue;
            }
            $candFirm = mb_strtoupper((string) ($summary['normalized_firm_name'] ?: $this->normalizer->firmName($summary['firm_name'] ?? '') ?: ''));
            $candCa = mb_strtoupper((string) ($summary['normalized_ca_name'] ?: $this->normalizer->caName($summary['ca_name'] ?? '') ?: ''));

            $firmExact = $firmKey !== '' && $candFirm !== '' && $firmKey === $candFirm;
            $caExact = $caKey !== '' && $this->caMatches($caKey, $summary);

            $firmSim = 0.0;
            if ($firmKey !== '' && $candFirm !== '') {
                if ($firmExact) {
                    $firmSim = 1.0;
                } else {
                    similar_text(mb_strtolower($firmKey), mb_strtolower($candFirm), $pct);
                    $firmSim = round($pct / 100, 4);
                }
            }
            $caSim = 0.0;
            if ($caKey !== '') {
                if ($caExact) {
                    $caSim = 1.0;
                } else {
                    $best = 0.0;
                    foreach ($this->caNameVariants($summary) as $variant) {
                        similar_text(mb_strtolower($caKey), mb_strtolower($variant), $pct);
                        $best = max($best, round($pct / 100, 4));
                    }
                    $caSim = $best;
                }
            }

            $score = 0.0;
            $matchedOn = 'state_firm_ca_fuzzy';
            if ($firmExact && $caSim >= $strongCa) {
                $score = min(0.98, ($weights['firm_exact'] ?? 0.4) + ($weights['ca_fuzzy'] ?? 0.25) + ($caSim * 0.2));
                $matchedOn = 'state_firm_strong_ca';
            } elseif ($caExact && $firmSim >= $strongFirm) {
                $score = min(0.98, ($weights['ca_exact'] ?? 0.4) + ($weights['firm_fuzzy'] ?? 0.25) + ($firmSim * 0.2));
                $matchedOn = 'state_ca_strong_firm';
            } else {
                if ($firmExact) {
                    $score += (float) ($weights['firm_exact'] ?? 0.4);
                } elseif ($firmSim > 0) {
                    $score += (float) ($weights['firm_fuzzy'] ?? 0.25) * $firmSim;
                }
                if ($caExact) {
                    $score += (float) ($weights['ca_exact'] ?? 0.4);
                } elseif ($caSim > 0) {
                    $score += (float) ($weights['ca_fuzzy'] ?? 0.25) * $caSim;
                }
                if (! empty($payload['city_id']) && (int) ($summary['city_id'] ?? 0) === (int) $payload['city_id']) {
                    $score += (float) ($weights['city'] ?? 0.05);
                }
                $matchedOn = 'state_combined_similarity';
            }

            $score = round(min(1.0, $score), 4);
            if ($score < $reviewMin) {
                continue;
            }

            // CA name alone must not auto-map (require firm signal).
            if ($firmKey === '' && ! $firmExact && $firmSim < $strongFirm) {
                continue;
            }

            $scored[] = $this->candidate($summary, $score, $matchedOn, $firmSim, $caSim);
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $scored = array_slice($scored, 0, 5);

        if ($scored === []) {
            return MatchResult::unmatched('weak_name_match');
        }

        $top = $scored[0];
        $second = $scored[1]['score'] ?? 0.0;
        if (count($scored) > 1 && abs($top['score'] - $second) < 0.05) {
            return MatchResult::conflict($scored, 'ambiguous_state_firm_ca');
        }

        if ($top['score'] >= $autoMin && in_array($top['matched_on'], ['state_firm_strong_ca', 'state_ca_strong_firm', 'state_firm_ca_exact'], true)) {
            return MatchResult::exact((int) $top['ca_id'], (string) $top['matched_on'], $scored);
        }

        if ($top['score'] >= $autoMin) {
            return MatchResult::possible($scored, (float) $top['score'], (string) $top['matched_on']);
        }

        return MatchResult::possible($scored, (float) $top['score'], (string) $top['matched_on']);
    }

    /**
     * @param  list<array<string, mixed>>  $summaries
     * @return list<array<string, mixed>>
     */
    private function uniqueHits(array $summaries, string $matchedOn, float $score): array
    {
        $hits = [];
        foreach ($summaries as $summary) {
            $caId = (int) $summary['ca_id'];
            $hits[$caId] = $this->candidate($summary, $score, $matchedOn, $score, $score);
        }

        return array_values($hits);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function candidate(array $summary, float $score, string $matchedOn, float $firmSim, float $caSim): array
    {
        return [
            'ca_id' => (int) $summary['ca_id'],
            'score' => $score,
            'matched_on' => $matchedOn,
            'firm_name' => $summary['firm_name'] ?? null,
            'ca_name' => $summary['ca_name'] ?? null,
            'firm_similarity' => $firmSim,
            'ca_similarity' => $caSim,
            'state_id' => $summary['state_id'] ?? null,
        ];
    }

    /**
     * Existing CRM mobile may rematch the same Master; conflicting firm/CA → conflict later.
     *
     * @param  array<string, mixed>  $summary
     */
    private function phoneOwnerCompatibleWithSalesNames(string $firmKey, string $caKey, array $summary): bool
    {
        if ($firmKey === '' && $caKey === '') {
            return true;
        }

        $ownerFirm = mb_strtoupper((string) ($summary['normalized_firm_name']
            ?: $this->normalizer->firmName($summary['firm_name'] ?? '')
            ?: ''));
        $firmOk = $firmKey === '' || ($ownerFirm !== '' && $firmKey === $ownerFirm);
        if (! $firmOk && $firmKey !== '' && $ownerFirm !== '') {
            similar_text(mb_strtolower($firmKey), mb_strtolower($ownerFirm), $pct);
            $firmOk = ($pct / 100) >= (float) config('crm_mapping.profiles.state_firm_ca.strong_firm_similarity', 0.88);
        }
        $caOk = $caKey === '' || $this->caMatches($caKey, $summary);

        // Compatible when firm agrees, or firm omitted and CA agrees with owner/partners.
        if ($firmKey !== '') {
            return $firmOk;
        }

        return $caOk;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function caMatches(string $normalizedIncoming, array $summary): bool
    {
        foreach ($this->caNameVariants($summary) as $variant) {
            if ($variant === $normalizedIncoming) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return list<string>
     */
    private function caNameVariants(array $summary): array
    {
        $variants = [];
        $primary = mb_strtoupper((string) ($summary['normalized_ca_name']
            ?: $this->normalizer->caName($summary['ca_name'] ?? '')
            ?: ''));
        if ($primary !== '') {
            $variants[] = $primary;
            $variants[] = $this->reorderName($primary);
        }
        foreach ($summary['partner_names'] ?? [] as $partner) {
            $norm = mb_strtoupper((string) ($this->normalizer->caName((string) $partner) ?: ''));
            if ($norm !== '') {
                $variants[] = $norm;
                $variants[] = $this->reorderName($norm);
            }
        }

        return array_values(array_unique(array_filter($variants)));
    }

    private function reorderName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (count($parts) < 2) {
            return $name;
        }

        return mb_strtoupper(trim($parts[count($parts) - 1].' '.implode(' ', array_slice($parts, 0, -1))));
    }

    /**
     * @param  array<string, mixed>  $index
     * @param  array<string, mixed>  $summary
     */
    private function indexSummary(array &$index, array $summary, int $prefixLen): void
    {
        if (filled($summary['frn'] ?? null)) {
            $frn = (string) $summary['frn'];
            $index['by_frn'][$frn][] = $summary;
            $frnNorm = $this->normalizer->frn($frn);
            if ($frnNorm) {
                $index['by_frn'][$frnNorm][] = $summary;
            }
        }
        if (filled($summary['membership_no'] ?? null)) {
            $mem = (string) $summary['membership_no'];
            $index['by_membership'][$mem][] = $summary;
            $memNorm = $this->normalizer->membershipNumber($mem);
            if ($memNorm) {
                $index['by_membership'][$memNorm][] = $summary;
            }
        }
        foreach (['normalized_mobile', 'normalized_alternate_mobile', 'mobile_no'] as $phoneField) {
            if (filled($summary[$phoneField] ?? null)) {
                $index['by_phone'][(string) $summary[$phoneField]][] = $summary;
            }
        }

        $stateId = (int) ($summary['state_id'] ?? 0);
        $firmKey = mb_strtoupper((string) ($summary['normalized_firm_name']
            ?: $this->normalizer->firmName($summary['firm_name'] ?? '')
            ?: ''));
        $caKey = mb_strtoupper((string) ($summary['normalized_ca_name']
            ?: $this->normalizer->caName($summary['ca_name'] ?? '')
            ?: ''));

        if ($stateId > 0 && $firmKey !== '') {
            $index['by_state_firm'][$stateId.'|'.$firmKey][] = $summary;
            if (mb_strlen($firmKey) >= $prefixLen) {
                $index['by_state_firm_prefix'][$stateId.'|'.mb_substr($firmKey, 0, $prefixLen)][] = $summary;
            }
        }
        if ($stateId > 0 && $caKey !== '') {
            $index['by_state_ca'][$stateId.'|'.$caKey][] = $summary;
        }
        foreach ($summary['partner_names'] ?? [] as $partnerName) {
            $partnerKey = mb_strtoupper((string) ($this->normalizer->caName((string) $partnerName) ?: ''));
            if ($stateId > 0 && $partnerKey !== '') {
                $index['by_state_ca'][$stateId.'|'.$partnerKey][] = $summary;
            }
        }
    }

    /**
     * Attach partner/member names from ca_reference when available (match against firm partners).
     *
     * @param  array<int, array<string, mixed>>  $byId
     */
    private function hydratePartnerNames(array &$byId): void
    {
        if ($byId === []) {
            return;
        }

        try {
            if (! Schema::connection('ca_reference')->hasTable('ca_firms')
                || ! Schema::connection('ca_reference')->hasTable('ca_partners')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $frns = [];
        $firmNames = [];
        foreach ($byId as $summary) {
            if (filled($summary['frn'] ?? null)) {
                $frns[(string) $summary['frn']] = true;
            }
            $firm = mb_strtoupper(trim((string) ($summary['firm_name'] ?? '')));
            if ($firm !== '') {
                $firmNames[$firm] = true;
            }
        }

        if ($frns === [] && $firmNames === []) {
            return;
        }

        try {
            $firms = \App\Models\CaFirm::query()
                ->with('partners:id,firm_id,partner_name')
                ->where(function ($q) use ($frns, $firmNames) {
                    if ($frns !== []) {
                        $q->whereIn('frn', array_keys($frns));
                    }
                    if ($firmNames !== []) {
                        $placeholders = implode(',', array_fill(0, count($firmNames), '?'));
                        $method = $frns !== [] ? 'orWhereRaw' : 'whereRaw';
                        $q->{$method}('UPPER(TRIM(firm_name)) IN ('.$placeholders.')', array_keys($firmNames));
                    }
                })
                ->limit(max(50, count($byId) * 3))
                ->get(['id', 'firm_name', 'frn']);
        } catch (\Throwable) {
            return;
        }

        $byFrn = [];
        $byFirm = [];
        foreach ($firms as $firm) {
            $names = $firm->partners->pluck('partner_name')->filter()->values()->all();
            if ($names === []) {
                continue;
            }
            if (filled($firm->frn)) {
                $byFrn[(string) $firm->frn] = $names;
            }
            $key = mb_strtoupper(trim((string) $firm->firm_name));
            if ($key !== '') {
                $byFirm[$key] = $names;
            }
        }

        foreach ($byId as $caId => $summary) {
            $names = [];
            if (filled($summary['frn'] ?? null) && isset($byFrn[(string) $summary['frn']])) {
                $names = $byFrn[(string) $summary['frn']];
            } else {
                $firmKey = mb_strtoupper(trim((string) ($summary['firm_name'] ?? '')));
                if ($firmKey !== '' && isset($byFirm[$firmKey])) {
                    $names = $byFirm[$firmKey];
                }
            }
            $byId[$caId]['partner_names'] = array_values(array_unique(array_filter($names)));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(CaMaster $lead): array
    {
        $normalizedFirm = Schema::hasColumn('ca_masters', 'normalized_firm_name')
            ? ($lead->normalized_firm_name ?: $this->normalizer->firmName($lead->firm_name))
            : $this->normalizer->firmName($lead->firm_name);
        $normalizedCa = Schema::hasColumn('ca_masters', 'normalized_ca_name')
            ? ($lead->normalized_ca_name ?: $this->normalizer->caName($lead->ca_name))
            : $this->normalizer->caName($lead->ca_name);

        return [
            'ca_id' => (int) $lead->ca_id,
            'ca_name' => $lead->ca_name,
            'firm_name' => $lead->firm_name,
            'normalized_firm_name' => $normalizedFirm ? mb_strtoupper($normalizedFirm) : null,
            'normalized_ca_name' => $normalizedCa ? mb_strtoupper($normalizedCa) : null,
            'city_id' => $lead->city_id ? (int) $lead->city_id : null,
            'state_id' => $lead->state_id ? (int) $lead->state_id : null,
            'mobile_no' => $lead->mobile_no,
            'normalized_mobile' => $lead->normalized_mobile,
            'normalized_alternate_mobile' => $lead->normalized_alternate_mobile,
            'frn' => $lead->frn,
            'membership_no' => $lead->membership_no,
            'partner_names' => [],
        ];
    }
}
