<?php

namespace App\Services\Master;

use App\Models\CaMaster;
use App\Models\State;
use App\Services\Concerns\SearchesListings;
use App\Services\Master\Concerns\LogsMasterActivity;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class StateService
{
    use LogsMasterActivity;
    use SearchesListings;

    public function search(array $params = []): array
    {
        return $this->searchListing(
            State::query()->withCount('cities'),
            $params,
            'states',
        );
    }

    public function list(): Collection
    {
        return $this->listAllFromSearch(
            State::query()->withCount('cities'),
            [],
            'states',
        );
    }

    public function find(int|string $id): State
    {
        return State::query()->withCount('cities')->findOrFail($id);
    }

    public function create(array $data): State
    {
        $state = State::create([
            'state_name' => trim($data['state_name']),
        ]);

        $this->logMasterActivity('Add State', 'STATE_MASTER', (string) $state->state_id, $state->state_name);

        return $state->fresh()->loadCount('cities');
    }

    public function update(State $state, array $data): State
    {
        $before = $state->state_name;
        $state->update([
            'state_name' => trim($data['state_name'] ?? $state->state_name),
        ]);

        $this->logMasterActivity(
            'Update State',
            'STATE_MASTER',
            (string) $state->state_id,
            $before.' → '.$state->state_name,
        );

        return $state->fresh()->loadCount('cities');
    }

    public function delete(State $state): void
    {
        if ($state->cities()->exists()) {
            throw new InvalidArgumentException('Cannot delete a state that has cities.');
        }

        if (CaMaster::query()->where('state_id', $state->state_id)->exists()) {
            throw new InvalidArgumentException('Cannot delete a state that is used by CA Master records.');
        }

        $name = $state->state_name;
        $id = (string) $state->state_id;
        $state->delete();

        $this->logMasterActivity('Delete State', 'STATE_MASTER', $id, $name);
    }
}
