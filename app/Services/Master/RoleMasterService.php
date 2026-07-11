<?php

namespace App\Services\Master;

use App\Models\RoleMaster;
use App\Services\Concerns\SearchesListings;
use App\Services\Master\Concerns\LogsMasterActivity;
use Illuminate\Support\Collection;

class RoleMasterService
{
    use LogsMasterActivity;
    use SearchesListings;

    public function __construct(
        private readonly MasterRecordLifecycleService $lifecycleService,
        private readonly MasterDependencyService $dependencyService,
    ) {}

    public function search(array $params = []): array
    {
        return $this->searchListing(RoleMaster::query(), $params, 'role_masters');
    }

    public function list(): Collection
    {
        return $this->listAllFromSearch(RoleMaster::query(), [], 'role_masters');
    }

    public function find(int|string $id): RoleMaster
    {
        return RoleMaster::query()->findOrFail($id);
    }

    public function create(array $data): RoleMaster
    {
        $role = RoleMaster::create([
            'role_name' => trim($data['role_name']),
            'description' => $data['description'] ?? null,
            'is_active' => true,
        ]);

        $this->logMasterActivity(
            'Add Role',
            'ROLE_MASTER',
            (string) $role->id,
            $role->role_name,
        );

        return $role;
    }

    public function update(RoleMaster $role, array $data): RoleMaster
    {
        $before = $role->role_name;
        $role->update([
            'role_name' => trim($data['role_name'] ?? $role->role_name),
            'description' => $data['description'] ?? $role->description,
        ]);

        $this->logMasterActivity(
            'Update Role',
            'ROLE_MASTER',
            (string) $role->id,
            $before.' → '.$role->role_name,
        );

        return $role->fresh();
    }

    public function dependencies(RoleMaster $role): array
    {
        return $this->dependencyService->analyze(MasterDependencyService::ENTITY_ROLE, $role);
    }

    public function delete(RoleMaster $role): void
    {
        $this->lifecycleService->delete(MasterDependencyService::ENTITY_ROLE, $role);
    }

    public function deactivate(RoleMaster $role, ?int $userId = null): RoleMaster
    {
        return $this->lifecycleService->deactivate(MasterDependencyService::ENTITY_ROLE, $role, $userId);
    }

    public function reactivate(RoleMaster $role): RoleMaster
    {
        return $this->lifecycleService->reactivate(MasterDependencyService::ENTITY_ROLE, $role);
    }
}
