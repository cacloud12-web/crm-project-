<?php

namespace App\Services\Ocr;

/**
 * Source-versus-output verification gate.
 *
 * A row is verified for auto-apply only when field validation, collision
 * detection, confidence, and structure checks all pass. Otherwise it must
 * go to manual review — never silently into Master Data.
 *
 * In firm_ca_city mode, ONLY firm_name / ca_name / city affect the decision.
 */
class OcrSourceVerificationService
{
    public function __construct(
        private readonly OcrFieldValidationService $fieldValidator,
        private readonly OcrFieldCollisionService $collisionDetector,
    ) {}

    /**
     * @param  array<string, mixed>  $firm
     * @return array{
     *     ok: bool,
     *     verified: bool,
     *     auto_apply_ok: bool,
     *     errors: list<string>,
     *     warnings: list<string>,
     *     collision_codes: list<string>,
     *     collision_messages: list<string>,
     *     fields: array<string, mixed>,
     *     overall_confidence: float|null,
     *     require_verification: bool
     * }
     */
    public function verify(array $firm): array
    {
        if (config('ocr_workflow.mode', 'firm_ca_city') === 'firm_ca_city') {
            return $this->verifyThreeField($firm);
        }

        $validation = $this->fieldValidator->validateFirm($firm);
        $collision = $this->collisionDetector->detect($firm);

        $errors = $validation['errors'];
        $warnings = $validation['warnings'];
        $codes = $collision['codes'];
        $messages = $collision['messages'];

        $rejectCollision = (bool) config('ocr_safety.reject_on_field_collision', true);
        $rejectAmbiguity = (bool) config('ocr_safety.reject_on_row_ambiguity', true);
        $minConf = (float) config('ocr_safety.min_required_field_confidence', 0.99);
        $requireVerification = (bool) config('ocr_safety.require_verification', true);
        $allowAutoCreate = (bool) config('ocr_safety.auto_create', false);
        $allowAutoUpdate = (bool) config('ocr_safety.auto_update', false);

        $ambiguityCodes = [
            'ROW_MERGE_SUSPECTED', 'ROW_SPLIT_SUSPECTED', 'AMBIGUOUS_TABLE_STRUCTURE',
            'SOURCE_COLUMN_MISMATCH', 'CROSS_COLUMN_CONTAMINATION', 'AMBIGUOUS_LAYOUT',
            'AMBIGUOUS_RECORD_BOUNDARY', 'ORPHAN_TOKEN', 'NUMERIC_FIELD_AMBIGUOUS',
        ];
        $hasAmbiguity = count(array_intersect($codes, $ambiguityCodes)) > 0;
        $hasCollision = $codes !== [];

        if ($rejectCollision && $hasCollision) {
            foreach ($messages as $message) {
                $errors[] = $message;
            }
        } elseif ($hasCollision) {
            foreach ($messages as $message) {
                $warnings[] = $message;
            }
        }

        if ($rejectAmbiguity && $hasAmbiguity) {
            $errors[] = 'Row/table structure is ambiguous — cannot prove source boundaries.';
        }

        $required = config('ocr_safety.required_fields_for_auto', ['firm_name']);
        $fields = $validation['fields'];
        $autoOnlyLowConf = (bool) config('ocr_safety.low_confidence_blocks_auto_only', true);
        $structuralConf = isset($firm['structural_confidence']) ? (float) $firm['structural_confidence'] : null;
        $minStructural = (float) config('ocr_safety.min_structural_confidence', 0.80);
        $structuralOk = $structuralConf === null || $structuralConf >= $minStructural;

        foreach ($required as $field) {
            $entry = is_array($fields[$field] ?? null) ? $fields[$field] : [];
            $conf = $entry['confidence'] ?? null;
            $valid = $entry['valid'] ?? null;
            if ($valid === false) {
                continue;
            }
            if ($valid === true && $conf !== null && (float) $conf < $minConf && ! ($autoOnlyLowConf && $structuralOk)) {
                $codes[] = 'LOW_FIELD_CONFIDENCE';
                $errors[] = 'Needs review: '.str_replace('_', '-', (string) $field).' confidence is '.round((float) $conf * 100).'%.';
            }
            if ($field === 'firm_name' && ($valid === false || $valid === null)) {
                $codes[] = 'MISSING_REQUIRED_FIELD';
            }
        }

        $formatOk = $validation['ok'] && (! $rejectCollision || ! $hasCollision) && (! $rejectAmbiguity || ! $hasAmbiguity);
        $overall = $validation['overall_confidence'];
        $parserConf = isset($firm['parser_confidence']) ? (float) $firm['parser_confidence'] : $overall;
        $minParser = (float) config('ocr_safety.min_parser_confidence', 0.70);
        $confidenceOk = ($overall === null || (float) $overall >= $minParser)
            && ($structuralConf === null || $structuralConf >= $minStructural);

        $sourceOk = $this->sourceMatchesParsed($firm);
        if (! $sourceOk) {
            $errors[] = 'Parsed value differs from preserved raw OCR value — silent correction blocked.';
            $formatOk = false;
        }

        $verified = $formatOk && $confidenceOk && $sourceOk && $errors === [];
        $autoApplyOk = ! $requireVerification && $verified && ($allowAutoCreate || $allowAutoUpdate);

        return [
            'ok' => $formatOk,
            'verified' => $verified,
            'auto_apply_ok' => $autoApplyOk,
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'collision_codes' => array_values(array_unique($codes)),
            'collision_messages' => $messages,
            'fields' => $fields,
            'overall_confidence' => $overall,
            'require_verification' => $requireVerification,
        ];
    }

    /**
     * Firm+CA+City only — ignored FRN/address/PIN/unknown tokens never block.
     *
     * @param  array<string, mixed>  $firm
     * @return array{
     *     ok: bool,
     *     verified: bool,
     *     auto_apply_ok: bool,
     *     errors: list<string>,
     *     warnings: list<string>,
     *     collision_codes: list<string>,
     *     collision_messages: list<string>,
     *     fields: array<string, mixed>,
     *     overall_confidence: float|null,
     *     require_verification: bool
     * }
     */
    private function verifyThreeField(array $firm): array
    {
        $validation = $this->fieldValidator->validateFirm($firm);
        $collision = $this->collisionDetector->detect($firm);

        $blocking = array_flip(config('ocr_workflow.blocking_codes', []));
        $ignored = array_flip(config('ocr_workflow.ignored_decision_codes', []));

        $codes = [];
        $messages = [];
        foreach ($collision['codes'] as $i => $code) {
            if (isset($ignored[$code])) {
                continue;
            }
            if (! isset($blocking[$code]) && ! str_starts_with((string) $code, 'MISSING_')) {
                continue;
            }
            $codes[] = $code;
            if (isset($collision['messages'][$i])) {
                $messages[] = $collision['messages'][$i];
            }
        }
        // Re-attach messages by code when index mapping is imperfect.
        if ($messages === [] && $codes !== []) {
            foreach ($collision['messages'] as $message) {
                $messages[] = $message;
            }
        }

        $errors = [];
        $warnings = [];
        foreach ($validation['errors'] as $error) {
            // Validation errors are already scoped to firm/ca/city in firm_ca_city mode.
            $errors[] = $error;
        }
        foreach ($validation['warnings'] as $warning) {
            $warnings[] = $warning;
        }
        foreach ($messages as $message) {
            $errors[] = $message;
        }

        $fields = $validation['fields'];
        $thresholds = [
            'firm_name' => (float) config('ocr_workflow.min_firm_name_confidence', config('ocr_workflow.min_field_confidence', 0.55)),
            'ca_name' => (float) config('ocr_workflow.min_ca_name_confidence', config('ocr_workflow.min_field_confidence', 0.55)),
            'city' => (float) config('ocr_workflow.min_city_confidence', config('ocr_workflow.min_field_confidence', 0.55)),
        ];

        foreach (['firm_name', 'ca_name', 'city'] as $field) {
            $entry = is_array($fields[$field] ?? null) ? $fields[$field] : [];
            $conf = $entry['confidence'] ?? null;
            $valid = $entry['valid'] ?? null;
            if ($valid !== true || $conf === null) {
                continue;
            }
            if ((float) $conf < $thresholds[$field]) {
                $codes[] = 'LOW_FIELD_CONFIDENCE';
                $errors[] = 'Needs review: '.str_replace('_', '-', $field).' confidence is '.round((float) $conf * 100).'%.';
            }
        }

        $sourceOk = $this->sourceMatchesParsed($firm);
        if (! $sourceOk) {
            $errors[] = 'Parsed value differs from preserved raw OCR value — silent correction blocked.';
        }

        $codes = array_values(array_unique($codes));
        $errors = array_values(array_unique($errors));
        $warnings = array_values(array_unique($warnings));

        $formatOk = $validation['ok'] && $codes === [] && $sourceOk;
        // Structurally verified for matching — auto-apply still gated by require_verification.
        $verified = $formatOk;
        $requireVerification = (bool) config('ocr_safety.require_verification', true);
        $autoApplyOk = ! $requireVerification
            && $verified
            && ((bool) config('ocr_safety.auto_create', false) || (bool) config('ocr_safety.auto_update', false));

        return [
            'ok' => $formatOk,
            'verified' => $verified,
            'auto_apply_ok' => $autoApplyOk,
            'errors' => $errors,
            'warnings' => $warnings,
            'collision_codes' => $codes,
            'collision_messages' => array_values(array_unique($messages)),
            'fields' => $fields,
            'overall_confidence' => $validation['overall_confidence'],
            'require_verification' => $requireVerification,
        ];
    }

    /**
     * @param  array<string, mixed>  $firm
     */
    private function sourceMatchesParsed(array $firm): bool
    {
        $raw = is_array($firm['raw'] ?? null) ? $firm['raw'] : [];
        $parsed = is_array($firm['parsed'] ?? null) ? $firm['parsed'] : [];
        if ($raw === [] && $parsed === []) {
            return true;
        }

        $threeField = config('ocr_workflow.mode', 'firm_ca_city') === 'firm_ca_city';
        $unicode = $threeField ? new OcrUnicodeNormalizationService : null;

        foreach (['firm_name', 'ca_name', 'city'] as $field) {
            $r = isset($raw[$field]) ? trim((string) $raw[$field]) : null;
            $p = isset($parsed[$field]) ? trim((string) $parsed[$field]) : null;
            if ($r === null || $r === '' || $p === null || $p === '') {
                continue;
            }
            if ($r === $p) {
                continue;
            }
            // Safe Unicode confusable normalization (Greek/Cyrillic look-alikes) is allowed.
            if ($unicode !== null) {
                $rNorm = $unicode->classificationValue($r);
                $pNorm = $unicode->classificationValue($p);
                if ($rNorm === $pNorm || $rNorm === $p || mb_strtoupper($rNorm) === mb_strtoupper($pNorm)) {
                    continue;
                }
            }
            // Leading partner markers (* / CA / PROP) are decorations, not spelling changes.
            $rDecor = preg_replace('/^[\*\•\·\-\–\—]+\s*/u', '', $r) ?? $r;
            $rDecor = preg_replace('/^(?:ca\.?|prop(?:rietor)?\.?|shri|smt|mr\.?|mrs\.?|ms\.?)\s+/iu', '', trim($rDecor)) ?? $rDecor;
            if (mb_strtoupper(trim($rDecor)) === mb_strtoupper($p)) {
                continue;
            }

            return false;
        }

        if ($threeField) {
            return true;
        }

        foreach (['frn', 'membership_no', 'pincode', 'phone', 'gst_no', 'pan_no'] as $field) {
            $r = isset($raw[$field]) ? trim((string) $raw[$field]) : null;
            $p = isset($parsed[$field]) ? trim((string) $parsed[$field]) : null;
            if ($r !== null && $r !== '' && $p !== null && $p !== '' && $r !== $p) {
                return false;
            }
        }

        return true;
    }
}
