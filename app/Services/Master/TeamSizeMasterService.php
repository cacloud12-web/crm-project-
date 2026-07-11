<?php

namespace App\Services\Master;

use App\Models\TeamSizeMaster;
use App\Services\Concerns\SearchesListings;
use App\Services\Master\Concerns\LogsMasterActivity;
use Illuminate\Support\Collection;

class TeamSizeMasterService
{
    use LogsMasterActivity;
    use SearchesListings;

    public function __construct(
        private readonly MasterRecordLifecycleService $lifecycleService,
        private readonly MasterDependencyService $dependencyService,
    ) {}

    public function search(array $params = []): array
    {
        return $this->searchListing(TeamSizeMaster::query(), $params, 'team_sizes');
    }

    public function list(): Collection
    {
        return $this->listAllFromSearch(TeamSizeMaster::query(), [], 'team_sizes');
    }

    public function find(int|string $id): TeamSizeMaster
    {
        return TeamSizeMaster::query()->findOrFail($id);
    }

    public function create(array $data): TeamSizeMaster
    {
        $teamSize = TeamSizeMaster::create([
            'team_size_min' => $data['team_size_min'],
            'team_size_max' => $data['team_size_max'],
            'team_size_label' => trim($data['team_size_label']),
            'is_active' => true,
        ]);

        $this->logMasterActivity(
            'Add Team Size',
            'TEAM_SIZE_MASTER',
            (string) $teamSize->id,
            $teamSize->team_size_label,
        );

        return $teamSize;
    }

    public function update(TeamSizeMaster $teamSize, array $data): TeamSizeMaster
    {
        $before = $teamSize->team_size_label;
        $teamSize->update([
            'team_size_min' => $data['team_size_min'] ?? $teamSize->team_size_min,
            'team_size_max' => $data['team_size_max'] ?? $teamSize->team_size_max,
            'team_size_label' => trim($data['team_size_label'] ?? $teamSize->team_size_label),
        ]);

        $this->logMasterActivity(
            'Update Team Size',
            'TEAM_SIZE_MASTER',
            (string) $teamSize->id,
            $before.' → '.$teamSize->team_size_label,
        );

        return $teamSize->fresh();
    }

    public function dependencies(TeamSizeMaster $teamSize): array
    {
        return $this->dependencyService->analyze(MasterDependencyService::ENTITY_TEAM, $teamSize);
    }

    public function delete(TeamSizeMaster $teamSize): void
    {
        $this->lifecycleService->delete(MasterDependencyService::ENTITY_TEAM, $teamSize);
    }

    public function deactivate(TeamSizeMaster $teamSize, ?int $userId = null): TeamSizeMaster
    {
        return $this->lifecycleService->deactivate(MasterDependencyService::ENTITY_TEAM, $teamSize, $userId);
    }

    public function reactivate(TeamSizeMaster $teamSize): TeamSizeMaster
    {
        return $this->lifecycleService->reactivate(MasterDependencyService::ENTITY_TEAM, $teamSize);
    }
}
