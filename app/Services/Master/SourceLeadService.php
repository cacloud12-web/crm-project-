<?php

namespace App\Services\Master;

use App\Models\CaMaster;
use App\Models\SourceLead;
use App\Services\Concerns\SearchesListings;
use App\Services\Master\Concerns\LogsMasterActivity;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class SourceLeadService
{
    use LogsMasterActivity;
    use SearchesListings;

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

    public function delete(SourceLead $source): void
    {
        if (CaMaster::query()->where('source_id', $source->source_id)->exists()) {
            throw new InvalidArgumentException('Cannot delete a source that is used by CA Master records.');
        }

        $name = $source->source_name;
        $id = (string) $source->source_id;
        $source->delete();

        $this->logMasterActivity('Delete Source', 'SOURCE_OF_LEAD', $id, $name);
    }
}
