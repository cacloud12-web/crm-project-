<?php

namespace App\Services\Master;

use App\Models\SourceLead;
use App\Services\Concerns\SearchesListings;
use App\Services\Master\Concerns\LogsMasterActivity;
use Illuminate\Support\Collection;

class SourceLeadService
{
    use LogsMasterActivity;
    use SearchesListings;

    public function __construct(
        private readonly MasterRecordLifecycleService $lifecycleService,
        private readonly MasterDependencyService $dependencyService,
    ) {}

    public function search(array $params = []): array
    {
        return $this->searchListing(SourceLead::query(), $params, 'source_leads');
    }

    public function list(): Collection
    {
        return $this->listAllFromSearch(SourceLead::query(), [], 'source_leads');
    }

    public function find(int|string $id): SourceLead
    {
        return SourceLead::query()->findOrFail($id);
    }

    public function create(array $data): SourceLead
    {
        $source = SourceLead::create([
            'source_name' => trim($data['source_name']),
            'is_active' => true,
        ]);

        $this->logMasterActivity(
            'Add Source',
            'SOURCE_OF_LEAD',
            (string) $source->source_id,
            $source->source_name,
        );

        return $source;
    }

    public function update(SourceLead $source, array $data): SourceLead
    {
        $before = $source->source_name;
        $source->update([
            'source_name' => trim($data['source_name'] ?? $source->source_name),
        ]);

        $this->logMasterActivity(
            'Update Source',
            'SOURCE_OF_LEAD',
            (string) $source->source_id,
            $before.' → '.$source->source_name,
        );

        return $source->fresh();
    }

    public function dependencies(SourceLead $source): array
    {
        return $this->dependencyService->analyze(MasterDependencyService::ENTITY_SOURCE, $source);
    }

    public function delete(SourceLead $source): void
    {
        $this->lifecycleService->delete(MasterDependencyService::ENTITY_SOURCE, $source);
    }

    public function deactivate(SourceLead $source, ?int $userId = null): SourceLead
    {
        return $this->lifecycleService->deactivate(MasterDependencyService::ENTITY_SOURCE, $source, $userId);
    }

    public function reactivate(SourceLead $source): SourceLead
    {
        return $this->lifecycleService->reactivate(MasterDependencyService::ENTITY_SOURCE, $source);
    }
}
