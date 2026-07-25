<?php

namespace App\Services\Ocr;

use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Propose Firm/CA/City repairs for needs_review staging rows from stored OCR only.
 * Never invents values. Dry-run safe (no writes).
 */
class OcrNeedsReviewProposalService
{
    /** @var array<int, array<string, mixed>> */
    private array $pageCache = [];

    public function __construct(
        private readonly ?OcrEntityClassificationService $entities = null,
        private readonly ?OcrHumanNameClassifier $humans = null,
        private readonly ?OcrFirmCaCityExtractorService $extractor = null,
        private readonly ?OcrCityContextResolver $cityResolver = null,
        private readonly ?OcrNeedsReviewClassifier $classifier = null,
    ) {}

    private function entities(): OcrEntityClassificationService
    {
        return $this->entities ?? new OcrEntityClassificationService;
    }

    private function humans(): OcrHumanNameClassifier
    {
        return $this->humans ?? new OcrHumanNameClassifier;
    }

    private function extractor(): OcrFirmCaCityExtractorService
    {
        return $this->extractor ?? new OcrFirmCaCityExtractorService;
    }

    private function cities(): OcrCityContextResolver
    {
        return $this->cityResolver ?? new OcrCityContextResolver;
    }

    private function classifier(): OcrNeedsReviewClassifier
    {
        return $this->classifier ?? new OcrNeedsReviewClassifier;
    }

    /**
     * @param  array{
     *   document?: int|null,
     *   ca_id?: int|null,
     *   limit?: int,
     *   category?: string|null,
     *   resume_from?: int,
     *   only_parser_fix?: bool,
     *   include_complete_check?: bool,
     *   audit_categories?: array<string, string>|null
     * }  $options
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int|float>}
     */
    public function propose(array $options = []): array
    {
        $documentId = isset($options['document']) ? (int) $options['document'] : null;
        $caId = isset($options['ca_id']) ? (int) $options['ca_id'] : null;
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $resumeFrom = max(0, (int) ($options['resume_from'] ?? 0));
        $categoryFilter = isset($options['category']) ? strtoupper(trim((string) $options['category'])) : '';
        $onlyParserFix = (bool) ($options['only_parser_fix'] ?? false);
        $includeCompleteCheck = (bool) ($options['include_complete_check'] ?? true);
        /** @var array<string, string>|null $auditCategories firm_id => A|B|C|D|F */
        $auditCategories = $options['audit_categories'] ?? null;

        $query = OcrParsedFirm::query()
            ->where('match_status', 'needs_review')
            ->orderBy('id');
        if ($documentId) {
            $query->where('ocr_document_id', $documentId);
        }
        if ($caId) {
            $query->where(function ($q) use ($caId) {
                $q->where('crm_ca_id', $caId)->orWhere('matched_ca_id', $caId);
            });
        }
        if ($resumeFrom > 0) {
            $query->where('id', '>=', $resumeFrom);
        }

        $rows = [];
        $totals = [
            'scanned' => 0,
            'unchanged' => 0,
            'ca_recovered' => 0,
            'city_recovered' => 0,
            'firm_recovered' => 0,
            'member_links_recovered' => 0,
            'complete_after' => 0,
            'still_needs_review' => 0,
            'conflicts' => 0,
            'errors' => 0,
            'duplicates_prevented' => 0,
            'manual_override_skipped' => 0,
            'category_A' => 0,
            'category_B' => 0,
            'category_C' => 0,
            'category_D' => 0,
            'category_F' => 0,
            'category_other' => 0,
            'automatically_recoverable' => 0,
        ];

        $processed = 0;
        $query->chunkById(200, function (Collection $firms) use (
            &$rows, &$totals, &$processed, $limit, $categoryFilter,
            $onlyParserFix, $includeCompleteCheck, $auditCategories
        ) {
            $memberCa = $this->loadPrimaryMemberCasForIds($firms->pluck('id')->all());
            foreach ($firms as $firm) {
                if ($limit > 0 && $processed >= $limit) {
                    return false;
                }
                $processed++;
                $totals['scanned']++;

                try {
                    $proposal = $this->proposeOne($firm, $memberCa[(int) $firm->id] ?? null, $includeCompleteCheck);
                } catch (\Throwable $e) {
                    $totals['errors']++;
                    $rows[] = $this->errorRow($firm, $e->getMessage());

                    continue;
                }

                $auditCat = $auditCategories[(string) $firm->id]
                    ?? $auditCategories[(int) $firm->id]
                    ?? null;
                if ($auditCat === null) {
                    $docNameKey = ((int) $firm->ocr_document_id).'|'.mb_strtoupper(trim((string) $firm->firm_name));
                    $auditCat = $auditCategories[$docNameKey] ?? $proposal['derived_category'];
                }
                $proposal['category'] = $auditCat;
                $totals['category_'.$auditCat] = ($totals['category_'.$auditCat] ?? 0) + 1;

                if ($categoryFilter !== '' && $auditCat !== $categoryFilter) {
                    continue;
                }
                if ($onlyParserFix && ! in_array($auditCat, ['A', 'F'], true)) {
                    continue;
                }

                if (! empty($proposal['manual_override'])) {
                    $totals['manual_override_skipped']++;
                }
                if (! empty($proposal['conflict'])) {
                    $totals['conflicts']++;
                }
                if ($proposal['action'] === 'unchanged') {
                    $totals['unchanged']++;
                }
                if ($proposal['ca_recovered']) {
                    $totals['ca_recovered']++;
                }
                if ($proposal['city_recovered']) {
                    $totals['city_recovered']++;
                }
                if ($proposal['complete_after']) {
                    $totals['complete_after']++;
                    $totals['automatically_recoverable']++;
                } else {
                    $totals['still_needs_review']++;
                }

                $rows[] = $proposal;
            }

            return $limit <= 0 || $processed < $limit;
        });

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * @param  array<int, string>  $memberCa
     * @return array<string, mixed>
     */
    public function proposeOne(OcrParsedFirm $firm, ?string $memberCa, bool $includeCompleteCheck = true): array
    {
        $sd = is_array($firm->source_data) ? $firm->source_data : [];
        if ($sd === [] && is_string($firm->getRawOriginal('source_data') ?? null)) {
            $sd = json_decode((string) $firm->getRawOriginal('source_data'), true) ?: [];
        }

        $firmName = trim((string) ($firm->firm_name ?: ($sd['parsed']['firm_name'] ?? $sd['raw']['firm_name'] ?? '')));
        $currentCa = trim((string) (($sd['parsed']['ca_name'] ?? '') ?: ($memberCa ?? '')));
        $rawCa = trim((string) ($sd['raw']['ca_name'] ?? ''));
        $currentCity = trim((string) ($firm->city ?: ($sd['parsed']['city'] ?? $sd['raw']['city'] ?? '')));
        $currentStatus = (string) ($firm->match_status ?? '');
        $currentReason = (string) ($firm->match_reason ?? '');

        $manual = $this->hasManualOverride($sd);
        $pageParas = $this->pageParagraphs((int) $firm->ocr_document_id, (int) ($firm->page_number ?? 0));

        $persons = $this->nearbyPersons($pageParas, $firmName, $currentCity ?: null);
        $peelable = $firmName !== '' ? $this->extractor()->suggestCaFromFirmName($firmName, $currentCity ?: null) : null;

        $rawIsPerson = $rawCa !== '' && $this->isStrictPerson($rawCa, $firmName, $currentCity ?: null);
        $rawIsNoise = $rawCa !== '' && ! $rawIsPerson;

        $proposedCa = $currentCa;
        $caMethod = $currentCa !== '' ? 'existing_parsed_member' : null;
        $caEvidence = $currentCa !== '' ? 'staging' : null;
        $caConfidence = $currentCa !== '' ? 0.95 : 0.0;
        $conflict = false;
        $conflictDetail = null;

        if ($currentCa === '') {
            if (count($persons) === 1) {
                $proposedCa = $persons[0];
                $caMethod = 'nearby_member_paragraph';
                $caEvidence = $persons[0];
                $caConfidence = 0.88;
            } elseif (count($persons) > 1) {
                // Deterministic: first person in reading order after firm (ICAI partner list order).
                $proposedCa = $persons[0];
                $caMethod = 'nearby_member_paragraph';
                $caEvidence = implode('|', array_slice($persons, 0, 4));
                $caConfidence = 0.84;
            } elseif ($peelable !== null) {
                $proposedCa = $peelable;
                $caMethod = 'proprietor_name_peel';
                $caEvidence = $firmName;
                $caConfidence = 0.78;
            } elseif ($rawIsPerson) {
                $proposedCa = $rawCa;
                $caMethod = 'normalized_raw_field';
                $caEvidence = $rawCa;
                $caConfidence = 0.8;
            }
        } elseif ($rawIsPerson && mb_strtoupper($rawCa) !== mb_strtoupper($currentCa)) {
            // Both valid — do not silently pick; keep current, flag conflict.
            $conflict = true;
            $conflictDetail = 'raw='.$rawCa.'; parsed='.$currentCa;
        }

        $cityResult = $this->cities()->resolveForFirm($currentCity, $sd, $pageParas, [
            'firm_name' => $firmName,
            'column' => $firm->column_number ?? ($sd['column_number'] ?? null),
        ]);
        $proposedCity = $cityResult['city'] ?? $currentCity;
        $cityMethod = $currentCity !== '' ? 'source_data_field' : ($cityResult['method'] ?? null);
        $cityEvidence = $currentCity !== '' ? 'staging' : ($cityResult['evidence'] ?? null);
        $cityConfidence = $currentCity !== '' ? 0.95 : (float) ($cityResult['confidence'] ?? 0);

        $hasCaInSource = $currentCa !== '' || $peelable !== null || $persons !== [] || $rawIsPerson;
        $hasCityInSource = $currentCity !== '' || ($cityResult['city'] ?? null) !== null;

        $classified = $this->classifier()->classify([
            'firm_name' => $firmName,
            'ca_name' => $proposedCa,
            'city' => $proposedCity,
            'has_ca_in_source' => $hasCaInSource,
            'has_city_in_source' => $hasCityInSource,
            'extraction_method' => $caMethod,
            'raw_parsed_conflict' => $conflict,
            'ambiguous_members' => false,
        ]);

        // Category A: already complete staging but stale needs_review
        if ($includeCompleteCheck
            && $firmName !== '' && $currentCa !== '' && $currentCity !== ''
            && $currentStatus === 'needs_review') {
            $classified = [
                'complete' => true,
                'match_status' => 'verified',
                'reason' => OcrNeedsReviewClassifier::COMPLETE_BUT_STATUS_STALE,
            ];
            $caMethod = $caMethod ?? 'existing_parsed_member';
            $cityMethod = $cityMethod ?? 'source_data_field';
        }

        if ($manual && ($proposedCa !== $currentCa || $proposedCity !== $currentCity)) {
            $proposedCa = $currentCa;
            $proposedCity = $currentCity;
            $classified = $this->classifier()->classify([
                'firm_name' => $firmName,
                'ca_name' => $proposedCa,
                'city' => $proposedCity,
                'has_ca_in_source' => $hasCaInSource,
                'has_city_in_source' => $hasCityInSource,
            ]);
        }

        $caRecovered = $currentCa === '' && $proposedCa !== '';
        $cityRecovered = $currentCity === '' && $proposedCity !== '';
        $completeAfter = $firmName !== '' && $proposedCa !== '' && $proposedCity !== '' && ! $conflict;
        $statusChanges = $completeAfter && $currentStatus === 'needs_review';

        $action = 'unchanged';
        if ($manual && ($caRecovered || $cityRecovered)) {
            $action = 'skipped_manual_override';
        } elseif ($conflict) {
            $action = 'conflict_keep_needs_review';
        } elseif ($caRecovered || $cityRecovered || $statusChanges) {
            $action = 'would_update_staging';
        }

        $derivedCategory = $this->deriveCategory(
            $firmName,
            $currentCa,
            $currentCity,
            $proposedCa,
            $proposedCity,
            $hasCaInSource,
            $hasCityInSource,
            $completeAfter,
            $caRecovered || $cityRecovered || $statusChanges,
        );

        $methods = array_values(array_filter([$caMethod, $cityMethod]));

        return [
            'ca_id' => $firm->crm_ca_id ?: ($firm->matched_ca_id ?: ''),
            'document_id' => $firm->ocr_document_id,
            'firm_row_id' => $firm->id,
            'page_number' => $firm->page_number,
            'current_firm_name' => $firmName,
            'proposed_firm_name' => $firmName,
            'current_ca_name' => $currentCa,
            'proposed_ca_name' => $proposedCa,
            'current_city' => $currentCity,
            'proposed_city' => $proposedCity ?? '',
            'current_status' => $currentStatus,
            'proposed_status' => $classified['match_status'],
            'current_review_reason' => $currentReason,
            'proposed_review_reason' => $classified['reason'],
            'extraction_method' => implode('+', $methods),
            'evidence' => trim(($caEvidence ?? '').'; '.($cityEvidence ?? '').($conflictDetail ? ' CONFLICT:'.$conflictDetail : '')),
            'confidence' => round(min($caConfidence ?: 0.5, $cityConfidence ?: 0.5), 4),
            'action' => $action,
            'conflict' => $conflict ? ($conflictDetail ?? '1') : '',
            'category' => $derivedCategory,
            'derived_category' => $derivedCategory,
            'ca_recovered' => $caRecovered,
            'city_recovered' => $cityRecovered,
            'complete_after' => $completeAfter,
            'manual_override' => $manual,
            'partners_found' => $persons,
            'raw_ca_noise' => $rawIsNoise ? $rawCa : '',
        ];
    }

    private function deriveCategory(
        string $firm,
        string $curCa,
        string $curCity,
        string $propCa,
        ?string $propCity,
        bool $hasCaSource,
        bool $hasCitySource,
        bool $completeAfter,
        bool $wouldChange,
    ): string {
        if ($firm !== '' && $curCa !== '' && $curCity !== '') {
            return 'A';
        }
        if ($completeAfter && $wouldChange) {
            return 'F';
        }
        if ($firm !== '' && ($curCa !== '' || $propCa !== '') && ($curCity === '' && ! $hasCitySource)) {
            return 'B';
        }
        if ($firm !== '' && ($curCity !== '' || $propCity) && ($curCa === '' && ! $hasCaSource)) {
            return 'C';
        }
        if ($firm !== '' && $curCa === '' && $curCity === '' && ! $hasCaSource && ! $hasCitySource) {
            return 'D';
        }
        if ($wouldChange || $hasCaSource || $hasCitySource) {
            return 'F';
        }

        return 'C';
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<int, string>
     */
    private function loadPrimaryMemberCasForIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        $rows = DB::table('ocr_parsed_members')
            ->whereIn('ocr_parsed_firm_id', $ids)
            ->orderByDesc('is_primary')
            ->orderBy('sequence_no')
            ->get(['ocr_parsed_firm_id', 'ca_name', 'raw_ca_name']);
        foreach ($rows as $m) {
            $fid = (int) $m->ocr_parsed_firm_id;
            if (isset($out[$fid])) {
                continue;
            }
            $ca = trim((string) ($m->ca_name ?: $m->raw_ca_name));
            if ($ca !== '') {
                $out[$fid] = $ca;
            }
        }

        return $out;
    }

    /**
     * Nearby OCR lines for a firm — uses extracted_text only (never loads full structured_data).
     *
     * @return list<string>
     */
    private function pageParagraphs(int $documentId, int $page): array
    {
        if ($page <= 0 || $documentId <= 0) {
            return [];
        }
        if (isset($this->pageCache[$documentId][$page])) {
            return $this->pageCache[$documentId][$page];
        }
        if (! isset($this->pageCache[$documentId])) {
            $this->pageCache[$documentId] = [];
        }

        $doc = OcrDocument::query()->whereKey($documentId)->first(['id', 'extracted_text']);
        $text = trim((string) ($doc->extracted_text ?? ''));
        if ($text === '') {
            $this->pageCache[$documentId][$page] = [];

            return [];
        }

        // Split once per document; reuse across pages of same doc.
        if (! isset($this->pageCache[$documentId]['_lines'])) {
            $lines = preg_split('/\R+/u', $text) ?: [];
            $clean = [];
            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ($line !== '') {
                    $clean[] = $line;
                }
            }
            $this->pageCache[$documentId]['_lines'] = $clean;
        }

        // Without per-page markers in extracted_text, expose full line list once
        // (nearbyPersons windows around the firm title — still deterministic).
        $this->pageCache[$documentId][$page] = $this->pageCache[$documentId]['_lines'];

        return $this->pageCache[$documentId][$page];
    }

    /**
     * @param  list<string>  $paras
     * @return list<string>
     */
    private function nearbyPersons(array $paras, string $firmName, ?string $city): array
    {
        $fu = mb_strtoupper(trim($firmName));
        $idx = null;
        foreach ($paras as $i => $t) {
            $u = mb_strtoupper(trim($t));
            if ($fu !== '' && ($u === $fu || str_contains($u, $fu))) {
                $idx = $i;
                break;
            }
        }
        $slice = $idx === null ? $paras : array_slice($paras, $idx, 12);
        $persons = [];
        $seen = [];
        foreach ($slice as $t) {
            $t = trim($t);
            if ($t === '' || mb_strtoupper($t) === $fu) {
                continue;
            }
            // Stop at next firm boundary
            if ($this->entities()->isFirmName($t) && ! $this->isStrictPerson($t, $firmName, $city)) {
                if ($persons !== []) {
                    break;
                }
                continue;
            }
            if ($this->isStrictPerson($t, $firmName, $city)) {
                $k = mb_strtolower($t);
                if (! isset($seen[$k])) {
                    $seen[$k] = true;
                    $persons[] = $t;
                }
            }
        }

        return $persons;
    }

    private function isStrictPerson(string $u, string $firmName, ?string $city): bool
    {
        $u = trim($u);
        if ($u === '' || mb_strlen($u) < 5 || preg_match('/\d/', $u)) {
            return false;
        }
        if (preg_match('/(?:ASSOCIATES|COMPANY|\bCO\b|LLP|PRIVATE|ROAD|FLOOR|PLOT|NAGAR|COLONY|APARTMENT|COMPLEX|TOWER|CHOWK|DISTRICT|Head Office|MATRIX)/iu', $u)) {
            return false;
        }
        if ($this->entities()->isCity($u) || $this->entities()->isFirmName($u) || $this->entities()->isAddress($u) || $this->entities()->isAddressShape($u)) {
            return false;
        }
        if (! $this->entities()->isPerson($u) || ! $this->humans()->isValid($u, $firmName !== '' ? $firmName : null, $city)) {
            return false;
        }
        $parts = preg_split('/\s+/u', $u) ?: [];

        return count($parts) >= 2;
    }

    private function hasManualOverride(array $sd): bool
    {
        if (! empty($sd['manual_override']) || ! empty($sd['manual_corrected'])) {
            return true;
        }
        $meta = is_array($sd['field_meta'] ?? null) ? $sd['field_meta'] : [];
        foreach (['firm_name', 'ca_name', 'city'] as $f) {
            if (! empty($meta[$f]['manual_override']) || ! empty($meta[$f]['locked'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function errorRow(OcrParsedFirm $firm, string $message): array
    {
        return [
            'ca_id' => $firm->crm_ca_id ?: '',
            'document_id' => $firm->ocr_document_id,
            'firm_row_id' => $firm->id,
            'page_number' => $firm->page_number,
            'current_firm_name' => $firm->firm_name,
            'proposed_firm_name' => $firm->firm_name,
            'current_ca_name' => '',
            'proposed_ca_name' => '',
            'current_city' => $firm->city,
            'proposed_city' => $firm->city,
            'current_status' => $firm->match_status,
            'proposed_status' => 'needs_review',
            'current_review_reason' => $firm->match_reason,
            'proposed_review_reason' => 'error:'.$message,
            'extraction_method' => '',
            'evidence' => $message,
            'confidence' => 0,
            'action' => 'error',
            'conflict' => '',
            'category' => 'G',
            'derived_category' => 'G',
            'ca_recovered' => false,
            'city_recovered' => false,
            'complete_after' => false,
            'manual_override' => false,
            'partners_found' => [],
            'raw_ca_noise' => '',
        ];
    }
}
