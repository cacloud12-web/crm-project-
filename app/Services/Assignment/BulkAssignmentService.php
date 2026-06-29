<?php

namespace App\Services\Assignment;

use App\Models\CaMaster;
use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Services\Rbac\RbacService;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class BulkAssignmentService
{
    private const MODE_LABELS = [
        'manual' => 'Manual',
        'round_robin' => 'Auto',
        'workload_balance' => 'Auto',
        'city_match' => 'Auto',
        'state_match' => 'Auto',
    ];

    private const MODE_REASONS = [
        'manual' => 'MANUAL_ASSIGN',
        'round_robin' => 'ROUND_ROBIN',
        'workload_balance' => 'WORKLOAD_BALANCE',
        'city_match' => 'CITY_MATCH',
        'state_match' => 'STATE_MATCH',
    ];

    public function __construct(
        private readonly BulkAssignmentWriter $bulkAssignmentWriter,
        private readonly RbacService $rbacService,
    ) {}

    public function execute(array $data, bool $preview = false): array
    {
        $caIds = array_values(array_unique(array_map('intval', $data['ca_ids'])));
        $employeeIds = array_values(array_unique(array_map('intval', $data['employee_ids'])));
        $mode = $data['assignment_mode'];
        $reason = $data['reason'] ?? self::MODE_REASONS[$mode] ?? 'MANUAL_ASSIGN';
        $assignedBy = isset($data['assigned_by']) ? (int) $data['assigned_by'] : $this->resolveAssignedBy();
        $assignmentType = self::MODE_LABELS[$mode] ?? 'Manual';

        $this->validateRequest($mode, $caIds, $employeeIds);

        $leads = CaMaster::query()
            ->with(['city.state', 'state'])
            ->whereIn('ca_id', $caIds)
            ->get()
            ->keyBy('ca_id');

        $employees = Employee::query()
            ->with('city.state')
            ->whereIn('employee_id', $employeeIds)
            ->get()
            ->keyBy('employee_id');

        $missingCa = array_values(array_diff($caIds, $leads->keys()->all()));
        if ($missingCa) {
            throw new InvalidArgumentException('Invalid CA IDs: '.implode(', ', $missingCa));
        }

        $missingEmployees = array_values(array_diff($employeeIds, $employees->keys()->all()));
        if ($missingEmployees) {
            throw new InvalidArgumentException('Invalid employee IDs: '.implode(', ', $missingEmployees));
        }

        $currentOwners = $this->currentOwnersByCaIds($caIds);
        $workloads = $this->activeAssignmentCounts($employeeIds);
        $plan = [];
        $roundRobinIndex = 0;

        foreach ($caIds as $caId) {
            $lead = $leads->get($caId);
            $owner = $currentOwners->get($caId);
            $pick = $this->resolveEmployeeForLead(
                $lead,
                $employees,
                $employeeIds,
                $mode,
                $workloads,
                $roundRobinIndex,
            );

            if ($pick['employee_id'] === null) {
                $plan[] = $this->planRow(
                    $lead,
                    $owner,
                    null,
                    null,
                    $mode,
                    $assignmentType,
                    $pick['reason'] ?? $reason,
                    'failed',
                    $pick['message'] ?? 'No matching employee found',
                );

                continue;
            }

            $employee = $employees->get($pick['employee_id']);
            $isDuplicate = $owner && (int) $owner['employee_id'] === (int) $pick['employee_id'];

            $plan[] = $this->planRow(
                $lead,
                $owner,
                $pick['employee_id'],
                $employee->name,
                $mode,
                $assignmentType,
                $pick['reason'] ?? $reason,
                $isDuplicate ? 'duplicate' : ($preview ? 'preview' : 'pending'),
                $isDuplicate ? 'duplicate_assignment: lead already assigned to this executive' : null,
            );

            if (! $isDuplicate && in_array($mode, ['workload_balance', 'city_match', 'state_match'], true)) {
                $workloads[$pick['employee_id']] = ($workloads[$pick['employee_id']] ?? 0) + 1;
            }
        }

        $assigned = 0;
        $reassigned = 0;
        $duplicates = 0;
        $failed = 0;

        if ($preview) {
            foreach ($plan as $row) {
                match ($row['status']) {
                    'duplicate' => $duplicates++,
                    'failed' => $failed++,
                    default => $assigned++,
                };
            }
        } else {
            foreach ($plan as $row) {
                if ($row['status'] === 'duplicate') {
                    $duplicates++;
                } elseif ($row['status'] === 'failed') {
                    $failed++;
                }
            }

            $commitPayload = array_values(array_map(fn ($row) => [
                'ca_id' => (int) $row['ca_id'],
                'employee_id' => (int) $row['employee_id'],
                'assignment_type' => $row['assignment_type'],
                'reason' => $row['reason'],
                'assignment_mode' => $mode,
            ], array_filter($plan, fn ($row) => $row['status'] === 'pending' && $row['employee_id'])));

            if ($commitPayload !== []) {
                $result = $this->bulkAssignmentWriter->commit(
                    $commitPayload,
                    $assignedBy,
                    $mode,
                );

                $assigned = $result['assigned'];
                $reassigned = $result['reassigned'];
                $duplicates += $result['duplicate'];

                foreach ($plan as $index => $row) {
                    if ($row['status'] !== 'pending') {
                        continue;
                    }
                    $owner = $currentOwners->get($row['ca_id']);
                    $plan[$index]['status'] = $owner ? 'reassigned' : 'assigned';
                }
            }
        }

        return [
            'preview' => $preview,
            'assignment_mode' => $mode,
            'total_leads' => count($caIds),
            'assigned_rows' => $assigned,
            'reassigned_rows' => $reassigned,
            'duplicate_rows' => $duplicates,
            'failed_rows' => $failed,
            'assignments' => $plan,
        ];
    }

    private function validateRequest(string $mode, array $caIds, array $employeeIds): void
    {
        if ($caIds === []) {
            throw new InvalidArgumentException('Select at least one lead.');
        }

        if ($employeeIds === []) {
            throw new InvalidArgumentException('Select at least one employee.');
        }

        if ($mode === 'manual' && count($employeeIds) !== 1) {
            throw new InvalidArgumentException('Manual assignment requires exactly one employee.');
        }

        $inactive = Employee::query()
            ->whereIn('employee_id', $employeeIds)
            ->where(function ($q) {
                $q->where('status', '!=', 'Active')
                    ->orWhereRaw("LOWER(status) = 'on leave'");
            })
            ->pluck('name')
            ->all();

        if ($inactive) {
            throw new InvalidArgumentException('Cannot assign to inactive or on-leave employees: '.implode(', ', $inactive));
        }
    }

    private function planRow(
        ?CaMaster $lead,
        ?array $owner,
        ?int $employeeId,
        ?string $employeeName,
        string $mode,
        string $assignmentType,
        string $reason,
        string $status,
        ?string $message,
    ): array {
        return [
            'ca_id' => $lead?->ca_id,
            'firm_name' => $lead?->firm_name,
            'previous_employee_id' => $owner['employee_id'] ?? null,
            'previous_employee_name' => $owner['employee_name'] ?? 'Unassigned',
            'employee_id' => $employeeId,
            'employee_name' => $employeeName,
            'assignment_mode' => $mode,
            'assignment_type' => $assignmentType,
            'reason' => $reason,
            'status' => $status,
            'message' => $message,
        ];
    }

    private function resolveAssignedBy(): ?int
    {
        $user = auth()->user();

        return $user ? $this->rbacService->userPayload($user)['employee_id'] ?? null : null;
    }

    private function currentOwnersByCaIds(array $caIds): Collection
    {
        if ($caIds === []) {
            return collect();
        }

        return LeadAssignmentEngine::query()
            ->with('employee:employee_id,name')
            ->whereIn('ca_id', $caIds)
            ->where('status', 'Active')
            ->get()
            ->keyBy('ca_id')
            ->map(fn (LeadAssignmentEngine $row) => [
                'employee_id' => $row->employee_id,
                'employee_name' => $row->employee?->name ?? 'Unknown',
            ]);
    }

    private function resolveEmployeeForLead(
        CaMaster $lead,
        Collection $employees,
        array $employeeIds,
        string $mode,
        array &$workloads,
        int &$roundRobinIndex,
    ): array {
        return match ($mode) {
            'manual' => [
                'employee_id' => $employeeIds[0],
                'reason' => self::MODE_REASONS['manual'],
                'message' => null,
            ],
            'round_robin' => $this->pickRoundRobin($employeeIds, $roundRobinIndex),
            'workload_balance' => $this->pickWorkloadBalance($employeeIds, $workloads),
            'city_match' => $this->pickCityMatch($lead, $employees, $employeeIds, $workloads),
            'state_match' => $this->pickStateMatch($lead, $employees, $employeeIds, $workloads),
            default => ['employee_id' => $employeeIds[0], 'reason' => 'MANUAL_ASSIGN', 'message' => null],
        };
    }

    private function pickRoundRobin(array $employeeIds, int &$index): array
    {
        $employeeId = $employeeIds[$index % count($employeeIds)];
        $index++;

        return [
            'employee_id' => $employeeId,
            'reason' => self::MODE_REASONS['round_robin'],
            'message' => null,
        ];
    }

    private function pickWorkloadBalance(array $employeeIds, array $workloads): array
    {
        $sorted = $employeeIds;
        usort($sorted, fn ($a, $b) => ($workloads[$a] ?? 0) <=> ($workloads[$b] ?? 0));

        $picked = $sorted[0];

        return [
            'employee_id' => $picked,
            'reason' => self::MODE_REASONS['workload_balance'],
            'message' => null,
        ];
    }

    private function pickCityMatch(CaMaster $lead, Collection $employees, array $employeeIds, array &$workloads): array
    {
        $leadCityId = $lead->city_id;
        if (! $leadCityId) {
            return $this->pickWorkloadBalance($employeeIds, $workloads);
        }

        $matches = array_values(array_filter($employeeIds, function ($id) use ($employees, $leadCityId) {
            return (int) ($employees->get($id)?->city_id) === (int) $leadCityId;
        }));

        if (! $matches) {
            return [
                'employee_id' => null,
                'reason' => self::MODE_REASONS['city_match'],
                'message' => 'mapping_error: no employee found for lead city',
            ];
        }

        return $this->pickWorkloadBalance($matches, $workloads);
    }

    private function pickStateMatch(CaMaster $lead, Collection $employees, array $employeeIds, array &$workloads): array
    {
        $leadStateId = $lead->state_id ?: $lead->city?->state_id;
        if (! $leadStateId) {
            return $this->pickWorkloadBalance($employeeIds, $workloads);
        }

        $matches = array_values(array_filter($employeeIds, function ($id) use ($employees, $leadStateId) {
            $employeeStateId = $employees->get($id)?->city?->state_id;

            return $employeeStateId && (int) $employeeStateId === (int) $leadStateId;
        }));

        if (! $matches) {
            return [
                'employee_id' => null,
                'reason' => self::MODE_REASONS['state_match'],
                'message' => 'mapping_error: no employee found for lead state',
            ];
        }

        return $this->pickWorkloadBalance($matches, $workloads);
    }

    private function activeAssignmentCounts(array $employeeIds): array
    {
        return LeadAssignmentEngine::query()
            ->selectRaw('employee_id, COUNT(*) as total')
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'Active')
            ->groupBy('employee_id')
            ->pluck('total', 'employee_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}
