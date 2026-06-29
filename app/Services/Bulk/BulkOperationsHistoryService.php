<?php

namespace App\Services\Bulk;

use App\Models\BulkAction;
use App\Services\Concerns\SearchesListings;
use Illuminate\Support\Collection;

class BulkOperationsHistoryService
{
    use SearchesListings;

    public function search(array $params = []): array
    {
        $query = BulkAction::query()
            ->whereIn('action_type', ['ca_master_import', 'ca_master_export', 'ca_master_status_update']);

        return $this->searchListing($query, $params, 'bulk_operations');
    }

    public function list(int $limit = 50): Collection
    {
        $params = ['per_page' => min($limit, 100)];

        return collect($this->search($params)['items']);
    }
}
