<?php

namespace App\Services\Mapping;

/**
 * Immutable match outcome from MasterDataMatchingService.
 *
 * @phpstan-type Candidate array{ca_id: ?int, score: float, matched_on: string, firm_name: ?string, ca_name: ?string, reference_firm_id?: ?int}
 */
final class MatchResult
{
    public const STATUS_EXACT = 'exact_match';

    public const STATUS_POSSIBLE = 'possible_match';

    public const STATUS_CONFLICT = 'conflict';

    public const STATUS_UNMATCHED = 'unmatched';

    /**
     * @param  list<Candidate>  $candidates
     */
    public function __construct(
        public readonly string $status,
        public readonly float $confidence,
        public readonly ?int $caId,
        public readonly ?string $matchedOn,
        public readonly array $candidates,
        public readonly string $reason,
        public readonly ?int $referenceFirmId = null,
    ) {}

    public static function unmatched(string $reason = 'no_candidates'): self
    {
        return new self(self::STATUS_UNMATCHED, 0.0, null, null, [], $reason);
    }

    /**
     * @param  list<Candidate>  $candidates
     */
    public static function exact(int $caId, string $matchedOn, array $candidates = [], ?int $referenceFirmId = null): self
    {
        if ($candidates === []) {
            $candidates = [[
                'ca_id' => $caId,
                'score' => 1.0,
                'matched_on' => $matchedOn,
                'firm_name' => null,
                'ca_name' => null,
                'reference_firm_id' => $referenceFirmId,
            ]];
        }

        return new self(self::STATUS_EXACT, 1.0, $caId, $matchedOn, $candidates, $matchedOn, $referenceFirmId);
    }

    /**
     * Exact official CA reference hit (firm + CA + city). Optional linked Master ca_id.
     *
     * @param  list<Candidate>  $candidates
     */
    public static function exactReference(int $referenceFirmId, string $matchedOn, array $candidates = [], ?int $caId = null): self
    {
        if ($candidates === []) {
            $candidates = [[
                'ca_id' => $caId,
                'score' => 1.0,
                'matched_on' => $matchedOn,
                'firm_name' => null,
                'ca_name' => null,
                'reference_firm_id' => $referenceFirmId,
            ]];
        }

        return new self(self::STATUS_EXACT, 1.0, $caId, $matchedOn, $candidates, $matchedOn, $referenceFirmId);
    }

    /**
     * @param  list<Candidate>  $candidates
     */
    public static function conflict(array $candidates, string $reason = 'multiple_exact'): self
    {
        return new self(self::STATUS_CONFLICT, 1.0, null, null, $candidates, $reason);
    }

    /**
     * @param  list<Candidate>  $candidates
     */
    public static function possible(array $candidates, float $confidence, string $matchedOn): self
    {
        $top = $candidates[0] ?? null;

        return new self(
            self::STATUS_POSSIBLE,
            $confidence,
            $top['ca_id'] ?? null,
            $matchedOn,
            $candidates,
            $matchedOn,
            $top['reference_firm_id'] ?? null,
        );
    }

    public function isExact(): bool
    {
        return $this->status === self::STATUS_EXACT;
    }

    public function isConflict(): bool
    {
        return $this->status === self::STATUS_CONFLICT;
    }

    public function isUnmatched(): bool
    {
        return $this->status === self::STATUS_UNMATCHED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'confidence' => $this->confidence,
            'ca_id' => $this->caId,
            'reference_firm_id' => $this->referenceFirmId,
            'matched_on' => $this->matchedOn,
            'candidates' => $this->candidates,
            'reason' => $this->reason,
        ];
    }
}
