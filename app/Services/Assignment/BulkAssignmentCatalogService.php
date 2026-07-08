<?php

namespace App\Services\Assignment;

use App\Models\BulkAction;
use App\Models\CaMaster;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\LeadAssignmentEngine;
use App\Support\Database\SqlAggregate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BulkAssignmentCatalogService
{
    private const OPEN_FOLLOWUP = ['Pending', 'Scheduled', 'Open'];

    private const WORKLOAD_CAP = 50;

    public function listLeads(array $params): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($params['per_page'] ?? 25)));
        $search = trim((string) ($params['search'] ?? ''));
        $searchField = (string) ($params['search_field'] ?? 'all');

        $query = $this->filteredLeadsQuery($params, $search, $searchField);

        $total = (clone $query)->count('ca_masters.ca_id');

        $leads = $query
            ->orderByDesc('ca_masters.updated_at')
            ->forPage($page, $perPage)
            ->get();

        $caIds = $leads->pluck('ca_id')->all();
        $assignments = $this->activeAssignmentsByCaIds($caIds);

        $items = $leads->map(function (CaMaster $lead) use ($assignments) {
            $assignment = $assignments->get($lead->ca_id);

            return [
                'ca_id' => $lead->ca_id,
                'firm_name' => $lead->firm_name,
                'ca_name' => $lead->ca_name,
                'mobile_no' => $lead->mobile_no,
                'city' => $lead->city?->city_name,
                'state' => $lead->state?->state_name ?? $lead->city?->state?->state_name,
                'city_id' => $lead->city_id,
                'state_id' => $lead->state_id ?: $lead->city?->state_id,
                'status' => $lead->status,
                'rating' => $lead->rating,
                'source' => $lead->sourceLead?->source_name,
                'source_id' => $lead->source_id,
                'is_assigned' => $assignment !== null,
                'current_employee_id' => $assignment?->employee_id,
                'current_executive' => $assignment?->employee_name,
                'assigned_date' => $assignment?->assigned_date,
            ];
        })->values()->all();

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
        ];
    }

    public function listLeadIds(array $params): array
    {
        $search = trim((string) ($params['search'] ?? ''));
        $searchField = (string) ($params['search_field'] ?? 'all');
        $max = (int) config('listing.max_all', 5000);
        $max = min(max(1, $max), 5000);

        $query = $this->filteredLeadsQuery($params, $search, $searchField);
        $total = (clone $query)->count('ca_masters.ca_id');

        $caIds = $query
            ->orderByDesc('ca_masters.updated_at')
            ->limit($max)
            ->pluck('ca_masters.ca_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        return [
            'ca_ids' => $caIds,
            'total' => $total,
            'returned' => count($caIds),
            'truncated' => $total > count($caIds),
            'max' => $max,
        ];
    }

    public function listBatches(array $params): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(50, max(5, (int) ($params['per_page'] ?? 10)));

        $query = BulkAction::query()
            ->where('action_type', 'ca_master_import')
            ->whereIn('status', ['Completed', 'Completed with errors'])
            ->whereHas('importedLeads');

        $this->applyBatchListFilters($query, $params);

        $total = (clone $query)->count('bulk_action_id');

        $batches = $query
            ->orderByDesc('bulk_action_id')
            ->forPage($page, $perPage)
            ->get();

        $batchIds = $batches->pluck('bulk_action_id')->all();
        $stats = $this->batchLeadStats($batchIds, $params);

        $items = $batches->map(function (BulkAction $batch) use ($stats) {
            $id = (int) $batch->bulk_action_id;
            $stat = $stats[$id] ?? [
                'total_leads' => 0,
                'assigned_leads' => 0,
                'unassigned_leads' => 0,
                'matching_leads' => 0,
            ];
            $importedAt = $batch->completed_at ?? $batch->created_at;

            return [
                'bulk_action_id' => $id,
                'batch_name' => $batch->file_name ?: ('Import #'.$id),
                'file_name' => $batch->file_name,
                'total_leads' => $stat['total_leads'],
                'assigned_leads' => $stat['assigned_leads'],
                'unassigned_leads' => $stat['unassigned_leads'],
                'matching_leads' => $stat['matching_leads'],
                'source' => 'Bulk Import',
                'imported_by' => $batch->imported_by,
                'imported_at' => $importedAt?->toIso8601String(),
                'imported_at_label' => $importedAt?->format('d M Y, H:i'),
                'status' => $batch->status,
            ];
        })->values()->all();

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
        ];
    }

    public function resolveBatchLeadIds(int $bulkActionId, array $params = []): array
    {
        $batch = BulkAction::query()
            ->where('action_type', 'ca_master_import')
            ->where('bulk_action_id', $bulkActionId)
            ->first();

        if (! $batch) {
            throw new InvalidArgumentException('Invalid import batch.');
        }

        $max = min(5000, max(1, (int) config('listing.max_all', 5000)));

        return $this->batchLeadsQuery($bulkActionId, $params)
            ->orderBy('ca_masters.ca_id')
            ->limit($max)
            ->pluck('ca_masters.ca_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function batchLeadsQuery(int $bulkActionId, array $params): Builder
    {
        $query = CaMaster::query()
            ->select('ca_masters.*')
            ->where('ca_masters.bulk_action_id', $bulkActionId);

        $this->applyLeadFilters($query, $params);

        return $query;
    }

    private function applyBatchListFilters(Builder $query, array $params): void
    {
        $stateId = (int) ($params['state_id'] ?? 0);
        $cityId = (int) ($params['city_id'] ?? 0);
        $sourceId = (int) ($params['source_id'] ?? 0);

        if ($stateId > 0 || $cityId > 0 || $sourceId > 0) {
            $query->whereHas('importedLeads', function (Builder $leadQuery) use ($stateId, $cityId, $sourceId) {
                if ($stateId > 0) {
                    $leadQuery->where(function (Builder $q) use ($stateId) {
                        $q->where('ca_masters.state_id', $stateId)
                            ->orWhereHas('city', fn (Builder $city) => $city->where('state_id', $stateId));
                    });
                }
                if ($cityId > 0) {
                    $leadQuery->where('ca_masters.city_id', $cityId);
                }
                if ($sourceId > 0) {
                    $leadQuery->where('ca_masters.source_id', $sourceId);
                }
            });
        }
    }

    private function batchLeadStats(array $batchIds, array $params): array
    {
        if ($batchIds === []) {
            return [];
        }

        $totals = DB::table('ca_masters')
            ->leftJoin('lead_assignment_engines', function ($join) {
                $join->on('ca_masters.ca_id', '=', 'lead_assignment_engines.ca_id')
                    ->where('lead_assignment_engines.status', '=', 'Active');
            })
            ->whereIn('ca_masters.bulk_action_id', $batchIds)
            ->groupBy('ca_masters.bulk_action_id')
            ->selectRaw('ca_masters.bulk_action_id')
            ->selectRaw('COUNT(ca_masters.ca_id) as total_leads')
            ->selectRaw('COUNT(lead_assignment_engines.ca_id) as assigned_leads')
            ->get()
            ->keyBy('bulk_action_id');

        $stats = [];
        foreach ($batchIds as $batchId) {
            $row = $totals->get($batchId);
            $total = (int) ($row->total_leads ?? 0);
            $assigned = (int) ($row->assigned_leads ?? 0);
            $stats[$batchId] = [
                'total_leads' => $total,
                'assigned_leads' => $assigned,
                'unassigned_leads' => max(0, $total - $assigned),
                'matching_leads' => 0,
            ];
        }

        foreach ($batchIds as $batchId) {
            $stats[$batchId]['matching_leads'] = $this->batchLeadsQuery((int) $batchId, $params)->count('ca_masters.ca_id');
        }

        return $stats;
    }

    private function filteredLeadsQuery(array $params, string $search, string $searchField): Builder
    {
        $query = CaMaster::query()
            ->with([
                'city.state',
                'state',
                'sourceLead',
            ])
            ->select('ca_masters.*');

        $this->applyLeadSearch($query, $search, $searchField);
        $this->applyLeadFilters($query, $params);

        return $query;
    }

    public function listEmployees(array $params): array
    {
        $search = trim((string) ($params['search'] ?? ''));
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($params['per_page'] ?? 25)));

        $query = Employee::query()
            ->with('city.state')
            ->whereNull('deleted_at');

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('name', 'ilike', $like)
                    ->orWhere('email_id', 'ilike', $like)
                    ->orWhere('mobile_no', 'ilike', $like)
                    ->orWhere('role', 'ilike', $like);
            });
        }

        $total = (clone $query)->count('employee_id');

        $employees = $query
            ->orderBy('name')
            ->forPage($page, $perPage)
            ->get();

        $employeeIds = $employees->pluck('employee_id')->all();
        $stats = $this->employeeWorkloadStats($employeeIds);
        $today = now()->toDateString();

        $items = $employees->map(function (Employee $employee) use ($stats) {
            $row = $stats[$employee->employee_id] ?? [
                'active_leads' => 0,
                'assigned_today' => 0,
                'followups_today' => 0,
            ];
            $activeLeads = (int) $row['active_leads'];
            $workloadPct = min(100, (int) round(($activeLeads / self::WORKLOAD_CAP) * 100));

            return [
                'employee_id' => $employee->employee_id,
                'name' => $employee->name,
                'designation' => $employee->role ?? 'Employee',
                'city' => $employee->city?->city_name,
                'state' => $employee->city?->state?->state_name,
                'city_id' => $employee->city_id,
                'state_id' => $employee->city?->state_id,
                'status' => $employee->status,
                'availability' => $this->availabilityLabel($employee, $activeLeads),
                'active_leads' => $activeLeads,
                'assigned_today' => (int) $row['assigned_today'],
                'followups_today' => (int) $row['followups_today'],
                'workload_pct' => $workloadPct,
                'assignable' => $this->isAssignable($employee),
            ];
        })->values()->all();

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
        ];
    }

    private function applyLeadSearch(Builder $query, string $search, string $field): void
    {
        if ($search === '') {
            return;
        }

        $like = '%'.$search.'%';

        match ($field) {
            'firm' => $query->where('firm_name', 'ilike', $like),
            'ca_name' => $query->where('ca_name', 'ilike', $like),
            'mobile' => $query->where('mobile_no', 'ilike', $like),
            'city' => $query->whereHas('city', fn (Builder $q) => $q->where('city_name', 'ilike', $like)),
            default => $query->where(function (Builder $q) use ($like) {
                $q->where('firm_name', 'ilike', $like)
                    ->orWhere('ca_name', 'ilike', $like)
                    ->orWhere('mobile_no', 'ilike', $like)
                    ->orWhereHas('city', fn (Builder $city) => $city->where('city_name', 'ilike', $like));
            }),
        };
    }

    private function applyLeadFilters(Builder $query, array $params): void
    {
        $assignment = (string) ($params['assignment'] ?? '');
        if ($assignment === 'unassigned') {
            $query->whereDoesntHave('leadAssignments', fn (Builder $q) => $q->where('status', 'Active'));
        } elseif ($assignment === 'assigned') {
            $query->whereHas('leadAssignments', fn (Builder $q) => $q->where('status', 'Active'));
        }

        $status = trim((string) ($params['lead_status'] ?? ''));
        if ($status !== '') {
            $query->where('ca_masters.status', $status);
        }

        if (! empty($params['state_id'])) {
            $query->where(function (Builder $q) use ($params) {
                $q->where('ca_masters.state_id', (int) $params['state_id'])
                    ->orWhereHas('city', fn (Builder $city) => $city->where('state_id', (int) $params['state_id']));
            });
        }

        if (! empty($params['city_id'])) {
            $query->where('ca_masters.city_id', (int) $params['city_id']);
        }

        if (! empty($params['source_id'])) {
            $query->where('ca_masters.source_id', (int) $params['source_id']);
        }

        if (filter_var($params['follow_up_due'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $open = implode(',', array_map(fn ($s) => "'".str_replace("'", "''", $s)."'", self::OPEN_FOLLOWUP));
            $query->whereExists(function ($sub) {
                $sub->select(DB::raw('1'))
                    ->from('follow_ups')
                    ->whereColumn('follow_ups.ca_id', 'ca_masters.ca_id')
                    ->whereIn('follow_ups.status', self::OPEN_FOLLOWUP)
                    ->whereDate('follow_ups.scheduled_date', '<=', now()->toDateString());
            });
        }
    }

    private function activeAssignmentsByCaIds(array $caIds): Collection
    {
        if (! $caIds) {
            return collect();
        }

        return LeadAssignmentEngine::query()
            ->with('employee:employee_id,name')
            ->whereIn('ca_id', $caIds)
            ->where('status', 'Active')
            ->get()
            ->keyBy('ca_id')
            ->map(fn (LeadAssignmentEngine $row) => (object) [
                'employee_id' => $row->employee_id,
                'employee_name' => $row->employee?->name,
                'assigned_date' => $row->assigned_date?->toDateString(),
            ]);
    }

    private function employeeWorkloadStats(array $employeeIds): array
    {
        if (! $employeeIds) {
            return [];
        }

        $today = now()->toDateString();
        $open = implode(',', array_map(fn ($s) => "'".str_replace("'", "''", $s)."'", self::OPEN_FOLLOWUP));

        $assignmentRows = LeadAssignmentEngine::query()
            ->selectRaw('employee_id')
            ->selectRaw(SqlAggregate::countFilter('*', "status = 'Active'").' as active_leads')
            ->selectRaw(SqlAggregate::countFilter('*', "status = 'Active' AND assigned_date = ?").' as assigned_today', [$today])
            ->whereIn('employee_id', $employeeIds)
            ->groupBy('employee_id')
            ->get();

        $followupRows = FollowUp::query()
            ->selectRaw('employee_id, COUNT(*) as followups_today')
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('scheduled_date', $today)
            ->whereIn('status', self::OPEN_FOLLOWUP)
            ->groupBy('employee_id')
            ->pluck('followups_today', 'employee_id');

        $stats = [];
        foreach ($assignmentRows as $row) {
            $stats[(int) $row->employee_id] = [
                'active_leads' => (int) $row->active_leads,
                'assigned_today' => (int) $row->assigned_today,
                'followups_today' => (int) ($followupRows[(int) $row->employee_id] ?? 0),
            ];
        }

        foreach ($employeeIds as $id) {
            $stats[$id] = $stats[$id] ?? [
                'active_leads' => 0,
                'assigned_today' => 0,
                'followups_today' => (int) ($followupRows[$id] ?? 0),
            ];
        }

        return $stats;
    }

    private function availabilityLabel(Employee $employee, int $activeLeads): string
    {
        if (strcasecmp((string) $employee->status, 'On Leave') === 0) {
            return 'On Leave';
        }

        if (strcasecmp((string) $employee->status, 'Active') !== 0) {
            return 'Unavailable';
        }

        return $activeLeads >= (int) (self::WORKLOAD_CAP * 0.85) ? 'Busy' : 'Available';
    }

    private function isAssignable(Employee $employee): bool
    {
        if (strcasecmp((string) $employee->status, 'On Leave') === 0) {
            return false;
        }

        return strcasecmp((string) $employee->status, 'Active') === 0;
    }
}
