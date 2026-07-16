<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\User;
use App\Services\Rbac\RbacService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EmployeeAttendanceService
{
    public function __construct(
        private readonly RbacService $rbacService,
    ) {}

    public function assertCanManage(?User $user): void
    {
        $role = $this->rbacService->roleKey($user);
        if (! in_array($role, ['super_admin', 'admin', 'manager'], true)) {
            throw new AccessDeniedHttpException('You do not have permission to manage attendance.');
        }

        if (! $this->rbacService->can($user, 'attendance', 'view')) {
            throw new AccessDeniedHttpException('You do not have permission to manage attendance.');
        }
    }

    public function assertCanMark(?User $user): void
    {
        $this->assertCanManage($user);

        if (! $this->rbacService->can($user, 'attendance', 'edit')) {
            throw new AccessDeniedHttpException('You do not have permission to mark attendance.');
        }
    }

    public function resolveDate(?string $date): Carbon
    {
        $resolved = $date
            ? Carbon::parse($date)->startOfDay()
            : now()->startOfDay();

        if ($resolved->greaterThan(now()->startOfDay())) {
            throw ValidationException::withMessages([
                'date' => ['Future attendance dates are not allowed.'],
            ]);
        }

        return $resolved;
    }

    public function summary(User $viewer, ?string $date = null): array
    {
        $this->assertCanManage($viewer);
        $day = $this->resolveDate($date);

        $employeeIds = $this->visibleEmployeesQuery($viewer)->pluck('employee_id');
        $total = $employeeIds->count();

        if ($total === 0) {
            return [
                'date' => $day->toDateString(),
                'total' => 0,
                'present' => 0,
                'absent' => 0,
                'not_marked' => 0,
            ];
        }

        $counts = EmployeeAttendance::query()
            ->whereDate('attendance_date', $day->toDateString())
            ->whereIn('employee_id', $employeeIds)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $present = (int) ($counts[EmployeeAttendance::STATUS_PRESENT] ?? 0);
        $absent = (int) ($counts[EmployeeAttendance::STATUS_ABSENT] ?? 0);
        $marked = $present + $absent;

        return [
            'date' => $day->toDateString(),
            'total' => $total,
            'present' => $present,
            'absent' => $absent,
            'not_marked' => max(0, $total - $marked),
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>, summary: array<string, mixed>}
     */
    public function list(User $viewer, array $params): array
    {
        $this->assertCanManage($viewer);
        $day = $this->resolveDate($params['date'] ?? null);
        $search = trim((string) ($params['search'] ?? ''));
        $roleFilter = trim((string) ($params['role'] ?? ''));
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($params['per_page'] ?? 25)));

        $query = $this->visibleEmployeesQuery($viewer)->with(['city']);

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('name', 'ilike', $like)
                    ->orWhere('role', 'ilike', $like)
                    ->orWhere('email_id', 'ilike', $like);
            });
        }

        if ($roleFilter !== '') {
            $query->where('role', 'ilike', '%'.$roleFilter.'%');
        }

        $total = (clone $query)->count('employee_id');
        $employees = $query
            ->orderBy('name')
            ->forPage($page, $perPage)
            ->get();

        $attendanceByEmployee = EmployeeAttendance::query()
            ->whereDate('attendance_date', $day->toDateString())
            ->whereIn('employee_id', $employees->pluck('employee_id'))
            ->get()
            ->keyBy('employee_id');

        $items = $employees->map(function (Employee $employee) use ($attendanceByEmployee) {
            /** @var EmployeeAttendance|null $row */
            $row = $attendanceByEmployee->get($employee->employee_id);

            return [
                'employee_id' => $employee->employee_id,
                'name' => $employee->name,
                'role' => $employee->role ?? 'Employee',
                'team' => $employee->role ?? '—',
                'city' => $employee->city?->city_name,
                'status' => $row?->status,
                'status_label' => $row
                    ? ucfirst((string) $row->status)
                    : 'Not Marked',
                'marked_by' => $row?->marked_by,
                'remarks' => $row?->remarks,
                'updated_at' => $row?->updated_at?->toIso8601String(),
            ];
        })->values()->all();

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) max(1, ceil(($total ?: 1) / $perPage)),
            ],
            'summary' => $this->summary($viewer, $day->toDateString()),
        ];
    }

    public function mark(User $marker, int $employeeId, string $status, ?string $date = null, ?string $remarks = null): array
    {
        $this->assertCanMark($marker);
        $day = $this->resolveDate($date);
        $status = strtolower(trim($status));

        if (! in_array($status, EmployeeAttendance::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['Attendance status must be present or absent.'],
            ]);
        }

        $this->assertEmployeeInScope($marker, $employeeId);

        $record = DB::transaction(function () use ($employeeId, $day, $status, $marker, $remarks) {
            return $this->upsertAttendance(
                $employeeId,
                $day->toDateString(),
                $status,
                $marker->id,
                $remarks,
            );
        });

        return [
            'id' => $record->id,
            'employee_id' => $record->employee_id,
            'attendance_date' => $record->attendance_date?->toDateString(),
            'status' => $record->status,
            'status_label' => ucfirst((string) $record->status),
            'marked_by' => $record->marked_by,
            'remarks' => $record->remarks,
        ];
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array{updated: int, summary: array<string, mixed>}
     */
    public function bulkMark(User $marker, array $employeeIds, string $status, ?string $date = null): array
    {
        $this->assertCanMark($marker);
        $day = $this->resolveDate($date);
        $status = strtolower(trim($status));

        if (! in_array($status, EmployeeAttendance::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['Attendance status must be present or absent.'],
            ]);
        }

        $ids = collect($employeeIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages([
                'employee_ids' => ['Select at least one employee.'],
            ]);
        }

        $allowed = $this->visibleEmployeesQuery($marker)
            ->whereIn('employee_id', $ids)
            ->pluck('employee_id');

        if ($allowed->count() !== $ids->count()) {
            throw new AccessDeniedHttpException('One or more employees are outside your attendance scope.');
        }

        $updated = 0;
        DB::transaction(function () use ($allowed, $day, $status, $marker, &$updated) {
            foreach ($allowed as $employeeId) {
                $this->upsertAttendance(
                    (int) $employeeId,
                    $day->toDateString(),
                    $status,
                    $marker->id,
                );
                $updated++;
            }
        });

        return [
            'updated' => $updated,
            'summary' => $this->summary($marker, $day->toDateString()),
        ];
    }

    private function upsertAttendance(
        int $employeeId,
        string $date,
        string $status,
        int $markedBy,
        ?string $remarks = null,
    ): EmployeeAttendance {
        // whereDate avoids SQLite datetime mismatch on unique (employee_id, attendance_date).
        $record = EmployeeAttendance::query()
            ->where('employee_id', $employeeId)
            ->whereDate('attendance_date', $date)
            ->lockForUpdate()
            ->first();

        if ($record) {
            $record->fill([
                'status' => $status,
                'marked_by' => $markedBy,
                'remarks' => $remarks,
            ])->save();

            return $record->refresh();
        }

        return EmployeeAttendance::query()->create([
            'employee_id' => $employeeId,
            'attendance_date' => $date,
            'status' => $status,
            'marked_by' => $markedBy,
            'remarks' => $remarks,
        ]);
    }

    public function visibleEmployeesQuery(User $viewer): Builder
    {
        $query = Employee::query()
            ->where('status', 'Active')
            ->whereNull('deleted_at');

        $role = $this->rbacService->roleKey($viewer);
        if ($role === 'manager') {
            $query->where(function ($q) {
                $q->whereNull('role')
                    ->orWhere('role', 'ilike', '%executive%')
                    ->orWhere('role', 'ilike', '%employee%')
                    ->orWhere('role', 'ilike', '%sales%');
            });
        }

        return $query;
    }

    private function assertEmployeeInScope(User $viewer, int $employeeId): void
    {
        $exists = $this->visibleEmployeesQuery($viewer)
            ->where('employee_id', $employeeId)
            ->exists();

        if (! $exists) {
            // Distinguish missing vs out-of-scope for clearer API responses.
            $any = Employee::query()->where('employee_id', $employeeId)->exists();
            if (! $any) {
                throw new NotFoundHttpException('Employee not found.');
            }

            throw new AccessDeniedHttpException('You do not have access to mark attendance for this employee.');
        }
    }
}
