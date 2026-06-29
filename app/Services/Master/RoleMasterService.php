<?php

namespace App\Services\Master;

use App\Models\Employee;
use App\Models\RoleMaster;
use App\Services\Concerns\SearchesListings;
use App\Services\Master\Concerns\LogsMasterActivity;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class RoleMasterService
{
    use LogsMasterActivity;
    use SearchesListings;

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

    public function delete(RoleMaster $role): void
    {
        if (Employee::query()->where('role', $role->role_name)->exists()) {
            throw new InvalidArgumentException('Cannot delete a role that is assigned to employees.');
        }

        $name = $role->role_name;
        $id = (string) $role->id;
        $role->delete();

        $this->logMasterActivity('Delete Role', 'ROLE_MASTER', $id, $name);
    }
}
