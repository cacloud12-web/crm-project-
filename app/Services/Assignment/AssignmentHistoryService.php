<?php

namespace App\Services\Assignment;

use App\Models\AssignmentHistory;
use App\Services\Concerns\SearchesListings;
use Illuminate\Support\Collection;

class AssignmentHistoryService
{
    use SearchesListings;

    public function search(array $params = []): array
    {
        return $this->searchListing(
            AssignmentHistory::query()->with([
                'caMaster',
                'previousEmployee',
                'newEmployee',
                'assignedByEmployee',
            ]),
            $params,
            'assignment_histories',
        );
    }

    public function list(): Collection
    {
        return $this->listAllFromSearch(
            AssignmentHistory::query()->with([
                'caMaster',
                'previousEmployee',
                'newEmployee',
                'assignedByEmployee',
            ]),
            [],
            'assignment_histories',
        );
    }
}
