<?php

namespace App\Services\Ocr;

use App\Models\OcrParsedFirm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Read-only audit of unlinked OCR staging rows (crm_ca_id IS NULL).
 * Never updates database rows or CA masters.
 */
class OcrUnlinkedCaNameAuditService
{
    public const CATEGORIES = [
        'missing_ca_name',
        'missing_city',
        'missing_firm_name',
        'numeric_prefix_address',
        'building_name_detected_as_ca_name',
        'address_detected_as_ca_name',
        'firm_name_person_extraction_conflict',
        'initials_expansion',
        'raw_and_parsed_ca_name_minor_difference',
        'parser_changed_raw_value',
        'invalid_person_name',
        'multiple_possible_ca_names',
        'low_confidence',
        'duplicate_candidate',
        'other',
    ];

    /** @var list<string> */
    private const BUILDING_WORDS = [
        'SQUARE', 'CENTRE', 'CENTER', 'COMPLEX', 'PLAZA', 'TOWER', 'BUILDING',
        'APARTMENT', 'TENAMENT', 'TENEMENT', 'BUSINESS', 'CHAMBERS', 'MARKET',
        'FLOOR', 'BLOCK', 'OFFICE', 'SHOP', 'ROAD', 'STREET', 'NAGAR', 'COLONY',
        'SOCIETY', 'NEAR', 'OPP', 'BESIDE', 'ABOVE', 'BELOW', 'COMM', 'COMMERCIAL',
        'HOUSE', 'FLAT', 'PLOT', 'SECTOR', 'PHASE', 'CHOWK', 'MARG',
    ];

    /**
     * @param  array{
     *   document?: int|null,
     *   category?: string|null,
     *   limit?: int|null,
     *   export?: string|null,
     *   json?: bool,
     *   summary_only?: bool,
     *   sample_limit?: int
     * }  $options
     * @return array<string, mixed>
     */
    public function audit(array $options = []): array
    {
        $documentId = isset($options['document']) ? (int) $options['document'] : null;
        $categoryFilter = isset($options['category']) ? trim((string) $options['category']) : '';
        $limit = isset($options['limit']) ? max(0, (int) $options['limit']) : 0;
        $sampleLimit = isset($options['sample_limit']) ? max(1, (int) $options['sample_limit']) : 20;
        $exportPath = isset($options['export']) ? trim((string) $options['export']) : '';

        $counts = array_fill_keys(self::CATEGORIES, 0);
        $totals = [
            'total_unlinked' => 0,
            'categorized' => 0,
            'uncategorized' => 0,
            'invalid_json' => 0,
            'missing_source_data' => 0,
            'safe_repair_candidate' => 0,
            'manual_review_required' => 0,
            'skipped_by_category_filter' => 0,
            'emitted' => 0,
        ];
        $samples = [];
        $exportHandle = null;
        $resolvedExport = null;

        if ($exportPath !== '') {
            $resolvedExport = $this->resolveExportPath($exportPath);
            $dir = dirname($resolvedExport);
            if (! is_dir($dir)) {
                File::makeDirectory($dir, 0755, true);
            }
            $exportHandle = fopen($resolvedExport, 'w');
            if ($exportHandle === false) {
                throw new RuntimeException('Unable to open export path: '.$resolvedExport);
            }
            fputcsv($exportHandle, [
                'id',
                'ocr_document_id',
                'row_number',
                'firm_name',
                'raw_firm_name',
                'raw_ca_name',
                'parsed_ca_name',
                'normalized_ca_name',
                'city',
                'review_status',
                'match_status',
                'match_reason',
                'overall_confidence',
                'primary_category',
                'issue_codes',
                'safe_repair_candidate',
                'manual_review_required',
                'source_fingerprint',
                'business_fingerprint',
            ]);
        }

        $query = $this->baseQuery($documentId);

        $query->orderBy('id')->chunkById(500, function ($rows) use (
            &$counts,
            &$totals,
            &$samples,
            $categoryFilter,
            $limit,
            $sampleLimit,
            $exportHandle,
        ) {
            foreach ($rows as $firm) {
                /** @var OcrParsedFirm $firm */
                $totals['total_unlinked']++;
                $row = $this->classifyRow($firm);

                if (! empty($row['invalid_json'])) {
                    $totals['invalid_json']++;
                }
                if (! empty($row['missing_source_data'])) {
                    $totals['missing_source_data']++;
                }

                $primary = (string) ($row['primary_category'] ?? 'other');
                if (! array_key_exists($primary, $counts)) {
                    $primary = 'other';
                    $row['primary_category'] = 'other';
                }

                if ($categoryFilter !== '' && $primary !== $categoryFilter
                    && ! in_array($categoryFilter, $row['issue_codes'] ?? [], true)) {
                    $totals['skipped_by_category_filter']++;

                    continue;
                }

                $counts[$primary]++;
                $totals['categorized']++;
                if ($primary === 'other' && ($row['issue_codes'] ?? []) === ['other']) {
                    $totals['uncategorized']++;
                }
                if (! empty($row['safe_repair_candidate'])) {
                    $totals['safe_repair_candidate']++;
                }
                if (! empty($row['manual_review_required'])) {
                    $totals['manual_review_required']++;
                }

                $totals['emitted']++;
                if (count($samples) < $sampleLimit) {
                    $samples[] = $row;
                }

                if ($exportHandle !== null) {
                    fputcsv($exportHandle, [
                        $row['id'],
                        $row['ocr_document_id'],
                        $row['row_number'],
                        $row['firm_name'],
                        $row['raw_firm_name'],
                        $row['raw_ca_name'],
                        $row['parsed_ca_name'],
                        $row['normalized_ca_name'],
                        $row['city'],
                        $row['review_status'],
                        $row['match_status'],
                        $row['match_reason'],
                        $row['overall_confidence'],
                        $row['primary_category'],
                        implode('|', $row['issue_codes'] ?? []),
                        ! empty($row['safe_repair_candidate']) ? '1' : '0',
                        ! empty($row['manual_review_required']) ? '1' : '0',
                        $row['source_fingerprint'],
                        $row['business_fingerprint'],
                    ]);
                }

                if ($limit > 0 && $totals['emitted'] >= $limit) {
                    return false;
                }
            }

            return true;
        });

        if (is_resource($exportHandle)) {
            fclose($exportHandle);
        }

        $totalForShare = max(1, array_sum($counts));
        $summary = [];
        foreach (self::CATEGORIES as $cat) {
            $count = (int) $counts[$cat];
            $summary[] = [
                'category' => $cat,
                'count' => $count,
                'share_pct' => round(($count / $totalForShare) * 100, 2),
                'safe_repair_candidate' => $this->categorySafeRepairDefault($cat),
                'needs_manual_review' => $this->categoryManualReviewDefault($cat),
            ];
        }

        return [
            'totals' => $totals,
            'counts' => $counts,
            'summary' => $summary,
            'samples' => $samples,
            'export_path' => $resolvedExport,
            'filters' => [
                'document' => $documentId,
                'category' => $categoryFilter !== '' ? $categoryFilter : null,
                'limit' => $limit > 0 ? $limit : null,
            ],
        ];
    }

    /**
     * @return Builder<OcrParsedFirm>
     */
    private function baseQuery(?int $documentId): Builder
    {
        $query = OcrParsedFirm::query()
            ->whereNull('crm_ca_id')
            ->select([
                'id',
                'ocr_document_id',
                'row_number',
                'sequence_no',
                'firm_name',
                'raw_firm_name',
                'normalized_firm_name',
                'city',
                'review_status',
                'overall_confidence',
                'match_status',
                'match_reason',
                'source_data',
                'field_meta',
                'validation_errors',
                'matched_reference_firm_id',
                'matched_ca_id',
                'crm_ca_id',
                'is_noise',
                'source_fingerprint',
                'business_fingerprint',
            ]);

        if ($documentId !== null && $documentId > 0) {
            $query->where('ocr_document_id', $documentId);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function classifyRow(OcrParsedFirm $firm): array
    {
        $invalidJson = false;
        $missingSource = false;
        $source = $firm->source_data;
        $rawOriginal = $firm->getAttributes()['source_data'] ?? null;
        if (is_string($rawOriginal) && $rawOriginal !== '' && json_decode($rawOriginal, true) === null && json_last_error() !== JSON_ERROR_NONE) {
            $invalidJson = true;
        }
        if ($source === null) {
            $missingSource = ! $invalidJson;
            $source = [];
        } elseif (is_string($source)) {
            $decoded = json_decode($source, true);
            if (! is_array($decoded)) {
                $invalidJson = true;
                $source = [];
            } else {
                $source = $decoded;
            }
        } elseif (! is_array($source)) {
            $invalidJson = true;
            $source = [];
        }

        $raw = is_array($source['raw'] ?? null) ? $source['raw'] : [];
        $parsed = is_array($source['parsed'] ?? null) ? $source['parsed'] : [];
        $normalized = is_array($source['normalized'] ?? null) ? $source['normalized'] : [];
        $validation = is_array($source['validation'] ?? null) ? $source['validation'] : [];
        $fieldMeta = is_array($firm->field_meta) ? $firm->field_meta : [];
        if (is_string($firm->field_meta)) {
            $decodedMeta = json_decode($firm->field_meta, true);
            $fieldMeta = is_array($decodedMeta) ? $decodedMeta : [];
            if ($firm->field_meta !== '' && $decodedMeta === null) {
                $invalidJson = true;
            }
        }

        $firmName = trim((string) ($firm->firm_name ?: $firm->raw_firm_name ?: ($parsed['firm_name'] ?? '') ?: ($raw['firm_name'] ?? '')));
        $rawFirm = trim((string) ($firm->raw_firm_name ?: ($raw['firm_name'] ?? '') ?: $firmName));
        $city = trim((string) ($firm->city ?: ($parsed['city'] ?? '') ?: ($raw['city'] ?? '')));
        $rawCa = trim((string) ($raw['ca_name'] ?? ''));
        $parsedCa = trim((string) ($parsed['ca_name'] ?? ''));
        $normalizedCa = trim((string) ($normalized['ca_name'] ?? ''));
        if ($parsedCa === '' && $normalizedCa !== '') {
            $parsedCa = $normalizedCa;
        }

        $matchReason = trim((string) ($firm->match_reason ?? ''));
        $issues = [];
        $tokenReport = $this->tokenDiff($rawCa, $parsedCa);

        if ($firmName === '') {
            $issues[] = 'missing_firm_name';
        }
        if ($this->isBlankCa($rawCa, $parsedCa, $normalizedCa)) {
            $issues[] = 'missing_ca_name';
        }
        if ($city === '') {
            $issues[] = 'missing_city';
        }

        $caForAddress = $rawCa !== '' ? $rawCa : $parsedCa;
        if ($caForAddress !== '') {
            if ($this->hasLeadingNumericPrefix($caForAddress) && $this->looksLikeAddressOrBuilding($caForAddress)) {
                $issues[] = 'numeric_prefix_address';
            } elseif ($this->hasLeadingNumericPrefix($caForAddress)) {
                $issues[] = 'numeric_prefix_address';
            }
            if ($this->looksLikeBuildingName($caForAddress)) {
                $issues[] = 'building_name_detected_as_ca_name';
            }
            if ($this->looksLikeAddressOrBuilding($caForAddress)) {
                $issues[] = 'address_detected_as_ca_name';
            }
        }

        if ($rawCa !== '' && $parsedCa !== '' && $this->isFirmPersonExtractionConflict($firmName, $rawCa, $parsedCa, $tokenReport)) {
            $issues[] = 'firm_name_person_extraction_conflict';
            if ($this->isInitialsExpansion($tokenReport)) {
                $issues[] = 'initials_expansion';
            }
        } elseif ($rawCa !== '' && $parsedCa !== '' && mb_strtoupper($rawCa) !== mb_strtoupper($parsedCa)) {
            if ($this->isMinorDifference($rawCa, $parsedCa, $tokenReport)) {
                $issues[] = 'raw_and_parsed_ca_name_minor_difference';
            } else {
                $issues[] = 'parser_changed_raw_value';
            }
            if ($this->isInitialsExpansion($tokenReport)) {
                $issues[] = 'initials_expansion';
            }
        }

        if ($this->isInvalidPersonSignal($matchReason, $validation, $rawCa, $parsedCa)) {
            $issues[] = 'invalid_person_name';
        }
        if ($this->hasMultiplePossibleCaNames($source, $fieldMeta)) {
            $issues[] = 'multiple_possible_ca_names';
        }
        if ($this->isLowConfidence($firm->overall_confidence, $fieldMeta, $matchReason)) {
            $issues[] = 'low_confidence';
        }
        if ($this->isDuplicateCandidate($firm, $matchReason)) {
            $issues[] = 'duplicate_candidate';
        }

        if ($issues === []) {
            $issues[] = 'other';
        }

        $primary = $this->pickPrimary($issues, $matchReason);
        $safe = $this->isSafeRepairCandidate($primary, $issues);
        $manual = ! $safe || in_array($primary, [
            'missing_ca_name',
            'missing_city',
            'missing_firm_name',
            'firm_name_person_extraction_conflict',
            'invalid_person_name',
            'multiple_possible_ca_names',
            'low_confidence',
            'other',
        ], true);

        // Address/building clearouts are safe to reclassify, but still need review before Master accept.
        if (in_array($primary, [
            'numeric_prefix_address',
            'building_name_detected_as_ca_name',
            'address_detected_as_ca_name',
        ], true)) {
            $manual = true;
            $safe = true;
        }

        return [
            'id' => (int) $firm->id,
            'ocr_document_id' => (int) $firm->ocr_document_id,
            'row_number' => $firm->row_number ?? $firm->sequence_no,
            'firm_name' => $firmName,
            'raw_firm_name' => $rawFirm,
            'raw_ca_name' => $rawCa,
            'parsed_ca_name' => $parsedCa,
            'normalized_ca_name' => $normalizedCa,
            'city' => $city,
            'review_status' => (string) ($firm->review_status ?? ''),
            'match_status' => (string) ($firm->match_status ?? ''),
            'match_reason' => $matchReason,
            'overall_confidence' => $firm->overall_confidence,
            'primary_category' => $primary,
            'issue_codes' => array_values(array_unique($issues)),
            'safe_repair_candidate' => $safe,
            'manual_review_required' => $manual,
            'source_fingerprint' => $firm->source_fingerprint,
            'business_fingerprint' => $firm->business_fingerprint,
            'token_report' => $tokenReport,
            'invalid_json' => $invalidJson,
            'missing_source_data' => $missingSource,
            'ca_name_evidence' => is_array($fieldMeta['ca_name'] ?? null) ? ($fieldMeta['ca_name']['evidence'] ?? null) : null,
        ];
    }

    /**
     * @param  list<string>  $issues
     */
    private function pickPrimary(array $issues, string $matchReason): string
    {
        $priority = [
            'missing_firm_name',
            'numeric_prefix_address',
            'building_name_detected_as_ca_name',
            'address_detected_as_ca_name',
            'firm_name_person_extraction_conflict',
            'initials_expansion',
            'raw_and_parsed_ca_name_minor_difference',
            'parser_changed_raw_value',
            'invalid_person_name',
            'missing_ca_name',
            'missing_city',
            'multiple_possible_ca_names',
            'duplicate_candidate',
            'low_confidence',
            'other',
        ];

        // Prefer silent-correction specific buckets when reason says so.
        if (str_contains(mb_strtolower($matchReason), 'silent correction')) {
            foreach ([
                'numeric_prefix_address',
                'building_name_detected_as_ca_name',
                'address_detected_as_ca_name',
                'firm_name_person_extraction_conflict',
                'initials_expansion',
                'raw_and_parsed_ca_name_minor_difference',
                'parser_changed_raw_value',
            ] as $preferred) {
                if (in_array($preferred, $issues, true)) {
                    return $preferred;
                }
            }
        }

        foreach ($priority as $code) {
            if (in_array($code, $issues, true)) {
                return $code;
            }
        }

        return 'other';
    }

    /**
     * @param  list<string>  $issues
     */
    private function isSafeRepairCandidate(string $primary, array $issues): bool
    {
        if (in_array($primary, [
            'numeric_prefix_address',
            'building_name_detected_as_ca_name',
            'address_detected_as_ca_name',
            'raw_and_parsed_ca_name_minor_difference',
        ], true)) {
            return true;
        }

        return false;
    }

    private function categorySafeRepairDefault(string $category): string
    {
        return in_array($category, [
            'numeric_prefix_address',
            'building_name_detected_as_ca_name',
            'address_detected_as_ca_name',
            'raw_and_parsed_ca_name_minor_difference',
        ], true) ? 'yes (reclassify / normalize only)' : 'no';
    }

    private function categoryManualReviewDefault(string $category): string
    {
        return in_array($category, [
            'numeric_prefix_address',
            'building_name_detected_as_ca_name',
            'address_detected_as_ca_name',
            'raw_and_parsed_ca_name_minor_difference',
        ], true) ? 'after repair / before Master accept' : 'yes';
    }

    private function isBlankCa(string $rawCa, string $parsedCa, string $normalizedCa): bool
    {
        return $rawCa === '' && $parsedCa === '' && $normalizedCa === '';
    }

    private function hasLeadingNumericPrefix(string $text): bool
    {
        return (bool) preg_match('#^\d{1,6}[\s.\-/]+\p{L}#u', trim($text));
    }

    private function looksLikeBuildingName(string $text): bool
    {
        $upper = mb_strtoupper(trim($text));
        foreach (['SQUARE', 'CENTRE', 'CENTER', 'COMPLEX', 'PLAZA', 'TOWER', 'BUILDING', 'TENAMENT', 'TENEMENT', 'BUSINESS', 'CHAMBERS', 'COMM'] as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/u', $upper)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeAddressOrBuilding(string $text): bool
    {
        $upper = mb_strtoupper(trim($text));
        foreach (self::BUILDING_WORDS as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/u', $upper)) {
                return true;
            }
        }

        return $this->hasLeadingNumericPrefix($text);
    }

    /**
     * @param  array{raw_tokens: list<string>, parsed_tokens: list<string>, added: list<string>, removed: list<string>}  $tokenReport
     */
    private function isFirmPersonExtractionConflict(string $firmName, string $rawCa, string $parsedCa, array $tokenReport): bool
    {
        if ($firmName === '' || $rawCa === '' || $parsedCa === '') {
            return false;
        }
        if (mb_strtoupper($rawCa) === mb_strtoupper($parsedCa)) {
            return false;
        }
        $derived = $this->derivePersonFromFirm($firmName);
        if ($derived === null) {
            return false;
        }
        $derivedUp = mb_strtoupper($derived);
        $parsedUp = mb_strtoupper($parsedCa);
        if ($derivedUp !== $parsedUp && ! $this->tokensCompatible(mb_strtoupper($rawCa), $parsedUp)) {
            // Still conflict if parsed is longer expansion of raw and firm contains parsed tokens.
            if (! $this->firmContainsPersonTokens($firmName, $parsedCa)) {
                return false;
            }
        }
        if ($derivedUp === $parsedUp || $this->firmContainsPersonTokens($firmName, $parsedCa)) {
            return count($tokenReport['parsed_tokens']) >= count($tokenReport['raw_tokens'])
                && ($tokenReport['added'] !== [] || $tokenReport['removed'] !== []);
        }

        return false;
    }

    private function derivePersonFromFirm(string $firmName): ?string
    {
        $base = trim((string) preg_replace(
            '/\s+(?:&\s*|AND\s+)?(?:ASSOCIATES|CO\.?|COMPANY|LLP|CHARTERED\s+ACCOUNTANTS)\s*$/iu',
            '',
            $firmName,
        ));
        $base = trim((string) preg_replace('/\s*(?:&|AND)\s*$/iu', '', $base));
        $base = trim(preg_replace('/\s+/', ' ', $base) ?? '');
        if ($base === '' || mb_strtoupper($base) === mb_strtoupper($firmName)) {
            return null;
        }
        $words = preg_split('/\s+/', $base) ?: [];
        if (count($words) < 2 || count($words) > 4) {
            return null;
        }

        return $base;
    }

    private function firmContainsPersonTokens(string $firmName, string $person): bool
    {
        $firmTokens = $this->tokens($firmName);
        $personTokens = $this->tokens($person);
        if ($personTokens === []) {
            return false;
        }
        foreach ($personTokens as $token) {
            if (! in_array($token, $firmTokens, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{raw_tokens: list<string>, parsed_tokens: list<string>, added: list<string>, removed: list<string>}  $tokenReport
     */
    private function isInitialsExpansion(array $tokenReport): bool
    {
        if ($tokenReport['added'] === [] || $tokenReport['removed'] !== []) {
            return false;
        }
        foreach ($tokenReport['added'] as $token) {
            if (mb_strlen($token) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{raw_tokens: list<string>, parsed_tokens: list<string>, added: list<string>, removed: list<string>}  $tokenReport
     */
    private function isMinorDifference(string $raw, string $parsed, array $tokenReport): bool
    {
        $r = mb_strtoupper(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $raw) ?? $raw);
        $p = mb_strtoupper(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $parsed) ?? $parsed);
        $r = trim(preg_replace('/\s+/', ' ', $r) ?? $r);
        $p = trim(preg_replace('/\s+/', ' ', $p) ?? $p);
        if ($r === $p) {
            return true;
        }

        // Single added/removed single-letter initial only.
        if (count($tokenReport['added']) <= 1 && count($tokenReport['removed']) === 0) {
            foreach ($tokenReport['added'] as $token) {
                if (mb_strlen($token) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private function tokensCompatible(string $a, string $b): bool
    {
        $ta = $this->tokens($a);
        $tb = $this->tokens($b);
        if ($ta === [] || $tb === []) {
            return false;
        }

        return ($ta[0] ?? null) === ($tb[0] ?? null);
    }

    /**
     * @return array{raw_tokens: list<string>, parsed_tokens: list<string>, added: list<string>, removed: list<string>, compatible: bool}
     */
    private function tokenDiff(string $raw, string $parsed): array
    {
        $rawTokens = $this->tokens($raw);
        $parsedTokens = $this->tokens($parsed);
        $added = array_values(array_diff($parsedTokens, $rawTokens));
        $removed = array_values(array_diff($rawTokens, $parsedTokens));

        return [
            'raw_tokens' => $rawTokens,
            'parsed_tokens' => $parsedTokens,
            'added' => $added,
            'removed' => $removed,
            'compatible' => $this->tokensCompatible($raw, $parsed),
        ];
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $clean = mb_strtoupper(trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text));
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');
        if ($clean === '') {
            return [];
        }
        $parts = preg_split('/\s+/', $clean) ?: [];

        return array_values(array_filter($parts, fn ($p) => $p !== ''));
    }

    /**
     * @param  array<string, mixed>  $validation
     */
    private function isInvalidPersonSignal(string $matchReason, array $validation, string $rawCa, string $parsedCa): bool
    {
        $hay = mb_strtolower($matchReason.' '.json_encode($validation['errors'] ?? []));
        if (str_contains($hay, 'not look like a valid person')
            || str_contains($hay, 'invalid_person')
            || str_contains($hay, 'invalid person')) {
            return true;
        }
        $ca = $parsedCa !== '' ? $parsedCa : $rawCa;
        if ($ca !== '' && $this->looksLikeAddressOrBuilding($ca) && ! $this->looksLikePersonShape($ca)) {
            return true;
        }

        return false;
    }

    private function looksLikePersonShape(string $text): bool
    {
        if ($this->looksLikeAddressOrBuilding($text) && $this->hasLeadingNumericPrefix($text)) {
            return false;
        }
        $words = $this->tokens($text);
        if (count($words) < 1 || count($words) > 4) {
            return false;
        }
        foreach ($words as $w) {
            if (preg_match('/\d/', $w)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $fieldMeta
     */
    private function hasMultiplePossibleCaNames(array $source, array $fieldMeta): bool
    {
        $candidates = $source['match_candidates'] ?? ($fieldMeta['ca_name']['candidates'] ?? null);
        if (is_array($candidates) && count($candidates) > 1) {
            return true;
        }
        $members = $source['members'] ?? null;
        if (is_array($members) && count($members) > 1) {
            $names = [];
            foreach ($members as $member) {
                if (! is_array($member)) {
                    continue;
                }
                $name = trim((string) ($member['ca_name'] ?? $member['raw_ca_name'] ?? ''));
                if ($name !== '') {
                    $names[mb_strtoupper($name)] = true;
                }
            }
            if (count($names) > 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $fieldMeta
     */
    private function isLowConfidence(mixed $overall, array $fieldMeta, string $matchReason): bool
    {
        if (str_contains(mb_strtolower($matchReason), 'low') && str_contains(mb_strtolower($matchReason), 'confidence')) {
            return true;
        }
        if (is_numeric($overall) && (float) $overall > 0 && (float) $overall < 0.55) {
            return true;
        }
        $caConf = $fieldMeta['ca_name']['confidence'] ?? $fieldMeta['ca_name']['ocr_confidence'] ?? null;
        if (is_numeric($caConf) && (float) $caConf > 0 && (float) $caConf < 0.55) {
            return true;
        }

        return false;
    }

    private function isDuplicateCandidate(OcrParsedFirm $firm, string $matchReason): bool
    {
        if ($firm->matched_ca_id || $firm->matched_reference_firm_id) {
            return true;
        }
        $hay = mb_strtolower($matchReason);

        return str_contains($hay, 'duplicate') || str_contains($hay, 'exact_official');
    }

    private function resolveExportPath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('#^[A-Za-z]:\\\\#', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }
}
