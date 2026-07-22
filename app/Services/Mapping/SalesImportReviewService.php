<?php

namespace App\Services\Mapping;

use App\Models\CaAddress;
use App\Models\CaFirm;
use App\Models\CaMaster;
use App\Models\CaPartner;
use App\Models\MasterMappingDecision;
use App\Models\SalesImportRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Manual Needs Review actions for employee sales-list rows.
 * Does not create/update/delete CA Reference or CA Master records.
 */
class SalesImportReviewService
{
    public const SOURCE_TYPE = 'sales_import_row';

    public const ACTION_CONFIRM = 'manual_confirmed';

    public const ACTION_UNMATCHED = 'mark_unmatched';

    public const ACTION_IGNORE = 'ignore';

    public const ACTION_ACCEPT_MATCHED = 'accepted_matched';

    public const ACTION_ACCEPT_TOP = 'accepted_top_candidate';

    public function __construct(
        private readonly DataNormalizationService $normalizer,
        private readonly SalesImportMatchingService $matcher,
    ) {}

    /**
     * Ranked CA Reference (+ linked master) candidates for one import row.
     * Display enrichment only — does not change ranking/scoring algorithms.
     *
     * @return list<array<string, mixed>>
     */
    public function candidatesForRow(SalesImportRow $row, int $limit = 15): array
    {
        $candidates = $this->matcher->findReviewCandidates(
            $row->firm_name,
            $row->city_name,
            $row->ca_name,
            $limit
        );

        return array_values(array_map(
            fn (array $candidate) => $this->enrichCandidateForReview($candidate, $row),
            $candidates
        ));
    }

    /**
     * Paginated CA Reference search for the review modal.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>}
     */
    public function searchReference(?string $firm, ?string $ca, ?string $city, int $page = 1, int $perPage = 20): array
    {
        $result = $this->matcher->searchCaReference($firm, $ca, $city, $page, $perPage);
        $result['items'] = array_values(array_map(
            fn (array $candidate) => $this->enrichCandidateForReview($candidate, null),
            $result['items'] ?? []
        ));

        return $result;
    }

    /**
     * Attach CA Reference display fields for the review modal.
     * Read-only enrichment — does not alter match_score or ranking.
     *
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function enrichCandidateForReview(array $candidate, ?SalesImportRow $row): array
    {
        $referenceFirmId = isset($candidate['reference_firm_id']) ? (int) $candidate['reference_firm_id'] : null;
        $display = [
            'frn' => $candidate['frn'] ?? null,
            'membership_number' => $candidate['membership_number'] ?? null,
            'partner_count' => $candidate['partner_count'] ?? null,
            'address' => $candidate['address'] ?? null,
            'state' => $candidate['state'] ?? null,
            'pin' => $candidate['pin'] ?? ($candidate['pin_code'] ?? null),
            'mobile' => $candidate['mobile'] ?? null,
            'source_ocr_file' => $candidate['source_ocr_file'] ?? null,
            'confidence_percent' => $this->confidencePercent($candidate['match_score'] ?? null),
        ];

        if ($referenceFirmId && $this->caReferenceReadable()) {
            try {
                $firm = CaFirm::query()->where('id', $referenceFirmId)->first();
                if ($firm) {
                    $address = CaAddress::query()->where('firm_id', $referenceFirmId)->orderBy('id')->first();
                    $partner = CaPartner::query()->where('firm_id', $referenceFirmId)->orderBy('id')->first();
                    $partnerCount = Schema::connection('ca_reference')->hasColumn('ca_firms', 'partner_count') && $firm->partner_count !== null
                        ? (int) $firm->partner_count
                        : (int) CaPartner::query()->where('firm_id', $referenceFirmId)->count();

                    $addressText = trim(implode(', ', array_filter([
                        $firm->address ?? null,
                        $address?->address_line_1,
                        $address?->address_line_2,
                    ])));

                    $display['frn'] = $firm->frn ?? $display['frn'];
                    $display['partner_count'] = $partnerCount;
                    $display['address'] = $addressText !== '' ? $addressText : $display['address'];
                    $display['state'] = $firm->state ?? $address?->state ?? $display['state'];
                    $display['pin'] = $firm->pin_code ?? $address?->pin_code ?? $display['pin'];
                    $display['membership_number'] = $partner?->membership_number ?? $display['membership_number'];
                    $display['mobile'] = $partner?->mobile ?? $firm->phone ?? $display['mobile'];
                    $candidate['ca_name'] = $candidate['ca_name'] ?? $partner?->partner_name;
                    $candidate['firm_name'] = $candidate['firm_name'] ?? $firm->firm_name;
                    $candidate['city'] = $candidate['city'] ?? ($address?->city ?? $firm->city);
                }
            } catch (\Throwable) {
                // Keep base candidate fields when reference DB is unavailable.
            }
        }

        if (! empty($candidate['ca_id']) && empty($display['membership_number'])) {
            $master = CaMaster::query()->where('ca_id', (int) $candidate['ca_id'])->first([
                'ca_id', 'ca_name', 'firm_name', 'membership_no', 'mobile_no', 'address',
            ]);
            if ($master) {
                $display['membership_number'] = $master->membership_no ?? $display['membership_number'];
                $display['mobile'] = $display['mobile'] ?? $master->mobile_no;
                $display['address'] = $display['address'] ?? $master->address;
                $candidate['ca_name'] = $candidate['ca_name'] ?? $master->ca_name;
                $candidate['firm_name'] = $candidate['firm_name'] ?? $master->firm_name;
            }
        }

        $comparison = $this->buildFieldComparison($row, $candidate, $display);
        $candidate['display'] = $display;
        $candidate['confidence_percent'] = $display['confidence_percent'];
        $candidate['frn'] = $display['frn'];
        $candidate['membership_number'] = $display['membership_number'];
        $candidate['partner_count'] = $display['partner_count'];
        $candidate['address'] = $display['address'];
        $candidate['state'] = $display['state'];
        $candidate['pin'] = $display['pin'];
        $candidate['mobile'] = $display['mobile'];
        $candidate['source_ocr_file'] = $display['source_ocr_file'];
        $candidate['matched_fields'] = $comparison['matched'];
        $candidate['comparison_different_fields'] = $comparison['different'];
        $candidate['comparison_labels'] = $comparison['labels'];

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $display
     * @return array{matched: list<string>, different: list<string>, labels: list<array{key: string, label: string, status: string}>}
     */
    private function buildFieldComparison(?SalesImportRow $row, array $candidate, array $display): array
    {
        $exact = array_values(array_unique($candidate['exact_fields'] ?? []));
        $diff = array_values(array_unique($candidate['different_fields'] ?? []));
        $labels = [];

        $pairs = [
            'ca_name' => ['label' => 'CA Name', 'sales' => $row?->ca_name, 'ref' => $candidate['ca_name'] ?? null],
            'firm_name' => ['label' => 'Firm Name', 'sales' => $row?->firm_name, 'ref' => $candidate['firm_name'] ?? null],
            'city' => ['label' => 'City', 'sales' => $row?->city_name, 'ref' => $candidate['city'] ?? null],
            'mobile' => ['label' => 'Mobile Number', 'sales' => $row?->mobile_no, 'ref' => $display['mobile'] ?? null],
        ];

        foreach ($pairs as $key => $pair) {
            $salesNorm = $this->compareToken($key, $pair['sales']);
            $refNorm = $this->compareToken($key, $pair['ref']);
            $status = 'missing';
            if ($salesNorm !== null && $refNorm !== null) {
                if ($salesNorm === $refNorm) {
                    $status = 'matched';
                    if (! in_array($key, $exact, true)) {
                        $exact[] = $key;
                    }
                    $diff = array_values(array_filter($diff, static fn ($item) => $item !== $key));
                } else {
                    $status = ($key === 'firm_name') ? 'formatting' : 'different';
                    if (! in_array($key, $diff, true) && ! in_array($key, $exact, true)) {
                        $diff[] = $key;
                    }
                }
            } elseif ($salesNorm !== null || $refNorm !== null) {
                $status = 'different';
                if (! in_array($key, $diff, true) && ! in_array($key, $exact, true)) {
                    $diff[] = $key;
                }
            }
            $labels[] = ['key' => $key, 'label' => $pair['label'], 'status' => $status];
        }

        return [
            'matched' => array_values(array_unique($exact)),
            'different' => array_values(array_unique($diff)),
            'labels' => $labels,
        ];
    }

    private function compareToken(string $key, mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        if ($key === 'mobile') {
            $digits = preg_replace('/\D+/', '', $raw) ?: '';

            return $digits !== '' ? substr($digits, -10) : null;
        }
        if ($key === 'firm_name' || $key === 'ca_name') {
            return $this->normalizer->salesFirmName($raw);
        }
        if ($key === 'city') {
            return $this->normalizer->salesCityName($raw);
        }

        return mb_strtoupper($raw);
    }

    private function confidencePercent(mixed $score): ?int
    {
        if ($score === null || $score === '') {
            return null;
        }
        $value = (float) $score;
        if ($value <= 1.0) {
            return (int) round($value * 100);
        }

        return (int) max(0, min(100, round($value)));
    }

    private function caReferenceReadable(): bool
    {
        try {
            return Schema::connection('ca_reference')->hasTable('ca_firms');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Accept the top ranked Needs Review candidate using existing confirmMatch logic.
     */
    public function acceptBestCandidate(SalesImportRow $row, User $actor, ?string $reason = null): SalesImportRow
    {
        $this->assertCanDecide($actor);

        if ($row->mapping_status !== 'needs_review') {
            throw ValidationException::withMessages([
                'mapping_status' => ['Only Needs Review rows can be accepted from the list action.'],
            ]);
        }

        $candidates = $this->candidatesForRow($row, 5);
        $best = $candidates[0] ?? null;
        $caId = isset($best['ca_id']) ? (int) $best['ca_id'] : null;
        $referenceFirmId = isset($best['reference_firm_id']) ? (int) $best['reference_firm_id'] : null;

        if ((! $caId || $caId <= 0) && (! $referenceFirmId || $referenceFirmId <= 0)) {
            throw ValidationException::withMessages([
                'matched_ca_id' => ['No acceptable CA Reference candidate is available for this row.'],
            ]);
        }

        return $this->confirmMatch($row, $actor, [
            'matched_ca_id' => $caId ?: null,
            'matched_reference_firm_id' => $referenceFirmId ?: null,
            'reason' => trim((string) ($reason ?: 'Accepted top candidate from Needs Review')),
        ]);
    }

    /**
     * Bulk-accept rows that Auto Match already marked as matched.
     * Does not create/update CA records. Only mapping fields + audit.
     *
     * @return array{accepted: int, skipped: int, employee: string|null, import_batch_id: int|null, source_file_name: string|null}
     */
    public function acceptAllMatched(
        User $actor,
        ?string $employee = null,
        ?int $importBatchId = null,
        ?string $sourceFileName = null,
    ): array {
        $this->assertCanDecide($actor);
        $employee = trim((string) ($employee ?? ''));
        $sourceFileName = trim((string) ($sourceFileName ?? ''));

        return DB::transaction(function () use ($actor, $employee, $importBatchId, $sourceFileName) {
            $query = SalesImportRow::query()
                ->where('mapping_status', 'matched')
                ->whereNotNull('matched_ca_id')
                ->when($employee !== '', fn ($builder) => $builder->where('employee_name', $employee))
                ->when($importBatchId !== null, fn ($builder) => $builder->where('import_batch_id', $importBatchId))
                ->when($sourceFileName !== '', fn ($builder) => $builder->where('source_file_name', $sourceFileName))
                ->orderBy('id');

            $accepted = 0;
            $skipped = 0;

            $query->chunkById(100, function ($rows) use ($actor, &$accepted, &$skipped) {
                foreach ($rows as $row) {
                    if (in_array((string) $row->matched_on, [self::ACTION_CONFIRM, self::ACTION_ACCEPT_MATCHED, self::ACTION_ACCEPT_TOP], true)) {
                        $skipped++;
                        continue;
                    }

                    $fresh = SalesImportRow::query()->lockForUpdate()->find($row->id);
                    if (! $fresh || $fresh->mapping_status !== 'matched' || ! $fresh->matched_ca_id) {
                        $skipped++;
                        continue;
                    }

                    $before = $this->snapshot($fresh);
                    $fresh->fill([
                        'mapping_status' => 'matched',
                        'matched_on' => self::ACTION_ACCEPT_MATCHED,
                        'review_reason' => 'Accepted auto-matched mapping',
                        'mapped_at' => now(),
                    ]);
                    $fresh->save();
                    $this->audit(
                        $actor,
                        $fresh,
                        self::ACTION_ACCEPT_MATCHED,
                        $before,
                        $this->snapshot($fresh),
                        'Accepted auto-matched mapping'
                    );
                    $accepted++;
                }
            });

            return [
                'accepted' => $accepted,
                'skipped' => $skipped,
                'employee' => $employee !== '' ? $employee : null,
                'import_batch_id' => $importBatchId,
                'source_file_name' => $sourceFileName !== '' ? $sourceFileName : null,
            ];
        });
    }

    /**
     * @param  array{matched_ca_id?: int|null, matched_reference_firm_id?: int|null, reason?: string|null}  $payload
     */
    public function confirmMatch(SalesImportRow $row, User $actor, array $payload): SalesImportRow
    {
        $this->assertCanDecide($actor);

        $caId = isset($payload['matched_ca_id']) ? (int) $payload['matched_ca_id'] : null;
        $referenceFirmId = isset($payload['matched_reference_firm_id'])
            ? (int) $payload['matched_reference_firm_id']
            : null;
        $reason = trim((string) ($payload['reason'] ?? 'Confirmed after comparing firm and city'));

        if ((! $caId || $caId <= 0) && (! $referenceFirmId || $referenceFirmId <= 0)) {
            throw ValidationException::withMessages([
                'matched_ca_id' => ['Select a valid CA master or CA Reference candidate.'],
            ]);
        }

        if ($caId) {
            $master = CaMaster::query()->where('ca_id', $caId)->first();
            if (! $master) {
                throw ValidationException::withMessages([
                    'matched_ca_id' => ['The selected CA does not exist or is unavailable.'],
                ]);
            }
        }

        if ($referenceFirmId) {
            if (! $this->referenceFirmExists($referenceFirmId)) {
                throw ValidationException::withMessages([
                    'matched_reference_firm_id' => ['The selected CA Reference firm does not exist.'],
                ]);
            }
        }

        return DB::transaction(function () use ($row, $actor, $caId, $referenceFirmId, $reason) {
            $fresh = SalesImportRow::query()->lockForUpdate()->findOrFail($row->id);
            $before = $this->snapshot($fresh);

            $updates = [
                'matched_ca_id' => $caId ?: null,
                'mapping_status' => 'matched',
                'matched_on' => self::ACTION_CONFIRM,
                'match_score' => 1.0,
                'review_reason' => $reason !== '' ? $reason : 'Confirmed after comparing firm and city',
                'mapped_at' => now(),
            ];
            if (Schema::hasColumn('sales_import_rows', 'matched_reference_firm_id')) {
                $updates['matched_reference_firm_id'] = $referenceFirmId ?: $fresh->matched_reference_firm_id;
            }

            $fresh->fill($updates);
            $fresh->save();

            $this->audit($actor, $fresh, self::ACTION_CONFIRM, $before, $this->snapshot($fresh), $reason);

            return $fresh->fresh(['ca.city']);
        });
    }

    /**
     * @param  array{reason?: string|null}  $payload
     */
    public function markUnmatched(SalesImportRow $row, User $actor, array $payload): SalesImportRow
    {
        $this->assertCanDecide($actor);
        $reason = trim((string) ($payload['reason'] ?? 'No correct CA found in reference data'));

        return DB::transaction(function () use ($row, $actor, $reason) {
            $fresh = SalesImportRow::query()->lockForUpdate()->findOrFail($row->id);
            $before = $this->snapshot($fresh);

            $updates = [
                'matched_ca_id' => null,
                'mapping_status' => 'unmatched',
                'matched_on' => null,
                'match_score' => null,
                'review_reason' => $reason !== '' ? $reason : 'No correct CA found in reference data',
                'mapped_at' => null,
            ];
            if (Schema::hasColumn('sales_import_rows', 'matched_reference_firm_id')) {
                $updates['matched_reference_firm_id'] = null;
            }

            $fresh->fill($updates);
            $fresh->save();

            $this->audit($actor, $fresh, self::ACTION_UNMATCHED, $before, $this->snapshot($fresh), $reason);

            return $fresh->fresh(['ca.city']);
        });
    }

    /**
     * @param  array{reason?: string|null}  $payload
     */
    public function ignore(SalesImportRow $row, User $actor, array $payload): SalesImportRow
    {
        $this->assertCanDecide($actor);
        $reason = trim((string) ($payload['reason'] ?? 'Ignored during manual review'));

        return DB::transaction(function () use ($row, $actor, $reason) {
            $fresh = SalesImportRow::query()->lockForUpdate()->findOrFail($row->id);
            $before = $this->snapshot($fresh);

            $updates = [
                'matched_ca_id' => null,
                'mapping_status' => 'ignored',
                'matched_on' => null,
                'match_score' => null,
                'review_reason' => $reason !== '' ? $reason : 'Ignored during manual review',
                'mapped_at' => null,
            ];
            if (Schema::hasColumn('sales_import_rows', 'matched_reference_firm_id')) {
                $updates['matched_reference_firm_id'] = null;
            }

            $fresh->fill($updates);
            $fresh->save();

            $this->audit($actor, $fresh, self::ACTION_IGNORE, $before, $this->snapshot($fresh), $reason);

            return $fresh->fresh(['ca.city']);
        });
    }

    private function assertCanDecide(User $actor): void
    {
        $rbac = app(\App\Services\Rbac\RbacService::class);
        if (! $rbac->can($actor, 'ca_master', 'edit')) {
            throw new AccessDeniedHttpException('You do not have permission to confirm employee import mappings.');
        }
    }

    private function referenceFirmExists(int $firmId): bool
    {
        try {
            return Schema::connection('ca_reference')->hasTable('ca_firms')
                && CaFirm::query()->where('id', $firmId)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(SalesImportRow $row): array
    {
        return [
            'mapping_status' => $row->mapping_status,
            'matched_ca_id' => $row->matched_ca_id,
            'matched_reference_firm_id' => $row->matched_reference_firm_id ?? null,
            'matched_on' => $row->matched_on,
            'match_score' => $row->match_score,
            'review_reason' => $row->review_reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(
        User $actor,
        SalesImportRow $row,
        string $action,
        array $before,
        array $after,
        string $reason,
    ): void {
        if (! Schema::hasTable('master_mapping_decisions')) {
            return;
        }

        $decision = match ($action) {
            self::ACTION_CONFIRM, self::ACTION_ACCEPT_TOP, self::ACTION_ACCEPT_MATCHED => 'manual_confirm',
            self::ACTION_UNMATCHED => MasterMappingDecision::DECISION_REJECTED,
            self::ACTION_IGNORE => MasterMappingDecision::DECISION_SKIPPED,
            default => MasterMappingDecision::DECISION_NEEDS_REVIEW,
        };

        $data = [
            'source_type' => self::SOURCE_TYPE,
            'source_ref' => (string) $row->id,
            'decision' => $decision,
            'matched_ca_id' => $after['matched_ca_id'] ?? null,
            'confidence' => $after['match_score'] ?? null,
            'matched_on' => $after['matched_on'] ?? $action,
            'candidates' => $row->match_candidates ?? [],
            'payload_snapshot' => [
                'employee_name' => $row->employee_name,
                'firm_name' => $row->firm_name,
                'city_name' => $row->city_name,
                'ca_name' => $row->ca_name,
                'mobile_no' => $row->mobile_no,
                'call_date' => $row->call_date?->format('Y-m-d'),
                'source_file_name' => $row->source_file_name,
            ],
            'old_values' => $before,
            'new_values' => $after,
            'actor_id' => $actor->id,
            'remarks' => $reason,
            'applied_at' => now(),
        ];

        if (Schema::hasColumn('master_mapping_decisions', 'decision_meta')) {
            $data['decision_meta'] = [
                'action' => $action,
                'sales_import_row_id' => $row->id,
            ];
        }

        MasterMappingDecision::query()->create($data);
    }
}
