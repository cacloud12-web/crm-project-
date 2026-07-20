<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\OcrParsedFirm */
class OcrParsedFirmResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if (config('ocr_workflow.mode', 'firm_ca_city') === 'firm_ca_city') {
            return $this->threeFieldReviewPayload($request);
        }

        return $this->legacyPayload($request);
    }

    /**
     * Production OCR review payload — Firm Name, CA Name, City only.
     *
     * @return array<string, mixed>
     */
    private function threeFieldReviewPayload(Request $request): array
    {
        $source = is_array($this->source_data) ? $this->source_data : [];
        $raw = is_array($source['raw'] ?? null) ? $source['raw'] : [];
        $parsed = is_array($source['parsed'] ?? null) ? $source['parsed'] : [];

        $firmName = $this->firm_name ?: ($parsed['firm_name'] ?? ($raw['firm_name'] ?? null));
        // Prefer parsed (Unicode-normalized Latin) over raw confusable OCR glyphs.
        $caName = $parsed['ca_name'] ?? ($source['ca_name'] ?? null);
        if ($caName === null || $caName === '') {
            $caName = $this->relationLoaded('members')
                ? ($this->members->first()?->ca_name ?: $this->members->first()?->raw_ca_name)
                : null;
        }
        $city = $this->city ?: ($parsed['city'] ?? ($raw['city'] ?? null));
        $partners = $this->partnerNamesFromMembers($source, $parsed, $caName);
        $partnerCount = count($partners);

        $userMessage = $this->userFacingMessage($source);
        $status = $this->userFacingStatus($source);
        $matchType = $this->userFacingMatchType($source);
        $canAct = $this->canActOnReview($status);

        return [
            'id' => $this->id,
            'firm_name' => $firmName,
            'ca_name' => $caName,
            'city' => $city,
            'partners' => $partners,
            'partner_count' => $partnerCount,
            'directory_profile' => $source['directory_profile'] ?? ($parsed['directory_profile'] ?? null),
            'raw_firm_name' => $raw['firm_name'] ?? $this->raw_firm_name,
            'raw_ca_name' => $raw['ca_name'] ?? null,
            'raw_city' => $raw['city'] ?? null,
            'normalized_firm_name' => $this->normalized_firm_name
                ?: ($source['normalized']['firm_name'] ?? null),
            'normalized_ca_name' => $source['normalized']['ca_name'] ?? null,
            'normalized_city' => $source['normalized']['city'] ?? null,
            'page_number' => $this->page_number,
            'column_number' => $this->column_number ?? ($source['column_number'] ?? null),
            'row_number' => $this->row_number ?? $this->sequence_no,
            'validation_status' => $status,
            'validation_errors' => is_array($this->validation_errors) ? $this->validation_errors : [],
            'match_type' => $matchType,
            'match_status' => $this->match_status,
            'matched_master_id' => $this->matched_ca_id ?: $this->crm_ca_id,
            'status' => $status,
            'user_message' => $userMessage,
            'can_approve' => $canAct,
            'can_correct' => $canAct,
            'can_reject' => $canAct,
            'review_status' => $this->review_status,
            'crm_ca_id' => $this->crm_ca_id,
            'ca_id' => $this->crm_ca_id,
            'source_fingerprint' => $this->source_fingerprint ?? ($source['source_fingerprint'] ?? null),
        ];
    }

    /**
     * Partners exclude primary CA (ca_name). Source: members after first, or parsed.partners.
     *
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $parsed
     * @return list<string>
     */
    private function partnerNamesFromMembers(array $source, array $parsed, mixed $caName): array
    {
        if (is_array($parsed['partners'] ?? null) && $parsed['partners'] !== []) {
            return array_values(array_filter(array_map(
                static fn ($n) => trim((string) $n),
                $parsed['partners'],
            ), static fn ($n) => $n !== ''));
        }
        if (is_array($source['partners'] ?? null) && $source['partners'] !== []) {
            return array_values(array_filter(array_map(
                static fn ($n) => trim((string) $n),
                $source['partners'],
            ), static fn ($n) => $n !== ''));
        }
        if (! $this->relationLoaded('members') || $this->members->isEmpty()) {
            return [];
        }
        $primary = mb_strtolower(trim((string) ($caName ?? '')));
        $out = [];
        foreach ($this->members as $i => $member) {
            $name = trim((string) ($member->ca_name ?: $member->raw_ca_name ?: ''));
            if ($name === '') {
                continue;
            }
            if ($i === 0 || ($primary !== '' && mb_strtolower($name) === $primary) || $member->is_primary) {
                if ($i === 0 || $member->is_primary) {
                    continue;
                }
            }
            if ($primary !== '' && mb_strtolower($name) === $primary) {
                continue;
            }
            $out[] = $name;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function userFacingStatus(array $source): string
    {
        $review = (string) ($this->review_status ?? '');
        $match = (string) ($this->match_status ?? '');

        if ($review === 'rejected' || $match === 'rejected') {
            return 'Rejected';
        }
        if (in_array($match, ['imported', 'updated_official', 'duplicate', 'auto_mapped', 'auto_created'], true)
            || ($review === 'approved' && $this->crm_ca_id)) {
            return 'Verified';
        }
        if ($match === 'verified' || $match === 'matched' || ($source['match_type'] ?? null) === 'EXACT_VERIFIED') {
            return 'Verified';
        }
        if ($match === 'conflict') {
            return 'Conflict';
        }

        // Three-field OCR: any row with a firm name is Verified for review UI.
        // Missing CA/city stay editable via Correct — never Invalid / Needs Review.
        $firm = trim((string) ($this->firm_name ?: (($source['parsed']['firm_name'] ?? '') ?: '')));
        if ($firm !== '') {
            return 'Verified';
        }

        return 'Needs Review';
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function hasCompleteThreeFields(array $source): bool
    {
        $parsed = is_array($source['parsed'] ?? null) ? $source['parsed'] : [];
        $firm = trim((string) ($this->firm_name ?: ($parsed['firm_name'] ?? '')));
        $ca = trim((string) ($parsed['ca_name'] ?? ($source['ca_name'] ?? '')));
        if ($ca === '' && $this->relationLoaded('members')) {
            $ca = trim((string) ($this->members->first()?->ca_name ?: $this->members->first()?->raw_ca_name ?: ''));
        }
        $city = trim((string) ($this->city ?: ($parsed['city'] ?? '')));

        return $firm !== '' && $ca !== '' && $city !== '';
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function userFacingMatchType(array $source): string
    {
        $type = (string) ($source['match_type'] ?? '');
        $match = (string) ($this->match_status ?? '');

        if ($type === 'EXACT_VERIFIED' || $match === 'verified' || $match === 'matched'
            || in_array($match, ['imported', 'updated_official', 'duplicate'], true)) {
            return 'Exact verified';
        }
        if ($type === 'CONFLICT' || $match === 'conflict' || $type === 'MULTIPLE_EXACT_MATCHES') {
            return 'Multiple Matches';
        }
        if ($type === 'INCOMPLETE_SCOPED_FIELDS') {
            return 'Not Checked';
        }
        if ($type === 'SCOPED_LAYOUT_UNCERTAIN') {
            return 'Needs confirmation';
        }
        if ($match === null || $match === '' || $match === 'pending') {
            return 'Not Checked';
        }
        // Complete OCR row with Firm + CA + City — verified extraction (Accept still adds to Master).
        if ($type === 'NO_EXACT_MATCH' || $match === 'needs_review' || $match === 'verified' || $match === 'pending' || $match === '') {
            $firm = trim((string) ($this->firm_name ?? ''));
            $city = trim((string) ($this->city ?? ''));
            $ca = trim((string) ($source['parsed']['ca_name'] ?? ($source['ca_name'] ?? '')));
            if ($firm !== '' && $city !== '' && $ca !== '') {
                return 'Exact verified';
            }
        }

        return 'No Exact Match';
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function userFacingMessage(array $source): ?string
    {
        $status = $this->userFacingStatus($source);
        if ($status === 'Verified') {
            return null;
        }
        $errors = $this->blockingErrors($source);
        if ($errors !== []) {
            return $errors[0];
        }
        if ($status === 'Conflict') {
            return 'Multiple Master records match Firm Name, CA Name, and City.';
        }
        if ($status === 'Needs Review') {
            $reason = (string) ($this->match_reason ?? '');
            if ($reason === 'city_not_in_master') {
                return 'City is not in the Master city list yet.';
            }
            if (in_array($reason, ['no_exact_firm_ca_city', 'NO_EXACT_MATCH'], true) || str_contains($reason, 'no_exact')) {
                return 'OCR fields look complete. No Master record yet — click Accept to add it.';
            }
            if ($reason === 'MISSING_FIRM_CA_OR_CITY' || $reason === 'missing_firm_ca_or_city') {
                return 'Firm Name, CA Name, and City are required for matching.';
            }

            return null;
        }

        return null;
    }

    private function canActOnReview(string $status): bool
    {
        $review = (string) ($this->review_status ?? '');
        if ($review === 'approved' && $this->crm_ca_id) {
            return false;
        }
        if ($review === 'rejected') {
            return false;
        }

        return in_array($status, ['Verified', 'Needs Review', 'Conflict', 'Invalid'], true);
    }

    /**
     * Legacy multi-field payload (OCR_WORKFLOW_MODE=full).
     *
     * @return array<string, mixed>
     */
    private function legacyPayload(Request $request): array
    {
        $source = is_array($this->source_data) ? $this->source_data : [];
        $raw = is_array($source['raw'] ?? null) ? $source['raw'] : [];
        $normalized = is_array($source['normalized'] ?? null) ? $source['normalized'] : [];
        $fieldMeta = is_array($this->field_meta) ? $this->field_meta : [];

        return [
            'id' => $this->id,
            'sequence_no' => $this->sequence_no,
            'firm_name' => $this->firm_name,
            'raw_firm_name' => $this->raw_firm_name ?: ($raw['firm_name'] ?? $this->firm_name),
            'normalized_firm_name' => $this->normalized_firm_name ?: ($normalized['firm_name'] ?? null),
            'firm_type' => $this->firm_type,
            'frn' => $this->frn,
            'gst_no' => $this->gst_no,
            'pan_no' => $this->pan_no,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'pincode' => $this->pincode,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'review_status' => $this->review_status,
            'crm_ca_id' => $this->crm_ca_id,
            'ca_id' => $this->crm_ca_id,
            'matched_ca_id' => $this->matched_ca_id,
            'match_status' => $this->match_status,
            'match_confidence' => $this->match_confidence,
            'match_reason' => $this->match_reason,
            'ca_name' => $raw['ca_name'] ?? ($source['ca_name'] ?? null),
            'ca_role' => $source['ca_role'] ?? null,
            'validation' => is_array($source['validation'] ?? null) ? $source['validation'] : null,
            'validation_errors' => is_array($this->validation_errors) ? $this->validation_errors : [],
            'raw_values' => $raw,
            'field_meta' => $fieldMeta,
            'members' => $this->when(
                $this->relationLoaded('members'),
                fn () => $this->members
                    ->map(fn ($member) => (new OcrParsedMemberResource($member))->resolve())
                    ->values()
                    ->all(),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<string>
     */
    private function scopedCollisionCodes(array $source): array
    {
        $codes = is_array($source['validation']['collision_codes'] ?? null)
            ? $source['validation']['collision_codes']
            : (is_array($this->validation_errors) ? $this->validation_errors : []);
        $blocking = array_flip(config('ocr_workflow.blocking_codes', []));
        $ignored = array_flip(config('ocr_workflow.ignored_decision_codes', []));
        $out = [];
        foreach ($codes as $code) {
            $code = (string) $code;
            if (isset($ignored[$code])) {
                continue;
            }
            if (isset($blocking[$code]) || str_starts_with($code, 'MISSING_')) {
                $out[] = $code;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<string>
     */
    private function blockingErrors(array $source): array
    {
        $validation = is_array($source['validation'] ?? null) ? $source['validation'] : [];
        $errors = is_array($validation['errors'] ?? null) ? $validation['errors'] : [];
        $codes = $this->scopedCollisionCodes($source);
        $ignored = config('ocr_workflow.ignored_decision_codes', []);
        $human = [];
        foreach (array_merge($errors, $codes) as $error) {
            $error = (string) $error;
            $skip = false;
            foreach ($ignored as $code) {
                if (str_contains(mb_strtoupper($error), (string) $code)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip || preg_match('/^[A-Z][A-Z0-9_]+$/', $error)) {
                continue;
            }
            $label = $this->humanizeBlockingError($error);
            if ($label !== null) {
                $human[$label] = $label;
            }
        }
        // If only machine codes remain (e.g. MISSING_CA_NAME), surface one human message.
        if ($human === [] && $codes !== []) {
            foreach ($codes as $code) {
                $label = match ($code) {
                    'MISSING_CA_NAME' => 'CA Name is required.',
                    'MISSING_FIRM_NAME' => 'Firm Name is required.',
                    'MISSING_CITY', 'MISSING_REQUIRED_FIELD' => 'City is required.',
                    default => null,
                };
                if ($label !== null) {
                    $human[$label] = $label;
                    break;
                }
            }
        }

        return array_values($human);
    }

    private function humanizeBlockingError(string $error): ?string
    {
        $lower = mb_strtolower($error);
        if (str_contains($lower, 'ca_name') || str_contains($lower, 'ca name')) {
            return 'CA Name is required.';
        }
        if (str_contains($lower, 'firm_name') || str_contains($lower, 'firm name')) {
            return 'Firm Name is required.';
        }
        if (str_contains($lower, 'city') && (str_contains($lower, 'required') || str_contains($lower, 'missing'))) {
            return 'City is required.';
        }
        if (str_contains($lower, 'address') && str_contains($lower, 'ca')) {
            return 'CA Name appears to contain address text.';
        }
        if (str_contains($lower, 'row merge') || str_contains($lower, 'row_merge')) {
            return null;
        }
        if (str_contains($lower, 'row split') || str_contains($lower, 'row_split')) {
            return null;
        }
        if (str_contains($lower, 'boundary is uncertain') || str_contains($lower, 'boundary_uncertain')) {
            return null;
        }

        return $error !== '' ? $error : null;
    }
}
