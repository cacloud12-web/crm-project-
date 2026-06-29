<?php

namespace App\Services\Employee;

use App\Models\Employee;
use App\Services\Activity\ActivityLogService;
use App\Services\Concerns\SearchesListings;
use App\Services\Master\LookupResolverService;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Collection;

class EmployeeService
{
    use SearchesListings;

    public function __construct(
        private readonly LookupResolverService $lookupResolver,
        private readonly ActivityLogService $activityLogService,
        private readonly NotificationService $notificationService,
        private readonly EmployeeCredentialService $credentialService,
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

        $this->credentialService->deactivateLogin($employee);

        $this->activityLogService->log(
            'EMPLOYEE_MASTER',
            'Delete Employee',
            (string) $employee->employee_id,
            $employee->name,
            beforeValue: $before,
        );

        $employee->delete();
    }

    private function normalize(array $data): array
    {
        $stateId = $this->lookupResolver->resolveStateId($data['state_id'] ?? null);

        return [
            'name' => $data['name'],
            'email_id' => $data['email_id'],
            'mobile_no' => $data['mobile_no'] ?? null,
            'city_id' => $this->lookupResolver->resolveCityId($data['city_id'] ?? null, $stateId),
            'role' => $data['role'] ?? 'Sales Executive',
            'date_of_joining' => $data['date_of_joining'] ?? null,
            'status' => $data['status'] ?? 'Active',
        ];
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
        ];
    }
}
