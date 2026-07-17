<?php

namespace App\Services\Mapping;

use App\Models\CaFirm;
use App\Models\CaPartner;
use Illuminate\Support\Collection;

/**
 * Prepares shortlisted CA Reference matches for staged OCR firms/members.
 *
 * Does NOT auto-merge into CRM or write approved reference rows.
 * Matching runs only after candidate shortlisting — never full-table scans.
 */
class CaReferenceMatchingService
{
    public const STATUS_EXACT_MATCH = 'exact_match';

    public const STATUS_POSSIBLE_MATCH = 'possible_match';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_CONFLICT = 'conflict';

    public const STATUS_UNMATCHED = 'unmatched';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_MERGED = 'merged';

    public function __construct(
        private readonly DataNormalizationService $normalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $incomingFirm
     * @return array{status: string, matches: list<array<string, mixed>>, reason: string}
     */
    public function matchFirm(array $incomingFirm): array
    {
        $frn = $this->normalizer->frn($incomingFirm['frn_number'] ?? ($incomingFirm['frn'] ?? null));
        $gst = $this->normalizer->gst($incomingFirm['gst_number'] ?? ($incomingFirm['gst_no'] ?? null));
        $phone = $this->normalizer->phone($incomingFirm['phone'] ?? null);
        $email = $this->normalizer->email($incomingFirm['email'] ?? null);
        $firmName = $this->normalizer->firmName($incomingFirm['firm_name'] ?? null);
        $city = $this->normalizer->city($incomingFirm['city'] ?? null);

        $candidates = $this->shortlistFirms([
            'frn' => $frn,
            'gst' => $gst,
            'phone' => $phone,
            'email' => $email,
            'firm_name' => $firmName,
            'city' => $city,
            'state' => $this->normalizer->state($incomingFirm['state'] ?? null),
            'postal_code' => $this->normalizer->postalCode($incomingFirm['postal_code'] ?? ($incomingFirm['pincode'] ?? null)),
        ]); // Indexed shortlist only — never scan all reference rows.

        if ($candidates->isEmpty()) {
            return ['status' => self::STATUS_UNMATCHED, 'matches' => [], 'reason' => 'no_candidates'];
        }

        $exact = [];
        foreach ($candidates as $candidate) {
            if ($frn && $this->normalizer->frn($candidate->frn) === $frn) {
                $exact[] = $this->firmMatchPayload($candidate, 'frn', 1.0);
            } elseif ($gst && $this->normalizer->gst($candidate->gst_number) === $gst) {
                $exact[] = $this->firmMatchPayload($candidate, 'gst', 1.0);
            } elseif ($phone && $this->normalizer->phone($candidate->phone) === $phone) {
                $exact[] = $this->firmMatchPayload($candidate, 'phone', 1.0);
            } elseif ($email && $this->normalizer->email($candidate->email) === $email) {
                $exact[] = $this->firmMatchPayload($candidate, 'email', 1.0);
            }
        }

        if (count($exact) === 1) {
            return ['status' => self::STATUS_EXACT_MATCH, 'matches' => $exact, 'reason' => $exact[0]['matched_on']];
        }
        if (count($exact) > 1) {
            return ['status' => self::STATUS_CONFLICT, 'matches' => $exact, 'reason' => 'multiple_exact'];
        }

        $possible = $candidates->take(10)->map(function (CaFirm $candidate) use ($firmName, $city) {
            $score = 0.0;
            $normalizedCandidate = $this->normalizer->firmName($candidate->firm_name);
            if ($firmName && $normalizedCandidate && str_starts_with($normalizedCandidate, mb_substr($firmName, 0, 8))) {
                $score += 0.5;
            }
            if ($city && $this->normalizer->city($candidate->city) === $city) {
                $score += 0.3;
            }

            return $this->firmMatchPayload($candidate, 'firm_name_city', $score);
        })->filter(fn (array $row) => $row['score'] >= 0.5)->values()->all();

        if ($possible !== []) {
            return ['status' => self::STATUS_POSSIBLE_MATCH, 'matches' => $possible, 'reason' => 'shortlist_fuzzy'];
        }

        return ['status' => self::STATUS_NEEDS_REVIEW, 'matches' => [], 'reason' => 'shortlist_no_score'];
    }

    /**
     * @param  array<string, mixed>  $incomingMember
     * @return array{status: string, matches: list<array<string, mixed>>, reason: string}
     */
    public function matchMember(array $incomingMember): array
    {
        $membership = $this->normalizer->membershipNumber(
            $incomingMember['membership_number'] ?? ($incomingMember['membership_no'] ?? null),
        );
        $mobile = $this->normalizer->phone($incomingMember['mobile'] ?? null);
        $email = $this->normalizer->email($incomingMember['email'] ?? null);

        if (! $membership && ! $mobile && ! $email) {
            return ['status' => self::STATUS_UNMATCHED, 'matches' => [], 'reason' => 'no_identifiers'];
        }

        $query = CaPartner::query()->limit(25);
        $query->where(function ($q) use ($membership, $mobile, $email) {
            if ($membership) {
                $q->orWhere('membership_number', $membership);
            }
            if ($mobile) {
                $q->orWhere('mobile', $mobile);
            }
            if ($email) {
                $q->orWhere('email', $email);
            }
        });

        $rows = $query->get();
        if ($rows->isEmpty()) {
            return ['status' => self::STATUS_UNMATCHED, 'matches' => [], 'reason' => 'no_candidates'];
        }

        $exact = [];
        foreach ($rows as $row) {
            if ($membership && $this->normalizer->membershipNumber($row->membership_number) === $membership) {
                $exact[] = [
                    'reference_member_id' => $row->id,
                    'matched_on' => 'membership_number',
                    'score' => 1.0,
                    'ca_name' => $row->partner_name,
                ];
            }
        }

        if (count($exact) === 1) {
            return ['status' => self::STATUS_EXACT_MATCH, 'matches' => $exact, 'reason' => 'membership_number'];
        }
        if (count($exact) > 1) {
            return ['status' => self::STATUS_CONFLICT, 'matches' => $exact, 'reason' => 'multiple_membership'];
        }

        return [
            'status' => self::STATUS_POSSIBLE_MATCH,
            'matches' => $rows->take(10)->map(fn (CaPartner $row) => [
                'reference_member_id' => $row->id,
                'matched_on' => 'shortlist',
                'score' => 0.6,
                'ca_name' => $row->partner_name,
            ])->values()->all(),
            'reason' => 'shortlist',
        ];
    }

    /**
     * @param  array<string, ?string>  $keys
     * @return Collection<int, CaFirm>
     */
    private function shortlistFirms(array $keys): Collection
    {
        $query = CaFirm::query()->limit(50);
        $hasFilter = false;

        $query->where(function ($q) use ($keys, &$hasFilter) {
            if ($keys['frn']) {
                $q->orWhere('frn', $keys['frn']);
                $hasFilter = true;
            }
            if ($keys['gst']) {
                $q->orWhere('gst_number', $keys['gst']);
                $hasFilter = true;
            }
            if ($keys['phone']) {
                $q->orWhere('phone', $keys['phone']);
                $hasFilter = true;
            }
            if ($keys['email']) {
                $q->orWhere('email', $keys['email']);
                $hasFilter = true;
            }
            if ($keys['city']) {
                $q->orWhere('city', $keys['city']);
                $hasFilter = true;
            }
            if ($keys['state']) {
                $q->orWhere('state', $keys['state']);
                $hasFilter = true;
            }
            if ($keys['postal_code']) {
                $q->orWhere('pin_code', $keys['postal_code']);
                $hasFilter = true;
            }
            if ($keys['firm_name']) {
                $prefix = mb_substr($keys['firm_name'], 0, 12);
                if ($prefix !== '') {
                    $q->orWhere('firm_name', 'like', $prefix.'%');
                    $hasFilter = true;
                }
            }
        });

        if (! $hasFilter) {
            return collect();
        }

        return $query->get();
    }

    /**
     * @return array{reference_firm_id: int, matched_on: string, score: float, firm_name: ?string}
     */
    private function firmMatchPayload(CaFirm $candidate, string $matchedOn, float $score): array
    {
        return [
            'reference_firm_id' => $candidate->id,
            'matched_on' => $matchedOn,
            'score' => $score,
            'firm_name' => $candidate->firm_name,
        ];
    }
}
