<?php

namespace App\Services\Ocr;

use App\Models\State;
use App\Services\Mapping\DataNormalizationService;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise field validation for OCR staging.
 *
 * Never invents or auto-corrects values — invalid / uncertain fields are flagged
 * so the row goes to Needs Review instead of silently writing Master Data.
 */
class OcrFieldValidationService
{
    public const AUTO_APPLY_MIN = 0.99;

    public function __construct(
        private readonly DataNormalizationService $normalizer,
    ) {}

    /**
     * Validate a parsed firm row field-by-field.
     *
     * @param  array<string, mixed>  $firm
     * @return array{
     *     ok: bool,
     *     auto_apply_ok: bool,
     *     errors: list<string>,
     *     warnings: list<string>,
     *     fields: array<string, array{valid: bool|null, error: ?string, normalized: mixed, confidence: ?float}>,
     *     overall_confidence: float|null
     * }
     */
    public function validateFirm(array $firm): array
    {
        $meta = is_array($firm['field_meta'] ?? null) ? $firm['field_meta'] : [];
        $members = is_array($firm['members'] ?? null) ? $firm['members'] : [];
        $primary = $members[0] ?? [];
        $caName = $firm['ca_name'] ?? ($primary['ca_name'] ?? ($primary['raw_ca_name'] ?? null));

        $fields = [];
        $errors = [];
        $warnings = [];

        if (config('ocr_workflow.mode', 'firm_ca_city') === 'firm_ca_city') {
            $fields['firm_name'] = $this->checkFirmName($firm['firm_name'] ?? ($firm['raw_firm_name'] ?? null), $meta);
            $fields['ca_name'] = $this->checkCaName($caName, $meta);
            $fields['city'] = $this->checkCityRequired($firm['city'] ?? ($firm['raw_city'] ?? null), $meta);
            foreach (['firm_name', 'ca_name', 'city'] as $required) {
                $result = $fields[$required];
                if ($result['valid'] !== true) {
                    $errors[] = $required.': '.($result['error'] ?? 'required for OCR match');
                }
            }
            foreach ($fields as $name => $result) {
                $conf = $result['confidence'];
                $minConf = match ($name) {
                    'firm_name' => (float) config('ocr_workflow.min_firm_name_confidence', config('ocr_workflow.min_field_confidence', 0.55)),
                    'ca_name' => (float) config('ocr_workflow.min_ca_name_confidence', config('ocr_workflow.min_field_confidence', 0.55)),
                    'city' => (float) config('ocr_workflow.min_city_confidence', config('ocr_workflow.min_field_confidence', 0.55)),
                    default => (float) config('ocr_workflow.min_field_confidence', 0.55),
                };
                if ($conf !== null && (float) $conf < $minConf) {
                    $warnings[] = $name.': low confidence ('.round((float) $conf * 100).'%)';
                }
            }
            $overall = $firm['overall_confidence'] ?? null;
            $ok = $errors === [];

            return [
                'ok' => $ok,
                'auto_apply_ok' => false,
                'errors' => array_values(array_unique($errors)),
                'warnings' => array_values(array_unique($warnings)),
                'fields' => $fields,
                'overall_confidence' => $overall,
            ];
        }

        $fields['firm_name'] = $this->checkFirmName($firm['firm_name'] ?? ($firm['raw_firm_name'] ?? null), $meta);
        $fields['ca_name'] = $this->checkCaName($caName, $meta);
        $fields['firm_type'] = $this->passthrough($firm['firm_type'] ?? null, $meta['firm_type'] ?? null);
        $fields['address'] = $this->checkAddress($firm['address'] ?? null, $caName, $meta);
        $fields['city'] = $this->passthrough($firm['city'] ?? null, $meta['city'] ?? null);
        $fields['state'] = $this->checkState($firm['state'] ?? null, $meta);
        $fields['pincode'] = $this->checkPincode($firm['pincode'] ?? null, $meta);
        $fields['phone'] = $this->checkPhone($firm['phone'] ?? null, $meta);
        $fields['email'] = $this->checkEmail($firm['email'] ?? null, $meta);
        $fields['frn'] = $this->checkFrn($firm['frn'] ?? null, $meta);
        $fields['membership_no'] = $this->checkMembership(
            $firm['membership_no'] ?? ($primary['membership_no'] ?? null),
            $meta,
        );
        $fields['gst_no'] = $this->checkGst($firm['gst_no'] ?? null, $meta);
        $fields['pan_no'] = $this->checkPan($firm['pan_no'] ?? null, $meta);

        foreach ($fields as $name => $result) {
            if ($result['valid'] === false && filled($result['error'])) {
                $errors[] = $name.': '.$result['error'];
            }
            $conf = $result['confidence'];
            if ($conf !== null && (float) $conf < (float) config('crm_mapping.field_confidence_review_min', 0.55)) {
                $warnings[] = $name.': low confidence ('.round((float) $conf * 100).'%)';
            }
        }

        $overall = $firm['overall_confidence'] ?? null;
        if ($overall === null && $meta !== []) {
            $scores = [];
            foreach ($meta as $entry) {
                if (is_array($entry) && isset($entry['confidence'])) {
                    $scores[] = (float) $entry['confidence'];
                }
            }
            $overall = $scores !== [] ? round(array_sum($scores) / count($scores), 4) : null;
        }

        $criticalLow = $this->hasCriticalLowConfidence($fields, $overall);
        $formatOk = $errors === [];
        $minApply = (float) config(
            'ocr_safety.min_required_field_confidence',
            config('crm_mapping.auto_apply_field_confidence_min', self::AUTO_APPLY_MIN),
        );
        $autoApplyOk = $formatOk && ! $criticalLow
            && ($overall === null || (float) $overall >= $minApply);

        // Fail-closed: when verification is required, field validation alone never authorizes Master writes.
        if ((bool) config('ocr_safety.require_verification', true)) {
            $autoApplyOk = false;
        }

        return [
            'ok' => $formatOk && ! $criticalLow,
            'auto_apply_ok' => $autoApplyOk,
            'errors' => $errors,
            'warnings' => $warnings,
            'fields' => $fields,
            'overall_confidence' => $overall !== null ? (float) $overall : null,
        ];
    }

    /**
     * @param  array<string, array{valid: bool|null, error: ?string, normalized: mixed, confidence: ?float}>  $fields
     */
    private function hasCriticalLowConfidence(array $fields, mixed $overall): bool
    {
        $threshold = (float) config('crm_mapping.field_confidence_review_min', 0.55);
        if ($overall !== null && (float) $overall < $threshold) {
            return true;
        }

        foreach (['firm_name', 'ca_name', 'frn', 'membership_no', 'phone', 'gst_no', 'pan_no'] as $critical) {
            $conf = $fields[$critical]['confidence'] ?? null;
            if ($conf !== null && (float) $conf < $threshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{valid: bool|null, error: ?string, normalized: mixed, confidence: ?float}
     */
    private function checkFirmName(mixed $raw, array $meta): array
    {
        $value = $this->rawString($raw);
        $confidence = $this->metaConfidence($meta, 'firm_name');
        if ($value === null) {
            return $this->result(false, 'Firm name is required.', null, $confidence);
        }
        if (preg_match('/\b\d{10}\b/', $value)) {
            return $this->result(false, 'Firm name must not contain a phone number.', null, $confidence);
        }
        if (preg_match('/\b\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}\b/', $value)) {
            return $this->result(false, 'Firm name must not contain a date.', null, $confidence);
        }
        if (mb_strlen($value) < 3) {
            return $this->result(false, 'Firm name is too short.', null, $confidence);
        }

        return $this->result(true, null, $this->normalizer->firmName($value), $confidence);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{valid: bool|null, error: ?string, normalized: mixed, confidence: ?float}
     */
    private function checkCaName(mixed $raw, array $meta): array
    {
        $value = $this->rawString($raw);
        $confidence = $this->metaConfidence($meta, 'ca_name');
        if ($value === null) {
            if (config('ocr_workflow.mode', 'firm_ca_city') === 'firm_ca_city') {
                return $this->result(false, 'CA name is required.', null, $confidence);
            }

            return $this->result(null, null, null, $confidence);
        }
        if (preg_match('/\b\d{10}\b/', $value) || preg_match('/\d{6,}/', $value) || preg_match('/\d/', $value)) {
            return $this->result(false, 'CA name must not contain phone/membership digits.', null, $confidence);
        }
        if (preg_match('/\b(road|street|nagar|colony|sector|floor|plot|lane|pin|pincode|mandi|estate|huda|sadak|hospital|market|chowk|mohalla|cantt|urban|anaj)\b/i', $value)
            || preg_match('/\b[1-9]\d{5}\b/', $value)) {
            return $this->result(false, 'CA name must not contain address or PIN text.', null, $confidence);
        }
        if (preg_match('/\b(?:associates|llp|chartered\s+accountants|&\s*co\.?|company)\b/i', $value)) {
            return $this->result(false, 'CA name must not contain firm suffixes.', null, $confidence);
        }

        return $this->result(true, null, $this->normalizer->caName($value), $confidence);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{valid: bool|null, error: ?string, normalized: mixed, confidence: ?float}
     */
    private function checkAddress(mixed $raw, mixed $caName, array $meta): array
    {
        $value = $this->rawString($raw);
        $confidence = $this->metaConfidence($meta, 'address');
        if ($value === null) {
            return $this->result(null, null, null, $confidence);
        }
        $ca = $this->rawString($caName);
        if ($ca !== null && mb_strlen($ca) >= 5) {
            $caNorm = mb_strtolower(preg_replace('/\s+/', ' ', $ca) ?? $ca);
            $addrNorm = mb_strtolower(preg_replace('/\s+/', ' ', $value) ?? $value);
            // Never invent — only flag when CA name appears as a standalone blob unlikely in real addresses.
            if (str_contains($addrNorm, $caNorm) && ! preg_match('/\b(road|street|nagar|colony|sector|floor|plot)\b/i', $value)) {
                return $this->result(false, 'Address appears to contain CA name (possible field mix).', null, $confidence);
            }
        }

        return $this->result(true, null, $value, $confidence);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{valid: bool|null, error: ?string, normalized: mixed, confidence: ?float}
     */
    private function checkState(mixed $raw, array $meta): array
    {
        $value = $this->rawString($raw);
        $confidence = $this->metaConfidence($meta, 'state');
        if ($value === null) {
            return $this->result(null, null, null, $confidence);
        }

        $normalized = $this->normalizer->state($value);
        if (! Schema::hasTable('states')) {
            return $this->result(true, null, $normalized, $confidence);
        }

        $exists = State::query()
            ->whereRaw('UPPER(TRIM(state_name)) = ?', [mb_strtoupper(trim($value))])
            ->exists();
        if (! $exists && $normalized) {
            $exists = State::query()
                ->whereRaw('UPPER(TRIM(state_name)) = ?', [mb_strtoupper(trim((string) $normalized))])
                ->exists();
        }

        if (! $exists) {
            return $this->result(false, 'State is not in the master state list.', null, $confidence);
        }

        return $this->result(true, null, $normalized, $confidence);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{valid: bool|null, error: ?string, normalized: mixed, confidence: ?float}
     */
    private function checkPincode(mixed $raw, array $meta): array
    {
        $value = $this->rawString($raw);
        $confidence = $this->metaConfidence($meta, 'pincode');
        if ($value === null) {
            return $this->result(null, null, null, $confidence);
        }
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (! preg_match('/^[1-9][0-9]{5}$/', $digits)) {
            return $this->result(false, 'PIN must be a valid 6-digit Indian postal code.', null, $confidence);
        }

        return $this->result(true, null, $digits, $confidence);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{valid: bool|null, error: ?string, normalized: mixed, confidence: ?float}
     */
    private function checkPhone(mixed $raw, array $meta): array
    {
        $value = $this->rawString($raw);
        $confidence = $this->metaConfidence($meta, 'phone');
        if ($value === null) {
            return $this->result(null, null, null, $confidence);
        }
        $normalized = $this->normalizer->phone($value);
        if ($normalized === null || ! preg_match('/^[6-9]\d{9}$/', $normalized)) {
            return $this->result(false, 'Mobile must be a valid 10-digit Indian mobile number.', null, $confidence);
        }

        return $this->result(true, null, $normalized, $confidence);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{valid: bool|null, error: ?string, normalized: mixed, confidence: ?float}
     */
    private function checkEmail(mixed $raw, array $meta): array
    {
        $value = $this->rawString($raw);
        $confidence = $this->metaConfidence($meta, 'email');
        if ($value === null) {
            return $this->result(null, null, null, $confidence);
        }
        $normalized = $this->normalizer->email($value);
        if ($normalized === null) {
            return $this->result(false, 'Email format is invalid.', null, $confidence);
        }

        return $this->result(true, null, $normalized, $confidence);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{valid: bool|null, error: ?string, normalized: mixed, confidence: ?float}
     */
    private function checkFrn(mixed $raw, array $meta): array
    {
        $value = $this->rawString($raw);
        $confidence = $this->metaConfidence($meta, 'frn');
        if ($value === null) {
            return $this->result(null, null, null, $confidence);
        }
        $normalized = $this->normalizer->frn($value);
        if ($normalized === null || ! preg_match('/^[A-Z0-9]{4,20}$/', $normalized)) {
            return $this->result(false, 'FRN format is invalid.', null, $confidence);
        }

        return $this->result(true, null, $normalized, $confidence);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{valid: bool|null, error: ?string, normalized: mixed, confidence: ?float}
     */
    private function checkMembership(mixed $raw, array $meta): array
    {
        $value = $this->rawString($raw);
        $confidence = $this->metaConfidence($meta, 'membership_no')
            ?? $this->metaConfidence($meta, 'membership_number');
        if ($value === null) {
            return $this->result(null, null, null, $confidence);
        }
        $normalized = $this->normalizer->membershipNumber($value);
        if ($normalized === null || ! preg_match('/^[A-Z0-9]{4,20}$/', $normalized)) {
            return $this->result(false, 'Membership number format is invalid.', null, $confidence);
        }
        $evidence = is_array($meta) ? ($meta['evidence'] ?? null) : null;
        if (is_array($meta['membership_no'] ?? null)) {
            $evidence = $meta['membership_no']['evidence'] ?? $evidence;
        }
        $directoryEvidence = in_array($evidence, [
            'right_column_membership', 'city_dash_membership', 'firm_line_identifier',
            'icai_membership_suffix', 'labeled_membership', 'membership_with_confirmed_pin',
            'membership_record_context',
        ], true);
        if (preg_match('/^[1-9]\d{5}$/', $normalized) && ! $directoryEvidence && preg_match('/[A-Z]/', $value) === 0) {
            return $this->result(false, 'Membership number must not be a PIN code.', null, $confidence);
        }
        if (preg_match('/^[6-9]\d{9}$/', $normalized)) {
            return $this->result(false, 'Membership number must not be a mobile number.', null, $confidence);
        }

        return $this->result(true, null, $normalized, $confidence);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{valid: bool|null, error: ?string, normalized: mixed, confidence: ?float}
     */
    private function checkGst(mixed $raw, array $meta): array
    {
        $value = $this->rawString($raw);
        $confidence = $this->metaConfidence($meta, 'gst_no');
        if ($value === null) {
            return $this->result(null, null, null, $confidence);
        }
        $normalized = $this->normalizer->gst($value);
        if ($normalized === null || strlen($normalized) !== 15 || ! preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/', $normalized)) {
            return $this->result(false, 'GST format is invalid.', null, $confidence);
        }

        return $this->result(true, null, $normalized, $confidence);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{valid: bool|null, error: ?string, normalized: mixed, confidence: ?float}
     */
    private function checkPan(mixed $raw, array $meta): array
    {
        $value = $this->rawString($raw);
        $confidence = $this->metaConfidence($meta, 'pan_no');
        if ($value === null) {
            return $this->result(null, null, null, $confidence);
        }
        $normalized = $this->normalizer->pan($value);
        if ($normalized === null || ! preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $normalized)) {
            return $this->result(false, 'PAN format is invalid.', null, $confidence);
        }

        return $this->result(true, null, $normalized, $confidence);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{valid: bool|null, error: ?string, normalized: mixed, confidence: ?float}
     */
    private function checkCityRequired(mixed $raw, array $meta): array
    {
        $value = $this->rawString($raw);
        $confidence = $this->metaConfidence($meta, 'city');
        if ($value === null) {
            return $this->result(false, 'City is required.', null, $confidence);
        }
        if (preg_match('/\b(?:street|sadak|hospital|floor|shop|ward|sector\s*\d|backside)\b/iu', $value)) {
            return $this->result(false, 'City must not be a full address line.', null, $confidence);
        }

        return $this->result(true, null, $this->normalizer->city($value), $confidence);
    }

    /**
     * @return array{valid: bool|null, error: ?string, normalized: mixed, confidence: ?float}
     */
    private function passthrough(mixed $raw, mixed $metaEntry): array
    {
        $value = $this->rawString($raw);
        $confidence = is_array($metaEntry) && isset($metaEntry['confidence'])
            ? (float) $metaEntry['confidence']
            : null;

        return $this->result($value !== null ? true : null, null, $value, $confidence);
    }

    /**
     * @return array{valid: bool|null, error: ?string, normalized: mixed, confidence: ?float}
     */
    private function result(?bool $valid, ?string $error, mixed $normalized, ?float $confidence): array
    {
        return [
            'valid' => $valid,
            'error' => $error,
            'normalized' => $normalized,
            'confidence' => $confidence,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function metaConfidence(array $meta, string $field): ?float
    {
        $entry = $meta[$field] ?? null;

        return is_array($entry) && isset($entry['confidence']) ? (float) $entry['confidence'] : null;
    }

    private function rawString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
