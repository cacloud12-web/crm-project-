<?php

namespace App\Http\Controllers\Leads;

use App\Exceptions\DuplicateLeadException;
use App\Exceptions\LeadLockedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CaMaster\StoreCaMasterRequest;
use App\Http\Requests\CaMaster\UpdateCaMasterContactRequest;
use App\Http\Requests\CaMaster\UpdateCaMasterRequest;
use App\Http\Requests\CaMaster\UpdateCaMasterStatusRequest;
use App\Http\Resources\CaMasterResource;
use App\Services\Leads\CaMasterService;
use App\Services\Leads\LeadLockService;
use App\Services\Leads\LeadActivityTimelineService;
use App\Services\Leads\LeadTeamMemberService;
use App\Services\Leads\LeadViewService;
use App\Services\Rbac\EmployeeLeadFieldGuard;
use App\Support\ApiResponse;
use App\Support\Listing\ListingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CaMasterController extends Controller
{
    public function __construct(
        private readonly CaMasterService $caMasterService,
        private readonly LeadViewService $leadViewService,
        private readonly EmployeeLeadFieldGuard $employeeLeadFieldGuard,
        private readonly LeadLockService $leadLockService,
        private readonly LeadTeamMemberService $leadTeamMemberService,
        private readonly LeadActivityTimelineService $leadActivityTimelineService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->caMasterService->search($request->query());

        return ListingResponse::from($result, CaMasterResource::class, 'Leads loaded');
    }

    public function segmentCounts(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->caMasterService->segmentCounts((string) $request->query('pipeline', '')),
            'Lead segment counts loaded',
        );
    }

    public function kanban(Request $request): JsonResponse
    {
        $result = $this->caMasterService->kanbanBoard($request->query());
        CaMasterResource::prepareCollection($result['items']);

        return ApiResponse::success([
            'stage_counts' => $result['stage_counts'],
            'items' => CaMasterResource::collection($result['items'])->resolve(),
            'per_stage' => $result['per_stage'],
            'pipeline' => $result['pipeline'],
        ], 'Pipeline board loaded');
    }

    public function create()
    {
        return redirect('/');
    }

    public function store(StoreCaMasterRequest $request): JsonResponse
    {
        try {
            $lead = $this->caMasterService->create($request->validated());

            return ApiResponse::created(
                new CaMasterResource($lead),
                'Lead added successfully',
            );
        } catch (DuplicateLeadException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, [
                'duplicate' => $exception->duplicateInfo(),
            ]);
        }
    }

    public function show(string $id): JsonResponse
    {
        $lead = $this->caMasterService->find($id);
        $this->leadLockService->expireIfStale($lead);
        $this->leadViewService->recordView($lead);

        $relations = ['city', 'state', 'sourceLead', 'lockedByEmployee', 'activeTeamAssignments.employee'];
        if (\Illuminate\Support\Facades\Schema::hasTable('ca_master_partners')) {
            $relations[] = 'partners';
        }
        $fresh = $lead->fresh($relations);

        return ApiResponse::success(new CaMasterResource($fresh));
    }

    public function teamMembers(string $id): JsonResponse
    {
        $lead = $this->caMasterService->find($id);

        return ApiResponse::success(
            $this->leadTeamMemberService->detailsForLead($lead),
            'Team members loaded',
        );
    }

    public function activityTimeline(string $id): JsonResponse
    {
        $lead = $this->caMasterService->find($id);
        $limit = min(20, max(1, (int) request()->query('limit', 10)));

        return ApiResponse::success(
            $this->leadActivityTimelineService->timelineForLead($lead, $limit),
            'Activity timeline loaded',
        );
    }

    public function edit(string $id)
    {
        return redirect('/');
    }

    public function acquireLock(Request $request, string $id): JsonResponse
    {
        try {
            $lead = $this->caMasterService->find($id);
            $this->leadLockService->acquire($lead, $request->user());

            return ApiResponse::success(
                new CaMasterResource($lead->fresh(['city', 'state', 'sourceLead', 'lockedByEmployee'])),
                'Lead locked for editing',
            );
        } catch (LeadLockedException $exception) {
            return ApiResponse::error($exception->getMessage(), 423, [
                'lock' => $exception->lockInfo(),
            ]);
        }
    }

    public function releaseLock(Request $request, string $id): JsonResponse
    {
        $lead = $this->caMasterService->find($id);
        $this->leadLockService->release($lead, $request->user());

        return ApiResponse::success(
            new CaMasterResource($lead->fresh(['city', 'state', 'sourceLead', 'lockedByEmployee'])),
            'Lead lock released',
        );
    }

    public function update(UpdateCaMasterRequest $request, string $id): JsonResponse
    {
        try {
            $lead = $this->caMasterService->find($id);
            $lead = $this->caMasterService->update($lead, $request->validated());

            return ApiResponse::success(
                new CaMasterResource($lead),
                'Lead updated successfully',
            );
        } catch (LeadLockedException $exception) {
            return ApiResponse::error($exception->getMessage(), 423, [
                'lock' => $exception->lockInfo(),
            ]);
        } catch (DuplicateLeadException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, [
                'duplicate' => $exception->duplicateInfo(),
            ]);
        }
    }

    public function updateStatus(UpdateCaMasterStatusRequest $request, string $id): JsonResponse
    {
        try {
            $lead = $this->caMasterService->find($id);
            $this->employeeLeadFieldGuard->assertCanChangeStatus(
                $request->user(),
                $lead,
                $request->validated('status'),
            );
            $lead = $this->caMasterService->updateStatus($lead, $request->validated('status'));

            return ApiResponse::success(
                new CaMasterResource($lead),
                'Lead status updated successfully',
            );
        } catch (LeadLockedException $exception) {
            return ApiResponse::error($exception->getMessage(), 423, [
                'lock' => $exception->lockInfo(),
            ]);
        }
    }

    public function updateContact(UpdateCaMasterContactRequest $request, string $id): JsonResponse
    {
        try {
            $lead = $this->caMasterService->find($id);
            $lead = $this->caMasterService->updateContact($lead, $request->validated());

            return ApiResponse::success(
                new CaMasterResource($lead),
                'Contact details updated successfully',
            );
        } catch (LeadLockedException $exception) {
            return ApiResponse::error($exception->getMessage(), 423, [
                'lock' => $exception->lockInfo(),
            ]);
        } catch (DuplicateLeadException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, [
                'duplicate' => $exception->duplicateInfo(),
            ]);
        }
    }

    public function updateTeamSize(Request $request, string $caMaster): JsonResponse
    {
        try {
            $lead = $this->caMasterService->find($caMaster);
            $data = $request->validate(['team_size' => 'required|integer|min:0']);
            $lead = $this->caMasterService->updateTeamSize($lead, (int) $data['team_size']);

            return ApiResponse::success(new CaMasterResource($lead), 'Team size updated');
        } catch (LeadLockedException $exception) {
            return ApiResponse::error($exception->getMessage(), 423, [
                'lock' => $exception->lockInfo(),
            ]);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        $lead = $this->caMasterService->find($id);
        $this->caMasterService->delete($lead);

        return ApiResponse::success(null, 'Lead deleted successfully');
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ca_ids' => 'required|array|min:1|max:500',
            'ca_ids.*' => 'integer|distinct|min:1',
        ]);

        try {
            $result = $this->caMasterService->bulkDelete($validated['ca_ids']);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return ApiResponse::error($e->getMessage() ?: 'You do not have permission to delete leads.', 403);
        }

        $message = $result['deleted_count'] === $result['requested_count']
            ? $result['deleted_count'].' lead(s) deleted successfully'
            : $result['deleted_count'].' of '.$result['requested_count'].' selected lead(s) deleted';

        return ApiResponse::success($result, $message);
    }

    public function trashed(): JsonResponse
    {
        try {
            return ApiResponse::success(
                $this->caMasterService->listTrashed(),
                'Recycle bin loaded',
            );
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return ApiResponse::error($e->getMessage() ?: 'You do not have permission to view the recycle bin.', 403);
        }
    }

    public function restore(string $id): JsonResponse
    {
        try {
            $lead = $this->caMasterService->restore((int) $id);

            return ApiResponse::success(
                new CaMasterResource($lead),
                'Lead restored successfully',
            );
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return ApiResponse::error($e->getMessage() ?: 'You do not have permission to restore leads.', 403);
        }
    }

    public function forceDestroy(string $id): JsonResponse
    {
        try {
            $this->caMasterService->forceDelete((int) $id);

            return ApiResponse::success(null, 'Lead permanently deleted');
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return ApiResponse::error($e->getMessage() ?: 'You do not have permission to permanently delete leads.', 403);
        }
    }

    public function bulkRestore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ca_ids' => 'required|array|min:1|max:500',
            'ca_ids.*' => 'integer|distinct|min:1',
        ]);

        try {
            $result = $this->caMasterService->bulkRestore($validated['ca_ids']);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return ApiResponse::error($e->getMessage() ?: 'You do not have permission to restore leads.', 403);
        }

        return ApiResponse::success($result, $result['restored_count'].' lead(s) restored');
    }

    public function bulkForceDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ca_ids' => 'required|array|min:1|max:500',
            'ca_ids.*' => 'integer|distinct|min:1',
        ]);

        try {
            $result = $this->caMasterService->bulkForceDelete($validated['ca_ids']);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return ApiResponse::error($e->getMessage() ?: 'You do not have permission to permanently delete leads.', 403);
        }

        return ApiResponse::success($result, $result['deleted_count'].' lead(s) permanently deleted');
    }
}
