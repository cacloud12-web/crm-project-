<?php

namespace App\Services\Ocr;

use App\Models\CaMaster;
use App\Models\OcrDocument;
use App\Models\OcrForcedReviewCandidate;
use App\Models\OcrParsedFirm;
use App\Models\OcrQuarantineImportAudit;
use App\Models\OcrQuarantineImportBatch;
use App\Services\Mapping\FirmCaCityMatchingProfile;
use App\Services\Mapping\MasterDataMappingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Quarantined bulk workflow for OCR needs_review rows.
 * Retains all rows; auto-writes Master only for otherwise_valid that pass gates.
 * Never overwrites existing ca_masters fields.
 */
class OcrQuarantinedBulkImportService
{
    public const CATEGORIES = [
        'missing_ca_name',
        'missing_city',
        'firm_ca_conflict',
        'address_used_as_ca',
        'duplicate',
        'ambiguous',
        'otherwise_valid',
    ];

    public function __construct(
        private readonly OcrUnlinkedCaNameAuditService $audit,
        private readonly OcrEntityClassificationService $entities,
        private readonly MasterDataMappingService $mapping,
        private readonly FirmCaCityMatchingProfile $matcher,
    ) {}

    /**
     * @param  array{
     *   dry_run?: bool,
     *   apply?: bool,
     *   chunk?: int,
     *   resume?: bool,
     *   batch_id?: string|null,
     *   actor?: int|null,
     *   document?: int|null,
     *   limit?: int,
     *   backup_paths?: array<string, string>|null
     * }  $options
     * @return array<string, mixed>
     */
    public function run(array $options = []): array
    {
        $apply = (bool) ($options['apply'] ?? false);
        $dryRun = ! $apply;
        if (! empty($options['dry_run'])) {
            $dryRun = true;
            $apply = false;
        }

        $chunk = max(50, (int) ($options['chunk'] ?? 500));
        $resume = (bool) ($options['resume'] ?? false);
        $actorId = isset($options['actor']) ? (int) $options['actor'] : null;
        $documentId = isset($options['document']) ? (int) $options['document'] : null;
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $backupPaths = is_array($options['backup_paths'] ?? null) ? $options['backup_paths'] : null;

        $batch = $this->resolveBatch($options['batch_id'] ?? null, $resume, $dryRun, $chunk, $actorId, $backupPaths);
        $batchId = $batch->batch_id;
        $afterId = $resume ? (int) ($batch->last_ocr_parsed_firm_id ?? 0) : 0;

        $counts = $this->emptyCounts();
        $counts['batch_id'] = $batchId;
        $counts['dry_run'] = $dryRun;
        $counts['apply'] = $apply && ! $dryRun;

        if (! $dryRun && empty($batch->backup_paths)) {
            throw new \RuntimeException(
                'Refusing apply without backup_paths on the batch. Run backups first and pass --with-backup, or set backup_paths.'
            );
        }

        $batch->update([
            'status' => $dryRun ? 'dry_run' : 'running',
            'started_at' => $batch->started_at ?? now(),
            'dry_run' => $dryRun,
        ]);

        $processed = 0;
        $query = OcrParsedFirm::query()
            ->with(['members' => static fn ($q) => $q->orderByDesc('is_primary')->orderBy('sequence_no')])
            ->whereNull('crm_ca_id')
            ->where('match_status', 'needs_review')
            ->when($documentId, fn ($q) => $q->where('ocr_document_id', $documentId))
            ->when($afterId > 0, fn ($q) => $q->where('id', '>', $afterId))
            ->orderBy('id');

        $query->chunkById($chunk, function ($rows) use (
            &$counts,
            &$processed,
            $batch,
            $batchId,
            $dryRun,
            $apply,
            $actorId,
            $limit,
        ) {
            $lastId = null;
            foreach ($rows as $firm) {
                /** @var OcrParsedFirm $firm */
                $counts['scanned']++;
                $decision = $this->decide($firm);
                $category = $decision['category'];
                $counts['by_category'][$category] = ($counts['by_category'][$category] ?? 0) + 1;

                foreach ($decision['flags'] as $flag) {
                    $counts['flags'][$flag] = ($counts['flags'][$flag] ?? 0) + 1;
                }

                if ($decision['eligible_for_master']) {
                    $counts['eligible_for_master']++;
                } else {
                    $counts['quarantined']++;
                }

                if (! $dryRun && $apply) {
                    $this->persistCandidateAndMaybeImport($firm, $decision, $batchId, $actorId, $counts);
                }
                // Dry-run: counts only — no quarantine candidate rows, no Master writes.

                $lastId = $firm->id;
                $processed++;
                if ($limit > 0 && $processed >= $limit) {
                    if ($lastId !== null) {
                        $batch->update(['last_ocr_parsed_firm_id' => $lastId]);
                    }

                    return false;
                }
            }
            if ($lastId !== null) {
                $batch->update(['last_ocr_parsed_firm_id' => $lastId]);
            }

            return true;
        });

        $batch->update([
            'status' => $dryRun ? 'dry_run_complete' : 'completed',
            'summary' => $counts,
            'completed_at' => now(),
        ]);

        return $counts;
    }

    /**
     * Rollback Master creates from a batch (unlink OCR + soft-mark candidates).
     * Does not delete ca_masters rows that may already be referenced elsewhere — marks them and unlinks OCR.
     *
     * @return array<string, mixed>
     */
    public function rollback(string $batchId, ?int $actorId = null): array
    {
        $batch = OcrQuarantineImportBatch::query()->where('batch_id', $batchId)->firstOrFail();
        $unlinked = 0;
        $marked = 0;
        $createdIds = [];

        OcrForcedReviewCandidate::query()
            ->where('batch_id', $batchId)
            ->whereIn('disposition', [
                OcrForcedReviewCandidate::DISPOSITION_IMPORTED,
                OcrForcedReviewCandidate::DISPOSITION_LINKED,
            ])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$unlinked, &$marked, &$createdIds, $batchId, $actorId) {
                foreach ($rows as $candidate) {
                    /** @var OcrForcedReviewCandidate $candidate */
                    $before = $candidate->toArray();
                    DB::transaction(function () use ($candidate, &$unlinked, &$marked, &$createdIds) {
                        $firm = OcrParsedFirm::query()->find($candidate->ocr_parsed_firm_id);
                        if ($firm && (int) $firm->crm_ca_id === (int) $candidate->crm_ca_id) {
                            $firm->update([
                                'crm_ca_id' => null,
                                'matched_ca_id' => null,
                                'match_status' => 'needs_review',
                                'review_status' => OcrParsedFirm::REVIEW_PENDING,
                                'match_reason' => 'quarantine_batch_rollback',
                            ]);
                            $unlinked++;
                        }
                        if ($candidate->master_created && $candidate->crm_ca_id) {
                            $createdIds[] = (int) $candidate->crm_ca_id;
                        }
                        $candidate->update([
                            'disposition' => OcrForcedReviewCandidate::DISPOSITION_ROLLED_BACK,
                            'meta' => array_merge(is_array($candidate->meta) ? $candidate->meta : [], [
                                'rolled_back_at' => now()->toIso8601String(),
                            ]),
                        ]);
                        $marked++;
                    });
                    $this->audit($batchId, $candidate->ocr_parsed_firm_id, $candidate->id, 'rollback', $candidate->category, OcrForcedReviewCandidate::DISPOSITION_ROLLED_BACK, 'Rolled back quarantine import link', $before, $candidate->fresh()?->toArray(), $actorId, false);
                }
            });

        $batch->update([
            'status' => 'rolled_back',
            'summary' => array_merge(is_array($batch->summary) ? $batch->summary : [], [
                'rollback' => [
                    'unlinked' => $unlinked,
                    'candidates_marked' => $marked,
                    'master_created_ids' => $createdIds,
                    'note' => 'ca_masters rows created by this batch were NOT deleted automatically; review master_created_ids before any purge.',
                ],
            ]),
        ]);

        return [
            'batch_id' => $batchId,
            'unlinked' => $unlinked,
            'candidates_marked' => $marked,
            'master_created_ids' => $createdIds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function decide(OcrParsedFirm $firm): array
    {
        $classified = $this->audit->classifyRow($firm);
        $source = is_array($firm->source_data) ? $firm->source_data : [];
        $raw = is_array($source['raw'] ?? null) ? $source['raw'] : [];
        $parsed = is_array($source['parsed'] ?? null) ? $source['parsed'] : [];
        $issues = $classified['issue_codes'] ?? [];
        $primary = (string) ($classified['primary_category'] ?? 'other');

        $firmName = trim((string) ($firm->firm_name ?: $firm->raw_firm_name ?: ($parsed['firm_name'] ?? '') ?: ($raw['firm_name'] ?? '')));
        $caName = trim((string) (($parsed['ca_name'] ?? '') ?: ($raw['ca_name'] ?? '')));
        $city = trim((string) ($firm->city ?: ($parsed['city'] ?? '') ?: ($raw['city'] ?? '')));
        $flags = [];
        $problems = [];

        if ($firmName === '' || mb_strlen($firmName) < 3) {
            $problems[] = 'missing_or_short_firm_name';
        }
        if ($caName === '') {
            $flags[] = 'missing_ca';
            $problems[] = 'missing_ca_name';
        }
        if ($city === '') {
            $flags[] = 'missing_city';
            $problems[] = 'missing_city';
        }

        $addressAsCa = $caName !== '' && (
            in_array('numeric_prefix_address', $issues, true)
            || in_array('building_name_detected_as_ca_name', $issues, true)
            || in_array('address_detected_as_ca_name', $issues, true)
            || $this->entities->isAddress($caName)
            || $this->entities->isAddressShape($caName)
        );
        if ($addressAsCa) {
            $flags[] = 'address_as_ca';
            $problems[] = 'address_used_as_ca';
        }

        $firmConflict = in_array('firm_name_person_extraction_conflict', $issues, true)
            || in_array($primary, ['firm_name_person_extraction_conflict'], true);
        if ($firmConflict) {
            $flags[] = 'conflicts';
            $problems[] = 'firm_ca_conflict';
        }

        $duplicate = in_array('duplicate_candidate', $issues, true)
            || $this->looksLikeHighConfidenceDuplicate($firm, $firmName, $caName, $city);
        if ($duplicate) {
            $flags[] = 'duplicate';
            $problems[] = 'duplicate';
        }

        $validationErrors = is_array($firm->validation_errors) ? $firm->validation_errors : [];
        if ($validationErrors !== []) {
            $problems[] = 'validation_errors_present';
        }

        $partnersIncomplete = $this->partnersIncomplete($source, $parsed);
        if ($partnersIncomplete) {
            $problems[] = 'required_partner_data_incomplete';
        }

        $category = $this->mapCategory($primary, $issues, $addressAsCa, $firmConflict, $duplicate, $caName, $city, $problems);

        $blockReason = null;
        $eligible = false;
        if ($category !== 'otherwise_valid') {
            $blockReason = 'category:'.$category;
        } elseif ($problems !== []) {
            $blockReason = implode(',', $problems);
            $category = $this->fallbackCategoryFromProblems($problems);
        } else {
            // Final gate: identity + matcher conflict check (no write).
            $payload = $this->payloadFromFirm($firm, $firmName, $caName, $city);
            if (! $this->hasThreeFieldIdentity($payload)) {
                $blockReason = 'incomplete_firm_ca_city';
                $category = $caName === '' ? 'missing_ca_name' : ($city === '' ? 'missing_city' : 'ambiguous');
                $flags[] = $category === 'missing_ca_name' ? 'missing_ca' : ($category === 'missing_city' ? 'missing_city' : 'ambiguous');
            } else {
                $match = $this->matcher->match($payload);
                if ($match->isConflict()) {
                    $blockReason = 'master_match_conflict';
                    $category = 'ambiguous';
                    $flags[] = 'conflicts';
                    $problems[] = 'master_match_conflict';
                } elseif ($match->isExact() && $match->caId) {
                    $blockReason = 'duplicate_existing_master_no_overwrite';
                    $category = 'duplicate';
                    $flags[] = 'duplicate';
                    $problems[] = 'duplicate';
                } else {
                    $eligible = true;
                }
            }
        }

        return [
            'category' => $category,
            'eligible_for_master' => $eligible,
            'block_reason' => $blockReason,
            'flags' => array_values(array_unique($flags)),
            'problems' => array_values(array_unique($problems)),
            'firm_name' => $firmName,
            'ca_name' => $caName,
            'city' => $city,
            'classified' => $classified,
            'match_preview' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $decision
     * @param  array<string, mixed>  $counts
     */
    private function persistCandidateAndMaybeImport(
        OcrParsedFirm $firm,
        array $decision,
        string $batchId,
        ?int $actorId,
        array &$counts,
    ): void {
        if (! $decision['eligible_for_master']) {
            $candidate = $this->upsertCandidate(
                $firm,
                $decision,
                $batchId,
                OcrForcedReviewCandidate::DISPOSITION_QUARANTINED,
                dryRun: false,
            );
            $this->audit($batchId, $firm->id, $candidate->id, 'quarantine', $decision['category'], OcrForcedReviewCandidate::DISPOSITION_QUARANTINED, $decision['block_reason'], null, null, $actorId, false);

            return;
        }

        $candidate = $this->upsertCandidate(
            $firm,
            $decision,
            $batchId,
            OcrForcedReviewCandidate::DISPOSITION_ELIGIBLE,
            dryRun: false,
        );

        try {
            $result = $this->importWithoutOverwrite($firm, $actorId);
            $disposition = $result['disposition'];
            $candidate->update([
                'disposition' => $disposition,
                'crm_ca_id' => $result['crm_ca_id'],
                'master_created' => $result['master_created'],
                'master_overwritten' => false,
                'block_reason' => $result['reason'],
                'meta' => array_merge(is_array($candidate->meta) ? $candidate->meta : [], [
                    'import_result' => $result,
                ]),
            ]);
            if ($disposition === OcrForcedReviewCandidate::DISPOSITION_IMPORTED) {
                $counts['imported']++;
            } elseif ($disposition === OcrForcedReviewCandidate::DISPOSITION_LINKED) {
                $counts['linked_existing']++;
            } else {
                $counts['quarantined']++;
                $counts['eligible_for_master'] = max(0, $counts['eligible_for_master'] - 1);
            }
            $this->audit($batchId, $firm->id, $candidate->id, 'import', $decision['category'], $disposition, $result['reason'], null, $result, $actorId, false);
        } catch (Throwable $e) {
            $candidate->update([
                'disposition' => OcrForcedReviewCandidate::DISPOSITION_QUARANTINED,
                'block_reason' => 'import_error:'.$e->getMessage(),
            ]);
            $counts['errors']++;
            $counts['quarantined']++;
            $counts['eligible_for_master'] = max(0, $counts['eligible_for_master'] - 1);
            $this->audit($batchId, $firm->id, $candidate->id, 'import_error', $decision['category'], OcrForcedReviewCandidate::DISPOSITION_QUARANTINED, $e->getMessage(), null, null, $actorId, false);
        }
    }

    /**
     * Create new Master OR link to existing exact match — never fill/update existing Master fields.
     *
     * @return array{disposition: string, crm_ca_id: int|null, master_created: bool, reason: string}
     */
    private function importWithoutOverwrite(OcrParsedFirm $firm, ?int $actorId, bool $humanOverride = false): array
    {
        $firm->refresh();
        if ($firm->crm_ca_id) {
            return [
                'disposition' => OcrForcedReviewCandidate::DISPOSITION_SKIPPED,
                'crm_ca_id' => (int) $firm->crm_ca_id,
                'master_created' => false,
                'reason' => 'already_linked',
            ];
        }

        $document = OcrDocument::query()->findOrFail($firm->ocr_document_id);
        $decision = $this->decide($firm);
        if (! $decision['eligible_for_master']) {
            if (! $humanOverride) {
                return [
                    'disposition' => OcrForcedReviewCandidate::DISPOSITION_QUARANTINED,
                    'crm_ca_id' => null,
                    'master_created' => false,
                    'reason' => $decision['block_reason'] ?? 'not_eligible',
                ];
            }
            // Human Accept still blocks empty CA / address-as-CA / incomplete identity.
            if ($decision['ca_name'] === '' || in_array('address_as_ca', $decision['flags'], true)) {
                return [
                    'disposition' => OcrForcedReviewCandidate::DISPOSITION_QUARANTINED,
                    'crm_ca_id' => null,
                    'master_created' => false,
                    'reason' => $decision['block_reason'] ?? 'human_accept_blocked',
                ];
            }
            if ($decision['firm_name'] === '' || $decision['city'] === '') {
                return [
                    'disposition' => OcrForcedReviewCandidate::DISPOSITION_QUARANTINED,
                    'crm_ca_id' => null,
                    'master_created' => false,
                    'reason' => 'incomplete_firm_ca_city',
                ];
            }
        }

        $payload = $this->payloadFromFirm($firm, $decision['firm_name'], $decision['ca_name'], $decision['city']);
        $match = $this->matcher->match($payload);

        if ($match->isConflict()) {
            return [
                'disposition' => OcrForcedReviewCandidate::DISPOSITION_QUARANTINED,
                'crm_ca_id' => null,
                'master_created' => false,
                'reason' => 'master_match_conflict',
            ];
        }

        if ($match->isExact() && $match->caId) {
            // Exact Master already exists — never overwrite; quarantine as duplicate for manual link.
            return [
                'disposition' => OcrForcedReviewCandidate::DISPOSITION_QUARANTINED,
                'crm_ca_id' => (int) $match->caId,
                'master_created' => false,
                'reason' => 'duplicate_existing_master_no_overwrite',
            ];
        }

        $attrs = $this->mapping->toCaMasterAttributes([
            'firm_name' => $decision['firm_name'],
            'ca_name' => $decision['ca_name'],
            'city' => $decision['city'],
            'raw_firm_name' => $decision['firm_name'],
            'raw_ca_name' => $decision['ca_name'],
            'raw_city' => $decision['city'],
        ]);
        unset(
            $attrs['mobile_no'], $attrs['alternate_mobile_no'], $attrs['address'], $attrs['pincode'],
            $attrs['gst_no'], $attrs['pan_no'], $attrs['frn'], $attrs['membership_no'], $attrs['email_id'], $attrs['website']
        );

        $lead = DB::transaction(fn () => $this->mapping->createMaster($attrs));
        $this->linkFirmToMaster($firm, (int) $lead->ca_id, $document, $actorId, $humanOverride ? 'quarantine_human_accepted' : 'quarantine_created_master');

        return [
            'disposition' => OcrForcedReviewCandidate::DISPOSITION_IMPORTED,
            'crm_ca_id' => (int) $lead->ca_id,
            'master_created' => true,
            'reason' => 'created_new_master',
        ];
    }

    private function linkFirmToMaster(
        OcrParsedFirm $firm,
        int $caId,
        OcrDocument $document,
        ?int $actorId,
        string $reason,
    ): void {
        $firm->update([
            'crm_ca_id' => $caId,
            'matched_ca_id' => $caId,
            'match_status' => MasterCaDirectImportService::MATCH_IMPORTED,
            'review_status' => OcrParsedFirm::REVIEW_APPROVED,
            'match_reason' => $reason,
            'mapped_at' => now(),
        ]);
        // Keep document refresh lightweight; importer has its own completion logic.
        unset($document, $actorId);
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private function upsertCandidate(
        OcrParsedFirm $firm,
        array $decision,
        string $batchId,
        string $disposition,
        bool $dryRun,
    ): OcrForcedReviewCandidate {
        $source = is_array($firm->source_data) ? $firm->source_data : [];
        $raw = is_array($source['raw'] ?? null) ? $source['raw'] : [];
        $parsed = is_array($source['parsed'] ?? null) ? $source['parsed'] : [];
        $partners = $this->partnerNames($firm, $source, $parsed);
        $membership = $this->membershipNo($firm, $raw, $parsed);
        $frn = trim((string) ($firm->frn ?? ($parsed['frn'] ?? ($raw['frn'] ?? '')))) ?: null;

        $attrs = [
            'ocr_document_id' => $firm->ocr_document_id,
            'source_row_number' => $firm->row_number ?? $firm->sequence_no,
            'firm_name' => $decision['firm_name'],
            'ca_name' => $decision['ca_name'],
            'city' => $decision['city'],
            'address' => $firm->address,
            'membership_no' => $membership,
            'frn' => $frn,
            'partners' => $partners,
            'original_ocr_payload' => [
                'source_data' => $source,
                'field_meta' => $firm->field_meta,
                'validation_errors' => $firm->validation_errors,
                'match_status' => $firm->match_status,
                'match_reason' => $firm->match_reason,
                'members' => $firm->relationLoaded('members')
                    ? $firm->members->map(static fn ($m) => [
                        'ca_name' => $m->ca_name,
                        'raw_ca_name' => $m->raw_ca_name,
                        'membership_no' => $m->membership_no,
                        'role' => $m->role,
                        'is_primary' => $m->is_primary,
                    ])->all()
                    : [],
                'dry_run_snapshot' => $dryRun,
            ],
            'validation_problems' => $decision['problems'],
            'confidence_score' => $firm->overall_confidence,
            'category' => $decision['category'],
            'disposition' => $disposition,
            'block_reason' => $decision['block_reason'],
            'meta' => [
                'issue_codes' => $decision['classified']['issue_codes'] ?? [],
                'flags' => $decision['flags'],
                'eligible_for_master' => $decision['eligible_for_master'],
            ],
        ];

        return OcrForcedReviewCandidate::query()->updateOrCreate(
            [
                'batch_id' => $batchId,
                'ocr_parsed_firm_id' => $firm->id,
            ],
            $attrs,
        );
    }

    /**
     * Manual quarantine actions: Accept / Reject / Ignore (Correct is separate field edit).
     *
     * @return array<string, mixed>
     */
    public function setManualDisposition(
        int $candidateId,
        string $disposition,
        ?int $actorId = null,
        ?string $note = null,
    ): array {
        $allowed = [
            OcrForcedReviewCandidate::DISPOSITION_REJECTED,
            OcrForcedReviewCandidate::DISPOSITION_IGNORED,
            OcrForcedReviewCandidate::DISPOSITION_QUARANTINED,
        ];
        if (! in_array($disposition, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported manual disposition: '.$disposition);
        }
        $candidate = OcrForcedReviewCandidate::query()->findOrFail($candidateId);
        $before = $candidate->toArray();
        $candidate->update([
            'disposition' => $disposition,
            'meta' => array_merge(is_array($candidate->meta) ? $candidate->meta : [], [
                'manual_disposition_at' => now()->toIso8601String(),
                'manual_note' => $note,
            ]),
        ]);
        $this->audit(
            (string) $candidate->batch_id,
            (int) $candidate->ocr_parsed_firm_id,
            $candidate->id,
            'manual_'.$disposition,
            $candidate->category,
            $disposition,
            $note,
            $before,
            $candidate->fresh()?->toArray(),
            $actorId,
            false,
        );

        return ['candidate_id' => $candidate->id, 'disposition' => $disposition];
    }

    /**
     * Accept a quarantined/eligible candidate into Master after human review (create-only; no overwrite).
     *
     * @return array<string, mixed>
     */
    public function acceptCandidate(int $candidateId, ?int $actorId = null): array
    {
        $candidate = OcrForcedReviewCandidate::query()->findOrFail($candidateId);
        $firm = OcrParsedFirm::query()->with('members')->findOrFail($candidate->ocr_parsed_firm_id);
        if ($firm->crm_ca_id) {
            throw new \RuntimeException('OCR firm already linked to Master.');
        }
        $decision = $this->decide($firm);
        if (! $decision['eligible_for_master'] && $candidate->disposition !== OcrForcedReviewCandidate::DISPOSITION_ELIGIBLE) {
            // Allow human Accept only when three-field identity is present and not address-as-CA.
            if ($decision['ca_name'] === '' || in_array('address_as_ca', $decision['flags'], true)) {
                throw new \RuntimeException('Cannot Accept: '.$decision['block_reason']);
            }
            if ($decision['city'] === '' || $decision['firm_name'] === '') {
                throw new \RuntimeException('Cannot Accept: incomplete firm/CA/city.');
            }
            // Human override path still never overwrites existing Master.
            $decision['eligible_for_master'] = true;
            $decision['category'] = 'otherwise_valid';
            $decision['block_reason'] = 'human_accepted';
        }

        $result = $this->importWithoutOverwrite($firm, $actorId, humanOverride: true);
        $candidate->update([
            'disposition' => $result['disposition'],
            'crm_ca_id' => $result['crm_ca_id'],
            'master_created' => $result['master_created'],
            'master_overwritten' => false,
            'block_reason' => $result['reason'],
        ]);
        $this->audit(
            (string) $candidate->batch_id,
            $firm->id,
            $candidate->id,
            'manual_accept',
            $candidate->category,
            $result['disposition'],
            $result['reason'],
            null,
            $result,
            $actorId,
            false,
        );

        return $result;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $parsed
     * @return list<string>
     */
    private function partnerNames(OcrParsedFirm $firm, array $source, array $parsed): array
    {
        $partners = $parsed['partners'] ?? ($source['partners'] ?? []);
        if (is_array($partners) && $partners !== []) {
            return array_values(array_filter(array_map(
                static fn ($p) => trim((string) $p),
                $partners,
            ), static fn ($p) => $p !== ''));
        }
        if (! $firm->relationLoaded('members') || $firm->members->isEmpty()) {
            return [];
        }
        $out = [];
        foreach ($firm->members as $member) {
            if ($member->is_primary) {
                continue;
            }
            $name = trim((string) ($member->ca_name ?: $member->raw_ca_name ?: ''));
            if ($name !== '') {
                $out[] = $name;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $parsed
     */
    private function membershipNo(OcrParsedFirm $firm, array $raw, array $parsed): ?string
    {
        $fromPayload = trim((string) ($parsed['membership_no'] ?? ($raw['membership_no'] ?? '')));
        if ($fromPayload !== '') {
            return $fromPayload;
        }
        if (! $firm->relationLoaded('members')) {
            return null;
        }
        $primary = $firm->members->firstWhere('is_primary', true) ?? $firm->members->first();
        $mem = trim((string) ($primary?->membership_no ?? ''));

        return $mem !== '' ? $mem : null;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function audit(
        string $batchId,
        ?int $firmId,
        ?int $candidateId,
        string $action,
        ?string $category,
        ?string $disposition,
        ?string $message,
        ?array $before,
        ?array $after,
        ?int $actorId,
        bool $dryRun,
    ): void {
        if (! Schema::hasTable('ocr_quarantine_import_audits')) {
            return;
        }
        // Avoid flooding audit table on dry-run classify for every row — sample via action filter is fine;
        // user asked for audit logging, so write compact rows.
        OcrQuarantineImportAudit::query()->create([
            'batch_id' => $batchId,
            'ocr_parsed_firm_id' => $firmId,
            'candidate_id' => $candidateId,
            'action' => $action,
            'category' => $category,
            'disposition' => $disposition,
            'message' => $message !== null ? mb_substr($message, 0, 2000) : null,
            'before' => $before,
            'after' => $after,
            'actor_id' => $actorId,
            'dry_run' => $dryRun,
        ]);
    }

    /**
     * @param  array<string, string>|null  $backupPaths
     */
    private function resolveBatch(
        ?string $batchId,
        bool $resume,
        bool $dryRun,
        int $chunk,
        ?int $actorId,
        ?array $backupPaths,
    ): OcrQuarantineImportBatch {
        if ($resume) {
            if (! $batchId) {
                throw new \InvalidArgumentException('--resume requires --batch-id=');
            }
            $batch = OcrQuarantineImportBatch::query()->where('batch_id', $batchId)->firstOrFail();
            if ($backupPaths) {
                $batch->update(['backup_paths' => array_merge(is_array($batch->backup_paths) ? $batch->backup_paths : [], $backupPaths)]);
            }

            return $batch->fresh();
        }

        $id = $batchId ?: ('qbi_'.now()->format('Ymd_His').'_'.Str::lower(Str::random(6)));
        if (OcrQuarantineImportBatch::query()->where('batch_id', $id)->exists()) {
            throw new \RuntimeException("Batch id already exists: {$id}. Use --resume or a new --batch-id.");
        }

        return OcrQuarantineImportBatch::query()->create([
            'batch_id' => $id,
            'status' => $dryRun ? 'dry_run' : 'pending',
            'actor_id' => $actorId,
            'dry_run' => $dryRun,
            'chunk_size' => $chunk,
            'backup_paths' => $backupPaths,
            'summary' => [],
        ]);
    }

    /**
     * @param  list<string>  $issues
     * @param  list<string>  $problems
     */
    private function mapCategory(
        string $primary,
        array $issues,
        bool $addressAsCa,
        bool $firmConflict,
        bool $duplicate,
        string $caName,
        string $city,
        array $problems,
    ): string {
        if ($addressAsCa) {
            return 'address_used_as_ca';
        }
        if ($caName === '' || in_array('missing_ca_name', $issues, true) || $primary === 'missing_ca_name') {
            return 'missing_ca_name';
        }
        if ($city === '' || in_array('missing_city', $issues, true) || $primary === 'missing_city') {
            return 'missing_city';
        }
        if ($firmConflict) {
            return 'firm_ca_conflict';
        }
        if ($duplicate) {
            return 'duplicate';
        }
        if (in_array('invalid_person_name', $issues, true)
            || in_array('multiple_possible_ca_names', $issues, true)
            || in_array('low_confidence', $issues, true)
            || in_array('parser_changed_raw_value', $issues, true)) {
            return 'ambiguous';
        }
        if ($problems !== []) {
            return $this->fallbackCategoryFromProblems($problems);
        }

        return 'otherwise_valid';
    }

    /** @param  list<string>  $problems */
    private function fallbackCategoryFromProblems(array $problems): string
    {
        if (in_array('address_used_as_ca', $problems, true)) {
            return 'address_used_as_ca';
        }
        if (in_array('missing_ca_name', $problems, true)) {
            return 'missing_ca_name';
        }
        if (in_array('missing_city', $problems, true)) {
            return 'missing_city';
        }
        if (in_array('firm_ca_conflict', $problems, true)) {
            return 'firm_ca_conflict';
        }
        if (in_array('duplicate', $problems, true)) {
            return 'duplicate';
        }

        return 'ambiguous';
    }

    private function looksLikeHighConfidenceDuplicate(
        OcrParsedFirm $firm,
        string $firmName,
        string $caName,
        string $city,
    ): bool {
        if ($firmName === '' || $caName === '' || $city === '') {
            return false;
        }
        if ((float) ($firm->match_confidence ?? 0) >= 0.95
            && in_array((string) $firm->match_status, ['duplicate', 'matched'], true)) {
            return true;
        }
        $normFirm = mb_strtoupper(trim($firmName));
        $normCa = mb_strtoupper(trim($caName));
        $normCity = mb_strtoupper(trim($city));
        $q = CaMaster::query();
        if (Schema::hasColumn('ca_masters', 'normalized_firm_name')) {
            $q->whereRaw('UPPER(TRIM(COALESCE(normalized_firm_name, firm_name))) = ?', [$normFirm]);
        } else {
            $q->whereRaw('UPPER(TRIM(firm_name)) = ?', [$normFirm]);
        }
        if (Schema::hasColumn('ca_masters', 'normalized_ca_name')) {
            $q->whereRaw('UPPER(TRIM(COALESCE(normalized_ca_name, ca_name))) = ?', [$normCa]);
        } else {
            $q->whereRaw('UPPER(TRIM(ca_name)) = ?', [$normCa]);
        }
        if (Schema::hasColumn('ca_masters', 'city')) {
            $q->whereRaw('UPPER(TRIM(COALESCE(city, ""))) = ?', [$normCity]);
        }
        // High-confidence duplicate only when a single exact Master already exists.
        return $q->limit(2)->get()->count() === 1;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $parsed
     */
    private function partnersIncomplete(array $source, array $parsed): bool
    {
        $profile = $source['directory_profile'] ?? ($parsed['directory_profile'] ?? null);
        if ($profile !== 'partnership' && $profile !== 'PARTNERSHIP') {
            return false;
        }
        $partners = $parsed['partners'] ?? ($source['partners'] ?? []);
        if (! is_array($partners) || $partners === []) {
            return true;
        }
        foreach ($partners as $p) {
            if (trim((string) $p) === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFromFirm(OcrParsedFirm $firm, string $firmName, string $caName, string $city): array
    {
        return [
            'firm_name' => $firmName,
            'raw_firm_name' => $firm->raw_firm_name ?: $firmName,
            'normalized_firm_name' => $firm->normalized_firm_name ?: mb_strtoupper($firmName),
            'ca_name' => $caName,
            'raw_ca_name' => $caName,
            'normalized_ca_name' => mb_strtoupper($caName),
            'city' => $city,
            'raw_city' => $city,
            'field_meta' => $firm->field_meta,
            'overall_confidence' => $firm->overall_confidence,
            'raw' => is_array($firm->source_data['raw'] ?? null) ? $firm->source_data['raw'] : [],
            'parsed' => is_array($firm->source_data['parsed'] ?? null) ? $firm->source_data['parsed'] : [],
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function hasThreeFieldIdentity(array $payload): bool
    {
        $firm = trim((string) ($payload['normalized_firm_name'] ?? $payload['firm_name'] ?? ''));
        $ca = trim((string) ($payload['normalized_ca_name'] ?? $payload['ca_name'] ?? ''));
        $city = trim((string) ($payload['city'] ?? $payload['raw_city'] ?? ''));

        return $firm !== '' && mb_strlen($firm) >= 3 && $ca !== '' && $city !== '';
    }

    /** @return array<string, mixed> */
    private function emptyCounts(): array
    {
        return [
            'scanned' => 0,
            'eligible_for_master' => 0,
            'quarantined' => 0,
            'imported' => 0,
            'linked_existing' => 0,
            'errors' => 0,
            'by_category' => array_fill_keys(self::CATEGORIES, 0),
            'flags' => [
                'missing_ca' => 0,
                'missing_city' => 0,
                'address_as_ca' => 0,
                'conflicts' => 0,
                'duplicate' => 0,
            ],
        ];
    }
}
