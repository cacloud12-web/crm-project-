<?php

namespace App\Services\Ocr;

/**
 * Single source of truth for Needs Review reason codes (staging status).
 */
class OcrNeedsReviewClassifier
{
    public const COMPLETE_BUT_STATUS_STALE = 'complete_but_status_stale';

    public const MISSING_CA_IN_STORED_OCR = 'missing_ca_in_stored_ocr';

    public const MISSING_CITY_IN_STORED_OCR = 'missing_city_in_stored_ocr';

    public const CA_PARSER_DROPPED = 'ca_parser_dropped';

    public const CITY_CONTEXT_NOT_ASSIGNED = 'city_context_not_assigned';

    public const PROPRIETOR_PEEL_CANDIDATE = 'proprietor_peel_candidate';

    public const MEMBER_LINK_CONFLICT = 'member_link_conflict';

    public const CA_VALIDATION_REJECTED = 'ca_validation_rejected';

    public const CITY_VALIDATION_REJECTED = 'city_validation_rejected';

    public const RAW_PARSED_CONFLICT = 'raw_parsed_conflict';

    public const AMBIGUOUS_MEMBER_CANDIDATES = 'ambiguous_member_candidates';

    public const COMPLETE_FIRM_CA_CITY = 'complete_firm_ca_city';

    /**
     * @param  array{
     *   firm_name?: ?string,
     *   ca_name?: ?string,
     *   city?: ?string,
     *   has_ca_in_source?: bool,
     *   has_city_in_source?: bool,
     *   extraction_method?: ?string,
     *   raw_parsed_conflict?: bool,
     *   ambiguous_members?: bool,
     * }  $state
     */
    public function classify(array $state): array
    {
        $firm = trim((string) ($state['firm_name'] ?? ''));
        $ca = trim((string) ($state['ca_name'] ?? ''));
        $city = trim((string) ($state['city'] ?? ''));
        $hasCaSource = (bool) ($state['has_ca_in_source'] ?? false);
        $hasCitySource = (bool) ($state['has_city_in_source'] ?? false);
        $method = (string) ($state['extraction_method'] ?? '');

        if ($firm === '') {
            return [
                'complete' => false,
                'match_status' => 'needs_review',
                'reason' => 'missing_firm_name',
            ];
        }

        if (! empty($state['raw_parsed_conflict'])) {
            return [
                'complete' => $ca !== '' && $city !== '',
                'match_status' => 'needs_review',
                'reason' => self::RAW_PARSED_CONFLICT,
            ];
        }

        if (! empty($state['ambiguous_members'])) {
            return [
                'complete' => false,
                'match_status' => 'needs_review',
                'reason' => self::AMBIGUOUS_MEMBER_CANDIDATES,
            ];
        }

        if ($ca !== '' && $city !== '') {
            return [
                'complete' => true,
                'match_status' => 'verified',
                'reason' => self::COMPLETE_FIRM_CA_CITY,
            ];
        }

        if ($ca === '' && $hasCaSource) {
            $reason = str_contains($method, 'proprietor_name_peel')
                ? self::PROPRIETOR_PEEL_CANDIDATE
                : self::CA_PARSER_DROPPED;

            return [
                'complete' => false,
                'match_status' => 'needs_review',
                'reason' => $reason,
            ];
        }

        if ($city === '' && $hasCitySource) {
            return [
                'complete' => false,
                'match_status' => 'needs_review',
                'reason' => self::CITY_CONTEXT_NOT_ASSIGNED,
            ];
        }

        if ($ca === '') {
            return [
                'complete' => false,
                'match_status' => 'needs_review',
                'reason' => self::MISSING_CA_IN_STORED_OCR,
            ];
        }

        return [
            'complete' => false,
            'match_status' => 'needs_review',
            'reason' => self::MISSING_CITY_IN_STORED_OCR,
        ];
    }

    public function staleCompleteReason(string $currentReason): bool
    {
        $r = mb_strtolower(trim($currentReason));
        if ($r === '' || $r === self::COMPLETE_FIRM_CA_CITY) {
            return true;
        }

        return str_contains($r, 'awaiting')
            || str_contains($r, 'city is required')
            || str_contains($r, 'ca name is required')
            || str_contains($r, 'silent correction')
            || str_contains($r, 'reclassified');
    }
}
