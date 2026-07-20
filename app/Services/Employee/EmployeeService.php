<?php

namespace App\Services\Employee;

use App\Models\Employee;
use App\Services\Activity\ActivityLogService;
use App\Services\Cache\CrmCacheService;
use App\Services\Concerns\SearchesListings;
use App\Services\Master\LookupResolverService;
use App\Services\Notifications\NotificationService;
use App\Services\User\UserLifecycleService;
use Illuminate\Support\Collection;

class EmployeeService
{
    use SearchesListings;

    public function __construct(
        private readonly LookupResolverService $lookupResolver,
        private readonly ActivityLogService $activityLogService,
        private readonly NotificationService $notificationService,
        private readonly EmployeeCredentialService $credentialService,
        private readonly UserLifecycleService $userLifecycleService,
        private readonly CrmCacheService $cacheService,
    ) {}

    public function search(array $params = []): array
    {
        return $this->searchListing(
            Employee::query()->with(['city', 'user']),
            $params,
            'employees',
        );
    }

    public function list(): Collection
    {
        return $this->listAllFromSearch(
            Employee::query()->with(['city', 'user']),
            [],
            'employees',
        );
    }

    public function find(int|string $id): Employee
    {
        return Employee::query()
            ->with(['city', 'user'])
            ->findOrFail($id);
    }

    public function create(array $data): Employee
    {
        $credentialData = [
            'password' => $data['password'],
            'crm_role' => $data['crm_role'] ?? 'employee',
        ];

        $employee = Employee::create($this->normalize($data));

        $this->credentialService->createLoginForEmployee(
            $employee,
            $credentialData['password'],
            $credentialData['crm_role'],
        );

        $this->activityLogService->log(
            'EMPLOYEE_MASTER',
            'Add Employee',
            (string) $employee->employee_id,
            $employee->name,
        );

        $this->notificationService->newEmployee($employee->name, $employee->role ?? 'Sales Executive');

        return $employee->fresh(['city', 'user']);
    }

    public function update(Employee $employee, array $data): Employee
    {
        $before = $this->auditSnapshot($employee);
        $previousEmail = $employee->email_id;
        $employee->update($this->normalize($data));
        $employee = $employee->fresh(['city', 'user']);

        $this->credentialService->syncUserFromEmployee($employee, $previousEmail);

        if (array_key_exists('crm_role', $data) && $employee->user) {
            $this->credentialService->syncCrmRoleForEmployee($employee, (string) $data['crm_role'], auth()->user());
        }

        $this->activityLogService->log(
            'EMPLOYEE_MASTER',
            'Update Employee',
            (string) $employee->employee_id,
            $employee->name,
            beforeValue: $before,
            afterValue: $this->auditSnapshot($employee),
        );

        return $employee;
    }

    public function delete(Employee $employee): void
    {
        $before = $this->auditSnapshot($employee);
        $user = $employee->user;

        if ($user) {
            $this->userLifecycleService->assertCanDeleteUser($user);
        }

        if ($user) {
            $this->credentialService->removeLoginForEmployee($employee);
        }

        $this->activityLogService->log(
            'EMPLOYEE_MASTER',
            'Delete Employee',
            (string) $employee->employee_id,
            $employee->name,
            beforeValue: $before,
        );

        $employeeId = (int) $employee->employee_id;
        $employee->delete();
        $this->cacheService->forgetDashboardMetrics();
        $this->cacheService->forgetEmployeeDashboard($employeeId);
        $this->cacheService->forgetEmployeeRankings();
    }

    private function normalize(array $data): array
    {
        $stateId = $this->lookupResolver->resolveStateId($data['state_id'] ?? null);

        $payload = [
            'name' => $data['name'],
            'email_id' => $data['email_id'],
            'mobile_no' => $data['mobile_no'] ?? null,
            'city_id' => $this->lookupResolver->resolveCityId($data['city_id'] ?? null, $stateId),
            'role' => $data['role'] ?? 'Sales Executive',
            'date_of_joining' => $data['date_of_joining'] ?? null,
            'status' => $data['status'] ?? 'Active',
        ];

        if (array_key_exists('work_type', $data)) {
            $payload['work_type'] = $data['work_type'] ?: 'calling';
            $payload['demo_meeting_link'] = $data['demo_meeting_link'] ?? null;
            $payload['demo_min_team_size'] = isset($data['demo_min_team_size']) && $data['demo_min_team_size'] !== ''
                ? (int) $data['demo_min_team_size']
                : null;
            $payload['demo_max_team_size'] = isset($data['demo_max_team_size']) && $data['demo_max_team_size'] !== ''
                ? (int) $data['demo_max_team_size']
                : null;
            $payload['active_for_demo'] = (bool) ($data['active_for_demo'] ?? false);
        }

        return $payload;
    }

    private function auditSnapshot(Employee $employee): array
    {
        return [
            'employee_id' => $employee->employee_id,
            'name' => $employee->name,
            'email_id' => $employee->email_id,
            'mobile_no' => $employee->mobile_no,
            'role' => $employee->role,
            'status' => $employee->status,
            'city_id' => $employee->city_id,
            'user_id' => $employee->user_id,
            'work_type' => $employee->work_type,
            'active_for_demo' => (bool) $employee->active_for_demo,
            'demo_min_team_size' => $employee->demo_min_team_size,
            'demo_max_team_size' => $employee->demo_max_team_size,
        ];
    }
}
