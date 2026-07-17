<?php

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\ProvisionEmployeeLoginsRequest;
use App\Http\Requests\Employee\ResetEmployeePasswordRequest;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Services\Demo\DemoProviderEligibilityService;
use App\Services\Employee\EmployeeCredentialService;
use App\Services\Employee\EmployeeService;
use App\Support\ApiResponse;
use App\Support\Listing\ListingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeService $employeeService,
        private readonly EmployeeCredentialService $credentialService,
        private readonly DemoProviderEligibilityService $demoProviderEligibility,
    ) {}

    public function demoProviders(Request $request): JsonResponse
    {
        $teamSize = (int) $request->query('team_size', 0);
        if ($teamSize < 1) {
            return ApiResponse::success([], 'Provide a team_size query parameter of 1 or greater.');
        }

        return ApiResponse::success(
            $this->demoProviderEligibility->optionsForTeamSize($teamSize),
            'Eligible demo providers loaded',
        );
    }

    public function index(Request $request): JsonResponse
    {
        $result = $this->employeeService->search($request->query());

        return ListingResponse::from($result, EmployeeResource::class, 'Employees loaded');
    }

    public function create()
    {
        return redirect('/');
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = $this->employeeService->create($request->validated());

        return ApiResponse::created(
            new EmployeeResource($employee),
            'Employee added successfully',
        );
    }

    public function show(string $id): JsonResponse
    {
        $employee = $this->employeeService->find($id);

        return ApiResponse::success(new EmployeeResource($employee));
    }

    public function edit(string $id)
    {
        return redirect('/');
    }

    public function update(UpdateEmployeeRequest $request, string $id): JsonResponse
    {
        $employee = $this->employeeService->update(
            Employee::findOrFail($id),
            $request->validated(),
        );

        return ApiResponse::success(
            new EmployeeResource($employee),
            'Employee updated successfully',
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $this->employeeService->delete(Employee::findOrFail($id));

        return ApiResponse::success(null, 'Employee deleted successfully');
    }

    public function resetPassword(ResetEmployeePasswordRequest $request, string $id): JsonResponse
    {
        $employee = Employee::query()->with('user')->findOrFail($id);

        $this->credentialService->resetEmployeePassword(
            $request->user(),
            $employee,
            $request->validated('password'),
        );

        return ApiResponse::success(null, 'Employee password reset successfully.');
    }

    public function provisionLogins(ProvisionEmployeeLoginsRequest $request): JsonResponse
    {
        $stats = $this->credentialService->provisionMissingLogins(
            $request->user(),
            $request->validated('default_password'),
        );

        return ApiResponse::success($stats, 'Employee login provisioning completed.');
    }
}
