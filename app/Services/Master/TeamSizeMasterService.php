<?php

namespace App\Services\Master;

use App\Models\CaMaster;
use App\Models\TeamSizeMaster;
use App\Services\Concerns\SearchesListings;
use App\Services\Master\Concerns\LogsMasterActivity;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class TeamSizeMasterService
{
    use LogsMasterActivity;
    use SearchesListings;

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

    public function delete(TeamSizeMaster $teamSize): void
    {
        if (CaMaster::query()->where('team_size_id', $teamSize->id)->exists()) {
            throw new InvalidArgumentException('Cannot delete a team size that is used by CA Master records.');
        }

        $label = $teamSize->team_size_label;
        $id = (string) $teamSize->id;
        $teamSize->delete();

        $this->logMasterActivity('Delete Team Size', 'TEAM_SIZE_MASTER', $id, $label);
    }
}
