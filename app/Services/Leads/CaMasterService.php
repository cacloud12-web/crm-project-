<?php

namespace App\Services\Leads;

use App\Events\LeadSaved;
use App\Exceptions\DuplicateLeadException;
use App\Models\CaMaster;
use App\Models\LeadPhoneNumber;
use App\Services\Activity\ActivityLogService;
use App\Services\Assignment\AssignmentRecorder;
use App\Services\Cache\CrmCacheService;
use App\Services\Concerns\SearchesListings;
use App\Services\Master\LookupResolverService;
use App\Services\Leads\LeadLockService;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Services\Rbac\EmployeeLeadFieldGuard;
use Illuminate\Auth\Access\AuthorizationException;
use App\Support\Database\SqlAggregate;
use App\Support\Listing\ListingQueryApplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class CaMasterService
{
    use SearchesListings;

    public function __construct(
        private readonly LookupResolverService $lookupResolver,
        private readonly ActivityLogService $activityLogService,
        private readonly CrmCacheService $cacheService,
        private readonly EmployeeDataScopeService $employeeDataScope,
        private readonly EmployeeLeadFieldGuard $employeeLeadFieldGuard,
        private readonly LeadLockService $leadLockService,
        private readonly AssignmentRecorder $assignmentRecorder,
        private readonly DuplicateLeadDetectionService $duplicateLeadDetection,
        private readonly PhoneNormalizationService $phoneNormalization,
        private readonly IndianMobileValidationService $mobileValidation,
        private readonly PhoneClassificationService $phoneClassification,
        private readonly DuplicateAttemptService $duplicateAttemptService,
        private readonly LeadOwnershipService $leadOwnership,
        private readonly LeadQualityHistoryService $leadQualityHistory,
    ) {}

    public function search(array $params = []): array
    {
        return $this->searchListing(
            CaMaster::query()->with($this->listingRelations()),
            $params,
            'ca_masters',
        );
    }

    public function list(): Collection
    {
        return $this->listAllFromSearch(
            CaMaster::query()->with($this->listingRelations()),
            [],
            'ca_masters',
        );
    }

    /**
     * @return array{all: int, new: int, hot: int, pipeline: int, lost: int, pipeline_stages: array<string, int>}
     */
    public function segmentCounts(string $pipeline = ''): array
    {
        $counts = $this->leadSegmentCounts();

        return array_merge($counts, [
            'pipeline_stages' => $pipeline === 'master'
                ? $this->masterPipelineStageCounts()
                : $this->pipelineStageCounts(),
        ]);
    }

    /**
     * @return array{all: int, new: int, hot: int, pipeline: int, lost: int}
     */
    public function leadSegmentCounts(): array
    {
        $scopeKey = $this->employeeDataScope->cacheScopeKey();

        return $this->cacheService->rememberLeadSegmentCounts($scopeKey, function () {
            $employeeId = $this->employeeDataScope->scopedEmployeeId(auth()->user());
            $query = CaMaster::query()->countableInStatistics();
            $this->employeeDataScope->scopeCaMasterQuery($query, $employeeId);

            $pipelineStatuses = \App\Support\CrmPipeline::pipelineSegmentStatuses();
            $lostStatuses = ['Lost', 'Inactive', 'Not Interested'];
            $pipelineList = "'".implode("','", $pipelineStatuses)."'";
            $lostList = "'".implode("','", $lostStatuses)."'";

            $row = $query
                ->selectRaw('COUNT(*) as total')
                ->selectRaw(SqlAggregate::countFilter('*', 'is_newly_established = true').' as new_leads')
                ->selectRaw(SqlAggregate::countFilter('*', "status = 'Hot'").' as hot_leads')
                ->selectRaw(SqlAggregate::countFilter('*', "status IN ({$pipelineList})").' as pipeline_leads')
                ->selectRaw(SqlAggregate::countFilter('*', "status IN ({$lostList})").' as lost_leads')
                ->first();

            return [
                'all' => (int) ($row->total ?? 0),
                'new' => (int) ($row->new_leads ?? 0),
                'hot' => (int) ($row->hot_leads ?? 0),
                'pipeline' => (int) ($row->pipeline_leads ?? 0),
                'lost' => (int) ($row->lost_leads ?? 0),
            ];
        });
    }

    /**
     * @return array<string, int>
     */
    public function masterPipelineStageCounts(): array
    {
        $scopeKey = $this->employeeDataScope->cacheScopeKey();

        return $this->cacheService->rememberPipelineStageCounts($scopeKey.':master', function () {
            $employeeId = $this->employeeDataScope->scopedEmployeeId(auth()->user());
            $query = CaMaster::query()->countableInStatistics();
            $this->employeeDataScope->scopeCaMasterQuery($query, $employeeId);

            $statusCounts = $query
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            return $this->aggregateStageCounts(
                $statusCounts,
                config('crm_master_pipeline.stage_statuses', \App\Support\CrmPipeline::masterStageStatuses()),
            );
        });
    }

    /**
     * @return array<string, int>
     */
    public function pipelineStageCounts(): array
    {
        $scopeKey = $this->employeeDataScope->cacheScopeKey();

        return $this->cacheService->rememberPipelineStageCounts($scopeKey, function () {
            $employeeId = $this->employeeDataScope->scopedEmployeeId(auth()->user());
            $query = CaMaster::query()->countableInStatistics();
            $this->employeeDataScope->scopeCaMasterQuery($query, $employeeId);

            $statusCounts = $query
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            return $this->aggregateStageCounts(
                $statusCounts,
                \App\Support\CrmPipeline::salesStageStatuses(),
            );
        });
    }

    public function kanbanBoard(array $params = []): array
    {
        $perStage = min(max((int) ($params['per_stage'] ?? 80), 1), 200);
        $config = ListingQueryApplier::config('ca_masters');
        $params = $this->employeeDataScope->stripScopedParams($params, $config);
        $employeeId = $this->employeeDataScope->scopedEmployeeId(auth()->user());
        $stageMap = $this->resolveKanbanStageMap($params);

        $baseQuery = CaMaster::query();
        $this->employeeDataScope->scopeCaMasterQuery($baseQuery, $employeeId);
        ListingQueryApplier::applyListingFilters($baseQuery, $params, $config);

        $statusCounts = (clone $baseQuery)
            ->countableInStatistics()
            ->selectRaw('status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $stageCounts = $this->aggregateStageCounts($statusCounts, $stageMap);

        $items = collect();
        foreach ($stageMap as $stage => $statuses) {
            $stageQuery = (clone $baseQuery)
                ->with($this->listingRelations())
                ->whereIn('status', $statuses);
            ListingQueryApplier::applyColumnProjection($stageQuery, $config);

            $items = $items->merge(
                $stageQuery
                    ->orderByDesc($stageQuery->getModel()->getTable().'.updated_at')
                    ->limit($perStage)
                    ->get(),
            );
        }

        return [
            'stage_counts' => $stageCounts,
            'items' => $items->unique('ca_id')->values(),
            'per_stage' => $perStage,
            'pipeline' => ($params['pipeline'] ?? '') === 'master' ? 'master' : 'sales',
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<string, int|string>|array<string, int|string>  $statusCounts
     * @param  array<string, list<string>>  $stageMap
     * @return array<string, int>
     */
    private function aggregateStageCounts($statusCounts, array $stageMap): array
    {
        $stageCounts = [];
        foreach ($stageMap as $stage => $statuses) {
            $stageCounts[$stage] = collect($statuses)
                ->sum(fn (string $status) => (int) ($statusCounts[$status] ?? 0));
        }

        return $stageCounts;
    }

    /**
     * @return array<string, list<string>>
     */
    private function resolveKanbanStageMap(array $params): array
    {
        if (($params['pipeline'] ?? '') === 'master') {
            return config('crm_master_pipeline.stage_statuses', \App\Support\CrmPipeline::masterStageStatuses());
        }

        return \App\Support\CrmPipeline::salesStageStatuses();
    }

    /**
     * @return list<string>
     */
    private function listingRelations(): array
    {
        return [
            'city:city_id,city_name',
            'state:state_id,state_name',
            'sourceLead:source_id,source_name',
            'createdByEmployee:employee_id,name',
            'activeAssignment.employee:employee_id,name',
            'activeTeamAssignments.employee:employee_id,name,role,status',
        ];
    }

    public function find(int|string $id): CaMaster
    {
        app(EmployeeDataScopeService::class)->ensureCanAccessCaMaster($id);

        return CaMaster::query()
            ->with(['city', 'state', 'sourceLead', 'lockedByEmployee'])
            ->findOrFail($id);
    }

    public function create(array $data): CaMaster
    {
        $user = auth()->user();
        $this->assertValidMobileFields($data);
        $data = $this->duplicateLeadDetection->applyNormalizedFields($data);
        $this->duplicateLeadDetection->assertNoDuplicatesForSave($data, null, $user);

        $executiveId = $this->extractExecutiveId($data);
        $payload = $this->normalize($data);
        $payload['created_by_employee_id'] = ! empty($data['created_by_employee_id'])
            ? (int) $data['created_by_employee_id']
            : $this->employeeDataScope->resolveEmployeeId($user);

        $lead = DB::transaction(function () use ($payload, $data) {
            try {
                $lead = CaMaster::create($payload);
                $this->duplicateLeadDetection->syncLeadPhones($lead);

                return $lead;
            } catch (QueryException $exception) {
                if ($this->isDuplicatePhoneRegistryViolation($exception)) {
                    throw $this->duplicatePhoneExceptionFromPayload($data);
                }

                throw $exception;
            }
        });

        if ($executiveId) {
            $this->assignExecutive((int) $lead->ca_id, $executiveId);
            $lead = $lead->fresh(['city', 'state', 'sourceLead']);
        } elseif ($user && $this->employeeDataScope->shouldScopeToEmployee($user)) {
            $creatorEmployeeId = $this->employeeDataScope->resolveEmployeeId($user);
            if ($creatorEmployeeId) {
                $this->assignExecutive((int) $lead->ca_id, $creatorEmployeeId);
                $lead = $lead->fresh(['city', 'state', 'sourceLead']);
            }
        }

        event(new LeadSaved($lead, true, $user));

        $this->activityLogService->log(
            'CA_MASTER',
            'Add Lead',
            $this->shortId((string) $lead->ca_id),
            $lead->firm_name ?: $lead->ca_name,
            afterValue: $this->auditSnapshot($lead),
        );

        $this->invalidateDashboardCache();

        $this->duplicateAttemptService->resolveOnLeadSave(
            $this->employeeDataScope->resolveEmployeeId($user),
            (int) $lead->ca_id,
            $data,
        );

        return $lead;
    }

    public function update(CaMaster $caMaster, array $data): CaMaster
    {
        $user = auth()->user();
        if ($user) {
            $this->leadOwnership->assertCanEdit($user, $caMaster);
            $this->leadLockService->assertCanMutate($caMaster, $user);
            $data = $this->employeeLeadFieldGuard->filterUpdateData(
                $user,
                $caMaster,
                $data,
                $this->employeeLeadFieldGuard->resolveActiveExecutiveId((int) $caMaster->ca_id),
            );
        }

        $this->assertValidMobileFields($data);
        $data = $this->duplicateLeadDetection->applyNormalizedFields($data, $caMaster);

        $before = $this->auditSnapshot($caMaster);
        $this->duplicateLeadDetection->assertNoDuplicatesForSave($data, $caMaster, $user);
        $executiveId = $this->extractExecutiveId($data);

        $lead = DB::transaction(function () use ($caMaster, $data) {
            $caMaster->update($this->normalize($data, $caMaster));
            $lead = $caMaster->fresh(['city', 'state', 'sourceLead', 'lockedByEmployee']);
            $this->duplicateLeadDetection->syncLeadPhones($lead);

            return $lead;
        });

        if ($executiveId) {
            $this->assignExecutive((int) $lead->ca_id, $executiveId);
            $lead = $lead->fresh(['city', 'state', 'sourceLead', 'lockedByEmployee']);
        }

        if ($user) {
            $this->leadLockService->release($lead, $user);
            $lead = $lead->fresh(['city', 'state', 'sourceLead', 'lockedByEmployee']);
        }

        $this->handleQualityStatusSideEffects($lead, $data, $user);

        event(new LeadSaved($lead, false, $user));

        $this->activityLogService->log(
            'CA_MASTER',
            'Update Lead',
            $this->shortId((string) $lead->ca_id),
            $lead->firm_name ?: $lead->ca_name,
            beforeValue: $before,
            afterValue: $this->auditSnapshot($lead),
        );

        $this->invalidateDashboardCache();

        $this->duplicateAttemptService->resolveOnLeadSave(
            $this->employeeDataScope->resolveEmployeeId($user),
            (int) $lead->ca_id,
            $data,
        );

        return $lead;
    }

    public function updateStatus(CaMaster $caMaster, string $status): CaMaster
    {
        $user = auth()->user();
        if ($user) {
            $this->leadOwnership->assertCanEdit($user, $caMaster);
            $this->leadLockService->assertCanMutate($caMaster, $user);
            $this->employeeLeadFieldGuard->assertCanChangeStatus($user, $caMaster, $status);
        }

        $before = $this->auditSnapshot($caMaster);
        $caMaster->update(['status' => $status]);
        $lead = $caMaster->fresh(['city', 'state', 'sourceLead']);

        if (in_array($status, config('crm_duplicates.wrong_number_statuses', []), true)) {
            $this->leadQualityHistory->markWrongNumber($lead, 'Status changed to '.$status, $user);
            $lead = $lead->fresh(['city', 'state', 'sourceLead']);
        }

        $this->activityLogService->log(
            'CA_MASTER',
            'Update Lead Status',
            $this->shortId((string) $lead->ca_id),
            ($lead->firm_name ?: $lead->ca_name).' — '.$before['status'].' → '.$status,
            beforeValue: $before['status'] ?? null,
            afterValue: $status,
        );

        $this->invalidateDashboardCache();

        return $lead;
    }

    public function updateContact(CaMaster $caMaster, array $data): CaMaster
    {
        $user = auth()->user();
        if ($user) {
            $this->leadOwnership->assertCanEdit($user, $caMaster);
            $this->leadLockService->assertCanMutate($caMaster, $user);
            $data = $this->employeeLeadFieldGuard->filterContactUpdateData($user, $caMaster, $data);
        }

        $this->assertValidMobileFields($data);
        $data = $this->duplicateLeadDetection->applyNormalizedFields($data, $caMaster);

        $before = $this->contactSnapshot($caMaster);
        $this->duplicateLeadDetection->assertNoDuplicatesForSave($data, $caMaster, $user);

        $lead = DB::transaction(function () use ($caMaster, $data) {
            $payload = [];
            if (array_key_exists('mobile_no', $data)) {
                $payload['mobile_no'] = $this->normalizeStoredMobile($data['mobile_no']);
                $payload['normalized_mobile'] = $this->phoneNormalization->normalize($data['mobile_no']);
            }
            if (array_key_exists('alternate_mobile_no', $data)) {
                $payload['alternate_mobile_no'] = $this->normalizeStoredMobile($data['alternate_mobile_no']);
                $payload['normalized_alternate_mobile'] = $this->phoneNormalization->normalize($data['alternate_mobile_no']);
            }
            if (array_key_exists('email_id', $data)) {
                $payload['email_id'] = $data['email_id'];
                $payload['normalized_email'] = app(LeadFieldNormalizationService::class)->normalizeEmail($data['email_id']);
            }
            if (array_key_exists('website', $data)) {
                $payload['website'] = $data['website'];
                $payload['normalized_website'] = $data['normalized_website'] ?? null;
            }

            $caMaster->update($payload);
            $lead = $caMaster->fresh(['city', 'state', 'sourceLead', 'lockedByEmployee']);
            $this->duplicateLeadDetection->syncLeadPhones($lead);

            return $lead;
        });

        if ($user) {
            $this->leadLockService->release($lead, $user);
            $lead = $lead->fresh(['city', 'state', 'sourceLead', 'lockedByEmployee']);
        }

        $this->activityLogService->log(
            'CA_MASTER',
            'Update Lead Contact',
            $this->shortId((string) $lead->ca_id),
            $lead->firm_name ?: $lead->ca_name,
            beforeValue: $before,
            afterValue: $this->contactSnapshot($lead),
        );

        $this->invalidateDashboardCache();

        return $lead;
    }

    public function delete(CaMaster $caMaster): void
    {
        $user = auth()->user();
        if ($user) {
            if ($this->employeeDataScope->shouldScopeToEmployee($user)) {
                throw new AuthorizationException('You do not have permission to delete leads.');
            }

            $this->leadLockService->assertCanMutate($caMaster, $user);
        }

        $before = $this->auditSnapshot($caMaster);

        $this->activityLogService->log(
            'CA_MASTER',
            'Delete Lead',
            $this->shortId((string) $caMaster->ca_id),
            $caMaster->firm_name ?: $caMaster->ca_name,
            beforeValue: $before,
        );

        LeadPhoneNumber::query()->where('ca_id', $caMaster->ca_id)->delete();
        $caMaster->delete();
        $this->invalidateDashboardCache();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTrashed(int $limit = 100): array
    {
        $this->assertCanViewRecycleBin();

        $query = CaMaster::onlyTrashed()
            ->with(['city:city_id,city_name', 'state:state_id,state_name']);

        $user = auth()->user();
        if ($user && $this->employeeDataScope->shouldScopeToEmployee($user)) {
            $this->employeeDataScope->scopeCaMasterQuery(
                $query,
                $this->employeeDataScope->scopedEmployeeId($user),
            );
        }

        return $query
            ->orderByDesc('deleted_at')
            ->limit($limit)
            ->get()
            ->map(fn (CaMaster $lead) => [
                'ca_id' => $lead->ca_id,
                'firm_name' => $lead->firm_name,
                'ca_name' => $lead->ca_name,
                'mobile_no' => $lead->mobile_no,
                'email_id' => $lead->email_id,
                'status' => $lead->status,
                'city' => $lead->city?->city_name,
                'state' => $lead->state?->state_name,
                'deleted_at' => $lead->deleted_at?->toIso8601String(),
            ])
            ->all();
    }

    public function restore(int $caId): CaMaster
    {
        $this->assertCanManageRecycleBin();

        $lead = CaMaster::onlyTrashed()->findOrFail($caId);
        $lead->restore();

        $this->activityLogService->log(
            'CA_MASTER',
            'Restore Lead',
            $this->shortId((string) $lead->ca_id),
            $lead->firm_name ?: $lead->ca_name,
        );
        $this->invalidateDashboardCache();

        return $lead->fresh(['city', 'state']);
    }

    public function forceDelete(int $caId): void
    {
        $this->assertCanManageRecycleBin();

        $lead = CaMaster::onlyTrashed()->findOrFail($caId);
        $label = $lead->firm_name ?: $lead->ca_name;

        $this->activityLogService->log(
            'CA_MASTER',
            'Permanently Delete Lead',
            $this->shortId((string) $lead->ca_id),
            $label,
        );

        $lead->forceDelete();
        $this->invalidateDashboardCache();
    }

    /**
     * @param  list<int>  $caIds
     * @return array{restored_count: int, restored_ids: list<int>}
     */
    public function bulkRestore(array $caIds): array
    {
        $this->assertCanManageRecycleBin();
        $ids = array_values(array_unique(array_filter(array_map('intval', $caIds))));
        $restored = [];

        foreach (CaMaster::onlyTrashed()->whereIn('ca_id', $ids)->get() as $lead) {
            $lead->restore();
            $restored[] = (int) $lead->ca_id;
            $this->activityLogService->log(
                'CA_MASTER',
                'Restore Lead',
                $this->shortId((string) $lead->ca_id),
                $lead->firm_name ?: $lead->ca_name,
            );
        }

        if ($restored !== []) {
            $this->invalidateDashboardCache();
        }

        return [
            'restored_count' => count($restored),
            'restored_ids' => $restored,
        ];
    }

    /**
     * @param  list<int>  $caIds
     * @return array{deleted_count: int, deleted_ids: list<int>}
     */
    public function bulkForceDelete(array $caIds): array
    {
        $this->assertCanManageRecycleBin();
        $ids = array_values(array_unique(array_filter(array_map('intval', $caIds))));
        $deleted = [];

        foreach (CaMaster::onlyTrashed()->whereIn('ca_id', $ids)->get() as $lead) {
            $this->activityLogService->log(
                'CA_MASTER',
                'Permanently Delete Lead',
                $this->shortId((string) $lead->ca_id),
                $lead->firm_name ?: $lead->ca_name,
            );
            $deleted[] = (int) $lead->ca_id;
            $lead->forceDelete();
        }

        if ($deleted !== []) {
            $this->invalidateDashboardCache();
        }

        return [
            'deleted_count' => count($deleted),
            'deleted_ids' => $deleted,
        ];
    }

    private function assertCanViewRecycleBin(): void
    {
        $user = auth()->user();
        if (! $user) {
            throw new AuthorizationException('You do not have permission to view the recycle bin.');
        }
    }

    private function assertCanManageRecycleBin(): void
    {
        $user = auth()->user();
        if (! $user || $this->employeeDataScope->shouldScopeToEmployee($user)) {
            throw new AuthorizationException('You do not have permission to manage the recycle bin.');
        }
    }

    /**
     * Delete only the provided lead IDs. Never expands beyond the request list.
     *
     * @param  list<int|string>  $caIds
     * @return array{
     *     requested_count: int,
     *     deleted_count: int,
     *     deleted_ids: list<int>,
     *     skipped_ids: list<int>,
     *     not_found_ids: list<int>
     * }
     */
    public function bulkDelete(array $caIds): array
    {
        $user = auth()->user();
        if ($user && $this->employeeDataScope->shouldScopeToEmployee($user)) {
            throw new AuthorizationException('You do not have permission to delete leads.');
        }

        $requestedIds = array_values(array_unique(array_filter(
            array_map(static fn ($id) => (int) $id, $caIds),
            static fn (int $id) => $id > 0,
        )));

        if ($requestedIds === []) {
            throw new \InvalidArgumentException('Select at least one lead to delete.');
        }

        $leads = CaMaster::query()
            ->whereIn('ca_id', $requestedIds)
            ->get()
            ->keyBy('ca_id');

        $foundIds = $leads->keys()->map(fn ($id) => (int) $id)->all();
        $notFoundIds = array_values(array_diff($requestedIds, $foundIds));
        $deletedIds = [];
        $skippedIds = [];

        DB::transaction(function () use ($leads, $user, &$deletedIds, &$skippedIds) {
            foreach ($leads as $lead) {
                try {
                    if ($user) {
                        $this->leadLockService->assertCanMutate($lead, $user);
                    }

                    $before = $this->auditSnapshot($lead);
                    $this->activityLogService->log(
                        'CA_MASTER',
                        'Delete Lead',
                        $this->shortId((string) $lead->ca_id),
                        $lead->firm_name ?: $lead->ca_name,
                        beforeValue: $before,
                    );
                    $lead->delete();
                    $deletedIds[] = (int) $lead->ca_id;
                } catch (\Throwable) {
                    $skippedIds[] = (int) $lead->ca_id;
                }
            }
        });

        if ($deletedIds !== []) {
            $this->invalidateDashboardCache();
        }

        return [
            'requested_count' => count($requestedIds),
            'deleted_count' => count($deletedIds),
            'deleted_ids' => $deletedIds,
            'skipped_ids' => $skippedIds,
            'not_found_ids' => $notFoundIds,
        ];
    }

    private function normalize(array $data, ?CaMaster $existing = null): array
    {
        $stateId = $this->lookupResolver->resolveStateId($data['state_id'] ?? $existing?->state_id);
        $cityId = $this->lookupResolver->resolveCityId($data['city_id'] ?? $existing?->city_id, $stateId);
        $sourceId = $this->lookupResolver->resolveSourceId($data['source_id'] ?? $existing?->source_id);

        return [
            'ca_name' => $data['ca_name'] ?? $existing?->ca_name,
            'firm_name' => $data['firm_name'] ?? $existing?->firm_name,
            'mobile_no' => $this->normalizeStoredMobile($data['mobile_no'] ?? $existing?->mobile_no),
            'normalized_mobile' => $data['normalized_mobile'] ?? $this->phoneNormalization->normalize($data['mobile_no'] ?? $existing?->mobile_no)
                ?? $this->phoneClassification->digitsOnly($data['mobile_no'] ?? $existing?->mobile_no),
            'mobile_no_type' => array_key_exists('mobile_no', $data)
                ? $this->phoneClassification->classify($data['mobile_no'])
                : ($existing?->mobile_no_type ?? $this->phoneClassification->classify($existing?->mobile_no)),
            'alternate_mobile_no' => $this->normalizeStoredMobile($data['alternate_mobile_no'] ?? $existing?->alternate_mobile_no),
            'normalized_alternate_mobile' => $data['normalized_alternate_mobile'] ?? $this->phoneNormalization->normalize($data['alternate_mobile_no'] ?? $existing?->alternate_mobile_no)
                ?? $this->phoneClassification->digitsOnly($data['alternate_mobile_no'] ?? $existing?->alternate_mobile_no),
            'alternate_mobile_no_type' => array_key_exists('alternate_mobile_no', $data)
                ? $this->phoneClassification->classify($data['alternate_mobile_no'])
                : ($existing?->alternate_mobile_no_type ?? $this->phoneClassification->classify($existing?->alternate_mobile_no)),
            'email_id' => $data['email_id'] ?? $existing?->email_id,
            'normalized_email' => $data['normalized_email'] ?? $existing?->normalized_email,
            'gst_no' => $data['gst_no'] ?? $existing?->gst_no,
            'pan_no' => $data['pan_no'] ?? $existing?->pan_no,
            'city_id' => $cityId,
            'state_id' => $stateId,
            'source_id' => $sourceId,
            'team_size' => $data['team_size'] ?? $existing?->team_size,
            'existing_software' => $data['existing_software'] ?? $existing?->existing_software,
            'website' => $data['website'] ?? $existing?->website,
            'normalized_website' => $data['normalized_website'] ?? $existing?->normalized_website,
            'google_place_id' => $data['google_place_id'] ?? $existing?->google_place_id,
            'rating' => $data['rating'] ?? $existing?->rating ?? 1,
            'is_newly_established' => $this->toBoolean($data['is_newly_established'] ?? $existing?->is_newly_established ?? false),
            'status' => $data['status'] ?? $existing?->status ?? 'New',
            'lead_tags' => array_key_exists('lead_tags', $data)
                ? $this->normalizeLeadTags($data['lead_tags'])
                : $existing?->lead_tags,
            'priority' => array_key_exists('priority', $data)
                ? $this->normalizePriority($data['priority'])
                : ($existing?->priority ?? 'Medium'),
            'research_status' => array_key_exists('research_status', $data)
                ? $data['research_status']
                : $existing?->research_status,
        ];
    }

    /**
     * @param  mixed  $tags
     * @return list<string>|null
     */
    private function normalizeLeadTags(mixed $tags): ?array
    {
        if ($tags === null) {
            return null;
        }

        if (is_string($tags)) {
            $tags = array_map('trim', explode(',', $tags));
        }

        if (! is_array($tags)) {
            return null;
        }

        $allowed = config('crm_leads.allowed_tags', []);
        $normalized = array_values(array_unique(array_filter(array_map(
            fn ($tag) => in_array($tag, $allowed, true) ? $tag : null,
            $tags,
        ))));

        return $normalized === [] ? null : $normalized;
    }

    private function normalizePriority(?string $priority): string
    {
        $allowed = config('crm_leads.priorities', ['High', 'Medium', 'Low']);
        $priority = $priority ?? 'Medium';

        return in_array($priority, $allowed, true) ? $priority : 'Medium';
    }

    private function toBoolean(mixed $value): bool
    {
        return in_array($value, ['yes', '1', 1, true, 'true'], true);
    }

    private function normalizeStoredMobile(mixed $value): ?string
    {
        return $this->phoneClassification->digitsOnly($value)
            ?? $this->phoneNormalization->normalize($value);
    }

    private function shortId(string $id): string
    {
        return strlen($id) <= 8 ? $id : substr($id, 0, 4).'…'.substr($id, -2);
    }

    private function auditSnapshot(CaMaster $lead): array
    {
        return [
            'ca_id' => $lead->ca_id,
            'ca_name' => $lead->ca_name,
            'firm_name' => $lead->firm_name,
            'mobile_no' => $lead->mobile_no,
            'alternate_mobile_no' => $lead->alternate_mobile_no,
            'email_id' => $lead->email_id,
            'status' => $lead->status,
            'lead_tags' => $lead->lead_tags,
            'priority' => $lead->priority,
            'research_status' => $lead->research_status,
            'city_id' => $lead->city_id,
            'state_id' => $lead->state_id,
        ];
    }

    private function contactSnapshot(CaMaster $lead): array
    {
        return [
            'mobile_no' => $lead->mobile_no,
            'alternate_mobile_no' => $lead->alternate_mobile_no,
            'email_id' => $lead->email_id,
            'website' => $lead->website,
        ];
    }

    private function extractExecutiveId(array &$data): ?int
    {
        $id = $data['executive_id'] ?? $data['employee_id'] ?? null;
        unset($data['executive_id'], $data['employee_id']);

        if ($id === null || $id === '') {
            return null;
        }

        return (int) $id;
    }

    private function assignExecutive(int $caId, int $employeeId): void
    {
        $assignedBy = $this->employeeDataScope->resolveEmployeeId(auth()->user());

        $this->assignmentRecorder->assign(
            $caId,
            $employeeId,
            'Manual',
            'Assigned from lead form',
            $assignedBy,
            'Lead Assignment',
            'manual',
        );

        $this->cacheService->forgetMasterListings();
    }

    private function invalidateDashboardCache(): void
    {
        $scopeKey = $this->employeeDataScope->cacheScopeKey();
        $this->cacheService->forgetDashboardMetrics('org');
        $this->cacheService->forgetLeadSegmentCounts('org');
        $this->cacheService->forgetPipelineStageCounts('org');
        $this->cacheService->forgetEmployeeRankings();
        if ($scopeKey !== 'org') {
            $this->cacheService->forgetDashboardMetrics($scopeKey);
            $this->cacheService->forgetLeadSegmentCounts($scopeKey);
            $this->cacheService->forgetPipelineStageCounts($scopeKey);
        }
        $employeeId = $this->employeeDataScope->scopedEmployeeId(auth()->user());
        if ($employeeId) {
            $this->cacheService->forgetDailyEmployeeTargets($employeeId);
            $this->cacheService->forgetYearlyEmployeeTargets($employeeId);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertValidMobileFields(array $data): void
    {
        if (array_key_exists('mobile_no', $data)) {
            $this->phoneClassification->assertValidForSave($data['mobile_no'], 'mobile_no');
        }

        if (array_key_exists('alternate_mobile_no', $data)) {
            $this->phoneClassification->assertValidForSave($data['alternate_mobile_no'], 'alternate_mobile_no');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleQualityStatusSideEffects(CaMaster $lead, array $data, $user): void
    {
        if (array_key_exists('status', $data) && in_array((string) $data['status'], config('crm_duplicates.wrong_number_statuses', []), true)) {
            $this->leadQualityHistory->markWrongNumber($lead, 'Status changed to '.$data['status'], $user);
        }

        if (! empty($data['is_verified']) && ! $lead->is_verified) {
            $this->leadQualityHistory->markVerified($lead, $user);
        }
    }

    private function isDuplicatePhoneRegistryViolation(QueryException $exception): bool
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        return ($driverCode === 1062 || ($exception->errorInfo[0] ?? '') === '23000')
            && str_contains($message, 'lead_phone_numbers');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function duplicatePhoneExceptionFromPayload(array $data): DuplicateLeadException
    {
        $mobile = $data['mobile_no'] ?? $data['alternate_mobile_no'] ?? null;
        $duplicateInfo = $this->duplicateLeadDetection->checkMobile($mobile);

        return new DuplicateLeadException(
            config('crm_duplicates.messages.phone'),
            $duplicateInfo ?? [
                'attempted_mobile' => $this->duplicateLeadDetection->normalize($mobile),
                'message' => config('crm_duplicates.messages.phone'),
            ],
        );
    }
}
