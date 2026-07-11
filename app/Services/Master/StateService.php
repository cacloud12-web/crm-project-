<?php

namespace App\Services\Master;

use App\Models\State;
use App\Services\Concerns\SearchesListings;
use App\Services\Master\Concerns\LogsMasterActivity;
use Illuminate\Support\Collection;

class StateService
{
    use LogsMasterActivity;
    use SearchesListings;

    public function __construct(
        private readonly MasterRecordLifecycleService $lifecycleService,
        private readonly MasterDependencyService $dependencyService,
    ) {}

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
            'is_active' => true,
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

    public function dependencies(State $state): array
    {
        return $this->dependencyService->analyze(MasterDependencyService::ENTITY_STATE, $state);
    }

    public function delete(State $state): void
    {
        $this->lifecycleService->delete(MasterDependencyService::ENTITY_STATE, $state);
    }

    public function deactivate(State $state, ?int $userId = null): State
    {
        return $this->lifecycleService->deactivate(MasterDependencyService::ENTITY_STATE, $state, $userId);
    }

    public function reactivate(State $state): State
    {
        return $this->lifecycleService->reactivate(MasterDependencyService::ENTITY_STATE, $state);
    }
}
