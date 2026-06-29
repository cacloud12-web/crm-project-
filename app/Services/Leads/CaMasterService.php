<?php

namespace App\Services\Leads;

use App\Models\CaMaster;
use App\Services\Activity\ActivityLogService;
use App\Services\Cache\CrmCacheService;
use App\Services\Concerns\SearchesListings;
use App\Services\Master\LookupResolverService;
use App\Services\Rbac\EmployeeDataScopeService;
use Illuminate\Support\Collection;

class CaMasterService
{
    use SearchesListings;

    public function __construct(
        private readonly LookupResolverService $lookupResolver,
        private readonly ActivityLogService $activityLogService,
        private readonly CrmCacheService $cacheService,
        private readonly EmployeeDataScopeService $employeeDataScope,
    ) {}

    public function search(array $params = []): array
    {
        return $this->searchListing(
            CaMaster::query()->with(['city', 'state', 'sourceLead']),
            $params,
            'ca_masters',
        );
    }

    public function list(): Collection
    {
        return $this->listAllFromSearch(
            CaMaster::query()->with(['city', 'state', 'sourceLead']),
            [],
            'ca_masters',
        );
    }

    public function find(int|string $id): CaMaster
    {
        app(EmployeeDataScopeService::class)->ensureCanAccessCaMaster($id);

        return CaMaster::query()
            ->with(['city', 'state', 'sourceLead'])
            ->findOrFail($id);
    }

    public function create(array $data): CaMaster
    {
        $lead = CaMaster::create($this->normalize($data));

        $this->activityLogService->log(
            'CA_MASTER',
            'Add Lead',
            $this->shortId((string) $lead->ca_id),
            $lead->firm_name ?: $lead->ca_name,
            afterValue: $this->auditSnapshot($lead),
        );

        $this->invalidateDashboardCache();

        return $lead;
    }

    public function update(CaMaster $caMaster, array $data): CaMaster
    {
        $before = $this->auditSnapshot($caMaster);
        $caMaster->update($this->normalize($data));
        $lead = $caMaster->fresh(['city', 'state', 'sourceLead']);

        $this->activityLogService->log(
            'CA_MASTER',
            'Update Lead',
            $this->shortId((string) $lead->ca_id),
            $lead->firm_name ?: $lead->ca_name,
            beforeValue: $before,
            afterValue: $this->auditSnapshot($lead),
        );

        $this->invalidateDashboardCache();

        return $lead;
    }

    public function updateStatus(CaMaster $caMaster, string $status): CaMaster
    {
        $before = $this->auditSnapshot($caMaster);
        $caMaster->update(['status' => $status]);
        $lead = $caMaster->fresh(['city', 'state', 'sourceLead']);

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
        $before = $this->contactSnapshot($caMaster);
        $caMaster->update([
            'mobile_no' => $this->normalizeStoredMobile($data['mobile_no'] ?? null),
            'alternate_mobile_no' => $this->normalizeStoredMobile($data['alternate_mobile_no'] ?? null),
            'email_id' => $data['email_id'] ?? null,
            'website' => $data['website'] ?? null,
        ]);
        $lead = $caMaster->fresh(['city', 'state', 'sourceLead']);

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
        $before = $this->auditSnapshot($caMaster);

        $this->activityLogService->log(
            'CA_MASTER',
            'Delete Lead',
            $this->shortId((string) $caMaster->ca_id),
            $caMaster->firm_name ?: $caMaster->ca_name,
            beforeValue: $before,
        );

        $caMaster->delete();
        $this->invalidateDashboardCache();
    }

    private function normalize(array $data): array
    {
        $stateId = $this->lookupResolver->resolveStateId($data['state_id'] ?? null);
        $cityId = $this->lookupResolver->resolveCityId($data['city_id'] ?? null, $stateId);
        $sourceId = $this->lookupResolver->resolveSourceId($data['source_id'] ?? null);

        return [
            'ca_name' => $data['ca_name'],
            'firm_name' => $data['firm_name'] ?? null,
            'mobile_no' => $this->normalizeStoredMobile($data['mobile_no'] ?? null),
            'alternate_mobile_no' => $this->normalizeStoredMobile($data['alternate_mobile_no'] ?? null),
            'email_id' => $data['email_id'] ?? null,
            'gst_no' => $data['gst_no'] ?? null,
            'city_id' => $cityId,
            'state_id' => $stateId,
            'source_id' => $sourceId,
            'team_size' => $data['team_size'] ?? null,
            'existing_software' => $data['existing_software'] ?? null,
            'website' => $data['website'] ?? null,
            'rating' => $data['rating'] ?? 1,
            'is_newly_established' => $this->toBoolean($data['is_newly_established'] ?? false),
            'status' => $data['status'] ?? 'Active',
        ];
    }

    private function toBoolean(mixed $value): bool
    {
        return in_array($value, ['yes', '1', 1, true, 'true'], true);
    }

    private function normalizeStoredMobile(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $value) ?? '';
        if (strlen($digits) > 10 && str_starts_with($digits, '91')) {
            $digits = substr($digits, -10);
        }

        return $digits !== '' ? $digits : null;
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

    private function invalidateDashboardCache(): void
    {
        $this->cacheService->forgetDashboardMetrics('org');
        $scopeKey = $this->employeeDataScope->cacheScopeKey();
        if ($scopeKey !== 'org') {
            $this->cacheService->forgetDashboardMetrics($scopeKey);
        }
    }
}
