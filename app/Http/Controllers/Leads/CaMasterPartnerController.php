<?php

namespace App\Http\Controllers\Leads;

use App\Exceptions\DuplicateLeadException;
use App\Exceptions\LeadLockedException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CaMasterResource;
use App\Models\CaMasterPartner;
use App\Services\Leads\CaMasterPartnerService;
use App\Services\Leads\CaMasterService;
use App\Services\Leads\LeadLockService;
use App\Services\Leads\LeadOwnershipService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CaMasterPartnerController extends Controller
{
    public function __construct(
        private readonly CaMasterService $caMasterService,
        private readonly CaMasterPartnerService $partnerService,
        private readonly LeadOwnershipService $leadOwnership,
        private readonly LeadLockService $leadLockService,
    ) {}

    public function index(string $caMaster): JsonResponse
    {
        $lead = $this->caMasterService->find($caMaster);
        $lead->load('partners');

        return ApiResponse::success(
            (new CaMasterResource($lead))->resolve(request())['partners'] ?? [],
            'Partners loaded',
        );
    }

    public function store(Request $request, string $caMaster): JsonResponse
    {
        $lead = $this->assertCanEdit($request, $caMaster);
        $data = $request->validate([
            'ca_name' => 'required|string|max:255',
            'membership_no' => 'nullable|string|max:64',
            'mobile' => 'nullable|string|max:32',
            'alternate_mobile' => 'nullable|string|max:32',
            'email' => 'nullable|email|max:255',
            'designation' => 'nullable|string|max:64',
            'is_primary' => 'sometimes|boolean',
        ]);

        try {
            $partner = $this->partnerService->create($lead, $data);
        } catch (DuplicateLeadException $e) {
            return ApiResponse::error($e->getMessage(), 409, ['duplicate' => $e->duplicateInfo()]);
        }

        return ApiResponse::success($this->partnerPayload($partner), 'Partner added', 201);
    }

    public function update(Request $request, string $caMaster, string $partner): JsonResponse
    {
        $lead = $this->assertCanEdit($request, $caMaster);
        $model = $this->findPartner($lead->ca_id, $partner);
        $data = $request->validate([
            'ca_name' => 'sometimes|string|max:255',
            'membership_no' => 'nullable|string|max:64',
            'mobile' => 'nullable|string|max:32',
            'alternate_mobile' => 'nullable|string|max:32',
            'email' => 'nullable|email|max:255',
            'designation' => 'nullable|string|max:64',
            'is_primary' => 'sometimes|boolean',
            'status' => 'sometimes|string|max:32',
            'team_size' => 'sometimes|integer|min:0',
        ]);

        try {
            $model = $this->partnerService->update($lead, $model, $data);
        } catch (DuplicateLeadException $e) {
            return ApiResponse::error($e->getMessage(), 409, ['duplicate' => $e->duplicateInfo()]);
        }

        return ApiResponse::success($this->partnerPayload($model), 'Partner updated');
    }

    public function destroy(Request $request, string $caMaster, string $partner): JsonResponse
    {
        $lead = $this->assertCanEdit($request, $caMaster);
        $model = $this->findPartner($lead->ca_id, $partner);
        $this->partnerService->delete($lead, $model);

        return ApiResponse::success(null, 'Partner removed');
    }

    public function setPrimary(Request $request, string $caMaster, string $partner): JsonResponse
    {
        $lead = $this->assertCanEdit($request, $caMaster);
        $model = $this->findPartner($lead->ca_id, $partner);
        $model = $this->partnerService->setPrimary($lead, $model);

        return ApiResponse::success($this->partnerPayload($model), 'Primary partner updated');
    }

    public function updateMobile(Request $request, string $caMaster, string $partner): JsonResponse
    {
        $lead = $this->assertCanEdit($request, $caMaster);
        $model = $this->findPartner($lead->ca_id, $partner);
        $data = $request->validate([
            'mobile' => 'nullable|string|max:32',
            'mobile_no' => 'nullable|string|max:32',
            'alternate_mobile' => 'nullable|string|max:32',
            'alternate_mobile_no' => 'nullable|string|max:32',
        ]);

        try {
            $model = $this->partnerService->updateMobile($lead, $model, $data);
        } catch (DuplicateLeadException $e) {
            return ApiResponse::error($e->getMessage(), 409, ['duplicate' => $e->duplicateInfo()]);
        } catch (ValidationException $e) {
            throw $e;
        }

        return ApiResponse::success($this->partnerPayload($model), 'Partner mobile updated');
    }

    public function updateTeamSize(Request $request, string $caMaster, string $partner): JsonResponse
    {
        $lead = $this->assertCanEdit($request, $caMaster);
        $model = $this->findPartner($lead->ca_id, $partner);
        $data = $request->validate(['team_size' => 'required|integer|min:0']);
        $model = $this->partnerService->updateTeamSize($lead, $model, (int) $data['team_size']);

        return ApiResponse::success($this->partnerPayload($model), 'Partner team size updated');
    }

    private function assertCanEdit(Request $request, string $caMaster)
    {
        $user = $request->user();
        $lead = $this->caMasterService->find($caMaster);
        if ($user) {
            $this->leadOwnership->assertCanEdit($user, $lead);
            try {
                $this->leadLockService->assertCanMutate($lead, $user);
            } catch (LeadLockedException $e) {
                abort(423, $e->getMessage());
            }
        }

        return $lead;
    }

    private function findPartner(int $caId, string $partnerId): CaMasterPartner
    {
        return CaMasterPartner::query()
            ->where('ca_id', $caId)
            ->where('id', (int) $partnerId)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function partnerPayload(CaMasterPartner $partner): array
    {
        return [
            'id' => (int) $partner->id,
            'ca_id' => (int) $partner->ca_id,
            'ca_name' => $partner->ca_name,
            'membership_no' => $partner->membership_no,
            'mobile' => $partner->mobile,
            'alternate_mobile' => $partner->alternate_mobile,
            'email' => $partner->email,
            'team_size' => max(0, (int) ($partner->team_size ?? 0)),
            'designation' => $partner->designation,
            'is_primary' => (bool) $partner->is_primary,
            'status' => $partner->status,
            'sequence_no' => (int) ($partner->sequence_no ?? 0),
        ];
    }
}
