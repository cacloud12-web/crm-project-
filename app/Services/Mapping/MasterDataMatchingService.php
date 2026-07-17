<?php

namespace App\Services\Mapping;

use App\Models\CaMaster;
use Illuminate\Support\Facades\Schema;

/**
 * Scalable Master CA matching for OCR / Excel / CSV / API.
 *
 * Never full-table-scans. Callers must build a batch index via buildIndex()
 * (indexed whereIn + bounded prefix shortlists), then match() each record
 * against that in-memory index.
 */
class MasterDataMatchingService
{
    public const PROFILE_IDENTIFIER_FIRST = 'identifier_first';

    public const PROFILE_STATE_FIRM_CA = 'state_firm_ca';

    public function __construct(
        private readonly DataNormalizationService $normalizer,
        private readonly StateFirmCaMatchingProfile $stateFirmCaProfile,
    ) {}

    /**
     * Normalize an arbitrary source row into a canonical match payload.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function normalizePayload(array $raw): array
    {
        $firmName = (string) ($raw['firm_name'] ?? $raw['raw_firm_name'] ?? '');
        $caName = (string) ($raw['ca_name'] ?? $raw['partner_name'] ?? '');
        $phone = $raw['mobile_no'] ?? ($raw['phone'] ?? ($raw['mobile'] ?? null));
        $email = $raw['email_id'] ?? ($raw['email'] ?? null);
        $gst = $raw['gst_no'] ?? ($raw['gst_number'] ?? null);
        $pan = $raw['pan_no'] ?? ($raw['pan'] ?? null);
        $frn = $raw['frn'] ?? ($raw['frn_number'] ?? null);
        $membership = $raw['membership_no'] ?? ($raw['membership_number'] ?? null);
        $city = $raw['city'] ?? ($raw['city_name'] ?? null);
        $state = $raw['state'] ?? ($raw['state_name'] ?? null);
        $pincode = $raw['pincode'] ?? ($raw['pin_code'] ?? ($raw['postal_code'] ?? null));

        $rawFirm = $firmName !== '' ? trim($firmName) : null;
        $rawCa = $caName !== '' ? trim($caName) : null;
        $rawPhone = (is_string($phone) || is_numeric($phone)) ? trim((string) $phone) : null;
        $altPhone = $raw['alternate_mobile_no'] ?? ($raw['alternate_mobile'] ?? ($raw['alt_mobile'] ?? null));
        $rawAltPhone = (is_string($altPhone) || is_numeric($altPhone)) ? trim((string) $altPhone) : null;
        $rawEmail = is_string($email) ? trim($email) : null;
        $rawGst = is_string($gst) ? trim($gst) : null;
        $rawPan = is_string($pan) ? trim($pan) : null;
        $rawFrn = is_string($frn) ? trim($frn) : null;
        $rawMembership = is_string($membership) ? trim($membership) : null;
        $rawPincode = is_string($pincode) ? trim($pincode) : null;
        $rawCity = is_string($city) ? trim($city) : null;
        $rawState = is_string($state) ? trim($state) : null;
        $normalizedFirm = $this->normalizer->firmName($rawFirm);
        $normalizedCa = $this->normalizer->caName($rawCa);

        // Display/save fields stay raw; normalized_* are match-only keys.
        return [
            'firm_name' => $rawFirm,
            'normalized_firm_name' => $normalizedFirm,
            'ca_name' => $rawCa,
            'normalized_ca_name' => $normalizedCa,
            'mobile_no' => $rawPhone,
            'normalized_mobile' => $this->normalizer->phone($rawPhone),
            'alternate_mobile_no' => $rawAltPhone,
            'normalized_alternate_mobile' => $this->normalizer->phone($rawAltPhone),
            'email_id' => $rawEmail,
            'normalized_email' => $this->normalizer->email($rawEmail),
            'gst_no' => $rawGst,
            'normalized_gst' => $this->normalizer->gst($rawGst),
            'pan_no' => $rawPan,
            'normalized_pan' => $this->normalizer->pan($rawPan),
            'frn' => $rawFrn,
            'normalized_frn' => $this->normalizer->frn($rawFrn),
            'membership_no' => $rawMembership,
            'normalized_membership_no' => $this->normalizer->membershipNumber($rawMembership),
            'address' => isset($raw['address']) ? trim((string) $raw['address']) : null,
            'city' => $rawCity,
            'state' => $rawState,
            'normalized_state' => $this->normalizer->state($rawState),
            'normalized_city' => $this->normalizer->city($rawCity),
            'pincode' => $rawPincode,
            'normalized_pincode' => $this->normalizer->postalCode($rawPincode),
            'website' => isset($raw['website']) ? trim((string) $raw['website']) : null,
            'firm_type' => isset($raw['firm_type']) ? trim((string) $raw['firm_type']) : null,
            'city_id' => isset($raw['city_id']) && is_numeric($raw['city_id']) ? (int) $raw['city_id'] : null,
            'state_id' => isset($raw['state_id']) && is_numeric($raw['state_id']) ? (int) $raw['state_id'] : null,
            'partner_count' => isset($raw['partner_count']) ? (int) $raw['partner_count'] : null,
            'overall_confidence' => isset($raw['overall_confidence']) ? (float) $raw['overall_confidence'] : null,
            'field_meta' => is_array($raw['field_meta'] ?? null) ? $raw['field_meta'] : null,
            'members' => is_array($raw['members'] ?? null) ? $raw['members'] : [],
        ];
    }

    /**
     * Complete enough to auto-create without inventing missing identity.
     *
     * @param  array<string, mixed>  $payload
     */
    public function hasCompleteValidData(array $payload): bool
    {
        $firm = trim((string) ($payload['firm_name'] ?? ''));
        if ($firm === '' || mb_strlen($firm) < 3) {
            return false;
        }

        $hasIdentifier = filled($payload['normalized_gst'] ?? $payload['gst_no'] ?? null)
            || filled($payload['normalized_frn'] ?? $payload['frn'] ?? null)
            || filled($payload['normalized_pan'] ?? $payload['pan_no'] ?? null)
            || filled($payload['normalized_membership_no'] ?? $payload['membership_no'] ?? null);
        $hasContact = filled($payload['normalized_mobile'] ?? null) || filled($payload['normalized_email'] ?? null);
        $hasLocation = filled($payload['address'] ?? null)
            || (filled($payload['city'] ?? null) && filled($payload['pincode'] ?? null));
        $hasPartner = filled($payload['ca_name'] ?? null);

        return $hasIdentifier || $hasContact || $hasLocation || $hasPartner;
    }

    /**
     * Prefetch candidate Master rows for a batch of normalized payloads.
     *
     * @param  list<array<string, mixed>>  $payloads
     * @return array{
     *     by_frn: array<string, list<array<string, mixed>>>,
     *     by_gst: array<string, list<array<string, mixed>>>,
     *     by_pan: array<string, list<array<string, mixed>>>,
     *     by_phone: array<string, list<array<string, mixed>>>,
     *     by_email: array<string, list<array<string, mixed>>>,
     *     by_membership: array<string, list<array<string, mixed>>>,
     *     by_firm: array<string, list<array<string, mixed>>>,
     *     by_firm_prefix: array<string, list<array<string, mixed>>>,
     *     by_id: array<int, array<string, mixed>>
     * }
     */
    /**
     * @param  list<array<string, mixed>>  $payloads
     * @return array<string, mixed>
     */
    public function buildIndex(array $payloads, ?string $profile = null): array
    {
        $profile = $this->resolveProfile($profile);
        if ($profile === self::PROFILE_STATE_FIRM_CA) {
            return $this->stateFirmCaProfile->buildIndex($payloads);
        }

        $chunk = (int) config('crm_mapping.index_chunk_size', 500);
        $prefixLen = (int) config('crm_mapping.fuzzy_prefix_length', 8);
        $fuzzyLimit = (int) config('crm_mapping.fuzzy_prefix_limit', 25);

        $keys = [
            'frn' => [],
            'gst' => [],
            'pan' => [],
            'phone' => [],
            'email' => [],
            'membership' => [],
            'firm' => [],
            'prefix' => [],
        ];

        foreach ($payloads as $payload) {
            foreach ([
                'frn' => 'normalized_frn',
                'gst' => 'normalized_gst',
                'pan' => 'normalized_pan',
                'phone' => 'normalized_mobile',
                'email' => 'normalized_email',
                'membership' => 'normalized_membership_no',
                'firm' => 'normalized_firm_name',
            ] as $bucket => $field) {
                $value = $payload[$field] ?? null;
                if (! filled($value) && $bucket === 'frn') {
                    $value = $payload['frn'] ?? null;
                }
                if (! filled($value) && $bucket === 'gst') {
                    $value = $payload['gst_no'] ?? null;
                }
                if (! filled($value) && $bucket === 'pan') {
                    $value = $payload['pan_no'] ?? null;
                }
                if (! filled($value) && $bucket === 'membership') {
                    $value = $payload['membership_no'] ?? null;
                }
                if (filled($value)) {
                    $keys[$bucket][(string) $value] = true;
                }
            }
            $altMobile = $payload['normalized_alternate_mobile'] ?? null;
            if (filled($altMobile)) {
                $keys['phone'][(string) $altMobile] = true;
            }
            $firm = (string) ($payload['normalized_firm_name'] ?? '');
            if ($firm !== '' && mb_strlen($firm) >= $prefixLen) {
                $keys['prefix'][mb_strtolower(mb_substr($firm, 0, $prefixLen))] = true;
            }
        }

        $byId = [];
        $columns = [
            'ca_id', 'ca_name', 'firm_name', 'city_id', 'state_id',
            'mobile_no', 'normalized_mobile', 'alternate_mobile_no', 'normalized_alternate_mobile',
            'email_id', 'normalized_email', 'gst_no', 'pan_no', 'frn', 'membership_no',
            'address', 'pincode', 'status',
        ];
        if (Schema::hasColumn('ca_masters', 'normalized_firm_name')) {
            $columns[] = 'normalized_firm_name';
        }

        $collect = function ($rows) use (&$byId): void {
            foreach ($rows as $lead) {
                $byId[(int) $lead->ca_id] = $this->summarizeLead($lead);
            }
        };

        foreach (array_chunk(array_keys($keys['frn']), $chunk) as $values) {
            // Indexed equality first; normalized compare covers legacy dashed FRNs.
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
        foreach (array_chunk(array_keys($keys['gst']), $chunk) as $values) {
            $collect(CaMaster::query()->whereIn('gst_no', $values)->get($columns));
        }
        if (Schema::hasColumn('ca_masters', 'pan_no')) {
            foreach (array_chunk(array_keys($keys['pan']), $chunk) as $values) {
                $collect(CaMaster::query()->whereIn('pan_no', $values)->get($columns));
            }
        }
        foreach (array_chunk(array_keys($keys['phone']), $chunk) as $values) {
            $collect(CaMaster::query()->where(function ($q) use ($values) {
                $q->whereIn('normalized_mobile', $values)
                    ->orWhereIn('normalized_alternate_mobile', $values)
                    ->orWhereIn('mobile_no', $values);
            })->get($columns));
        }
        foreach (array_chunk(array_keys($keys['email']), $chunk) as $values) {
            $collect(CaMaster::query()->where(function ($q) use ($values) {
                $q->whereIn('normalized_email', $values)->orWhereIn('email_id', $values);
            })->get($columns));
        }
        foreach (array_chunk(array_keys($keys['membership']), $chunk) as $values) {
            $collect(CaMaster::query()->whereIn('membership_no', $values)->get($columns));
        }

        $hasNormalizedFirm = Schema::hasColumn('ca_masters', 'normalized_firm_name');
        foreach (array_chunk(array_keys($keys['firm']), $chunk) as $values) {
            if ($hasNormalizedFirm) {
                $collect(CaMaster::query()->whereIn('normalized_firm_name', $values)->limit(count($values) * 5)->get($columns));
            }
            $placeholders = implode(',', array_fill(0, count($values), '?'));
            $collect(CaMaster::query()
                ->whereRaw('LOWER(TRIM(firm_name)) IN ('.$placeholders.')', array_map('mb_strtolower', $values))
                ->limit(count($values) * 5)
                ->get($columns));
        }

        // Bounded prefix shortlist for fuzzy firm names (indexed prefix, never full scan).
        if ($hasNormalizedFirm) {
            foreach (array_keys($keys['prefix']) as $prefix) {
                $collect(CaMaster::query()
                    ->where('normalized_firm_name', 'like', $prefix.'%')
                    ->limit($fuzzyLimit)
                    ->get($columns));
            }
        }

        $index = [
            'by_frn' => [],
            'by_gst' => [],
            'by_pan' => [],
            'by_phone' => [],
            'by_email' => [],
            'by_membership' => [],
            'by_firm' => [],
            'by_firm_prefix' => [],
            'by_id' => $byId,
        ];

        foreach ($byId as $summary) {
            $this->indexSummary($index, $summary, $prefixLen);
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $payload  Normalized via normalizePayload()
     * @param  array<string, mixed>  $index    From buildIndex()
     */
    public function match(array $payload, array $index, ?string $profile = null): MatchResult
    {
        $profile = $this->resolveProfile($profile ?? ($index['profile'] ?? null));
        if ($profile === self::PROFILE_STATE_FIRM_CA) {
            return $this->stateFirmCaProfile->match($payload, $index);
        }

        // Strict priority: stop at the first identifier rung that yields hits.
        $exactPriority = [
            ['frn', 'by_frn', $payload['normalized_frn'] ?? ($payload['frn'] ?? null)],
            ['gst', 'by_gst', $payload['normalized_gst'] ?? ($payload['gst_no'] ?? null)],
            ['pan', 'by_pan', $payload['normalized_pan'] ?? ($payload['pan_no'] ?? null)],
            ['phone', 'by_phone', $payload['normalized_mobile'] ?? null],
            ['alternate_mobile', 'by_phone', $payload['normalized_alternate_mobile'] ?? null],
            ['email', 'by_email', $payload['normalized_email'] ?? null],
            ['membership_no', 'by_membership', $payload['normalized_membership_no'] ?? ($payload['membership_no'] ?? null)],
        ];

        foreach ($exactPriority as [$matchedOn, $bucket, $value]) {
            if (! filled($value)) {
                continue;
            }
            $hits = [];
            foreach ($index[$bucket][(string) $value] ?? [] as $summary) {
                $caId = (int) $summary['ca_id'];
                $hits[$caId] = [
                    'ca_id' => $caId,
                    'score' => 1.0,
                    'matched_on' => $matchedOn,
                    'firm_name' => $summary['firm_name'],
                    'ca_name' => $summary['ca_name'],
                ];
            }
            if (count($hits) === 1) {
                $hit = array_values($hits)[0];

                return MatchResult::exact((int) $hit['ca_id'], (string) $matchedOn, array_values($hits));
            }
            if (count($hits) > 1) {
                return MatchResult::conflict(array_values($hits), 'multiple_exact_'.$matchedOn);
            }
        }

        $firmKey = (string) ($payload['normalized_firm_name'] ?? '');
        if ($firmKey === '') {
            return MatchResult::unmatched('missing_firm_name');
        }

        $firmHits = $index['by_firm'][mb_strtolower($firmKey)] ?? [];
        // Firm name + city is its own rung before fuzzy.
        if ($firmHits !== [] && ! empty($payload['city_id'])) {
            $cityHits = array_values(array_filter(
                $firmHits,
                fn (array $hit) => (int) ($hit['city_id'] ?? 0) === (int) $payload['city_id'],
            ));
            if (count($cityHits) === 1) {
                $hit = $cityHits[0];

                return MatchResult::exact((int) $hit['ca_id'], 'firm_name_city', [[
                    'ca_id' => (int) $hit['ca_id'],
                    'score' => 1.0,
                    'matched_on' => 'firm_name_city',
                    'firm_name' => $hit['firm_name'],
                    'ca_name' => $hit['ca_name'],
                ]]);
            }
            if (count($cityHits) > 1) {
                return MatchResult::conflict(array_map(fn (array $hit) => [
                    'ca_id' => (int) $hit['ca_id'],
                    'score' => 0.95,
                    'matched_on' => 'firm_name_city',
                    'firm_name' => $hit['firm_name'],
                    'ca_name' => $hit['ca_name'],
                ], $cityHits), 'multiple_firm_city');
            }
        }

        if (count($firmHits) === 1) {
            $hit = $firmHits[0];
            $score = 0.92;

            return MatchResult::possible([[
                'ca_id' => (int) $hit['ca_id'],
                'score' => $score,
                'matched_on' => 'normalized_firm_name',
                'firm_name' => $hit['firm_name'],
                'ca_name' => $hit['ca_name'],
            ]], $score, 'normalized_firm_name');
        }
        if (count($firmHits) > 1) {
            $candidates = array_map(fn (array $hit) => [
                'ca_id' => (int) $hit['ca_id'],
                'score' => 0.88,
                'matched_on' => 'normalized_firm_name',
                'firm_name' => $hit['firm_name'],
                'ca_name' => $hit['ca_name'],
            ], $firmHits);

            return MatchResult::conflict($candidates, 'multiple_firm_name');
        }

        // Fuzzy: score prefix shortlist by similar_text on normalized names.
        $prefixLen = (int) config('crm_mapping.fuzzy_prefix_length', 8);
        $prefix = mb_strtolower(mb_substr($firmKey, 0, $prefixLen));
        $shortlist = $index['by_firm_prefix'][$prefix] ?? [];
        $scored = [];
        foreach ($shortlist as $hit) {
            $candidateFirm = (string) ($hit['normalized_firm_name'] ?: $this->normalizer->firmName($hit['firm_name'] ?? ''));
            if ($candidateFirm === '') {
                continue;
            }
            similar_text(mb_strtolower($firmKey), mb_strtolower($candidateFirm), $percent);
            $score = round($percent / 100, 4);
            if ($score < (float) config('crm_mapping.review_min_confidence', 0.55)) {
                continue;
            }
            if (! empty($payload['city_id']) && (int) ($hit['city_id'] ?? 0) === (int) $payload['city_id']) {
                $score = min(0.95, $score + 0.05);
            }
            $partnerBoost = $this->partnerNameBoost($payload, $hit);
            $score = min(0.95, $score + $partnerBoost);
            $scored[] = [
                'ca_id' => (int) $hit['ca_id'],
                'score' => $score,
                'matched_on' => 'fuzzy_firm_name',
                'firm_name' => $hit['firm_name'],
                'ca_name' => $hit['ca_name'],
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $scored = array_slice($scored, 0, 5);

        if ($scored === []) {
            return MatchResult::unmatched('no_candidates');
        }

        $top = $scored[0];
        $second = $scored[1]['score'] ?? 0.0;
        if (count($scored) > 1 && abs($top['score'] - $second) < 0.05) {
            return MatchResult::conflict($scored, 'ambiguous_fuzzy');
        }

        return MatchResult::possible($scored, (float) $top['score'], 'fuzzy_firm_name');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $hit
     */
    private function partnerNameBoost(array $payload, array $hit): float
    {
        $incoming = mb_strtolower((string) ($payload['normalized_ca_name'] ?? $payload['ca_name'] ?? ''));
        $existing = mb_strtolower((string) ($hit['ca_name'] ?? ''));
        if ($incoming === '' || $existing === '') {
            return 0.0;
        }
        if ($incoming === $existing) {
            return 0.08;
        }
        similar_text($incoming, $existing, $percent);

        return $percent >= 80 ? 0.04 : 0.0;
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
            if ($frnNorm && $frnNorm !== $frn) {
                $index['by_frn'][$frnNorm][] = $summary;
            }
        }
        if (filled($summary['gst_no'] ?? null)) {
            $gst = (string) $summary['gst_no'];
            $index['by_gst'][$gst][] = $summary;
            $gstNorm = $this->normalizer->gst($gst);
            if ($gstNorm && $gstNorm !== $gst) {
                $index['by_gst'][$gstNorm][] = $summary;
            }
        }
        if (filled($summary['pan_no'] ?? null)) {
            $pan = (string) $summary['pan_no'];
            $index['by_pan'][$pan][] = $summary;
            $panNorm = $this->normalizer->pan($pan);
            if ($panNorm && $panNorm !== $pan) {
                $index['by_pan'][$panNorm][] = $summary;
            }
        }
        foreach (['normalized_mobile', 'normalized_alternate_mobile', 'mobile_no'] as $phoneField) {
            if (filled($summary[$phoneField] ?? null)) {
                $index['by_phone'][(string) $summary[$phoneField]][] = $summary;
            }
        }
        foreach (['normalized_email', 'email_id'] as $emailField) {
            if (filled($summary[$emailField] ?? null)) {
                $index['by_email'][mb_strtolower((string) $summary[$emailField])][] = $summary;
            }
        }
        if (filled($summary['membership_no'] ?? null)) {
            $index['by_membership'][(string) $summary['membership_no']][] = $summary;
        }

        $firmKey = mb_strtolower((string) ($summary['normalized_firm_name']
            ?: $this->normalizer->firmName($summary['firm_name'] ?? '')
            ?: ''));
        if ($firmKey !== '') {
            $index['by_firm'][$firmKey][] = $summary;
            if (mb_strlen($firmKey) >= $prefixLen) {
                $prefix = mb_substr($firmKey, 0, $prefixLen);
                $index['by_firm_prefix'][$prefix][] = $summary;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeLead(CaMaster $lead): array
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
            'normalized_firm_name' => $normalizedFirm,
            'normalized_ca_name' => $normalizedCa,
            'city_id' => $lead->city_id ? (int) $lead->city_id : null,
            'state_id' => $lead->state_id ? (int) $lead->state_id : null,
            'mobile_no' => $lead->mobile_no,
            'normalized_mobile' => $lead->normalized_mobile,
            'normalized_alternate_mobile' => $lead->normalized_alternate_mobile,
            'email_id' => $lead->email_id,
            'normalized_email' => $lead->normalized_email,
            'gst_no' => $lead->gst_no,
            'pan_no' => $lead->pan_no ?? null,
            'frn' => $lead->frn,
            'membership_no' => $lead->membership_no,
            'address' => $lead->address ?? null,
            'pincode' => $lead->pincode ?? null,
            'status' => $lead->status,
        ];
    }

    public function resolveProfile(?string $profile): string
    {
        $profile = $profile ?: (string) config('crm_mapping.default_matching_profile', self::PROFILE_IDENTIFIER_FIRST);
        if ($profile === self::PROFILE_STATE_FIRM_CA || $profile === 'sales_team') {
            return self::PROFILE_STATE_FIRM_CA;
        }

        return self::PROFILE_IDENTIFIER_FIRST;
    }
}
