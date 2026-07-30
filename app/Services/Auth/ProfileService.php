<?php

namespace App\Services\Auth;

use App\Models\Employee;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use App\Services\Demo\DemoProviderEligibilityService;
use App\Services\Rbac\RbacService;
use Illuminate\Support\Facades\DB;

class ProfileService
{
    public function __construct(
        private readonly RbacService $rbacService,
        private readonly ActivityLogService $activityLogService,
    ) {}

    /** @return array<string, mixed> */
    public function update(User $user, array $data): array
    {
        DB::transaction(function () use ($user, $data) {
            $user->update(['name' => $data['name']]);

            $employee = $this->resolveOrCreateEmployee($user, $data);
            if (! $employee) {
                return;
            }

            $employeeData = [
                'name' => $data['name'],
            ];

            if (! empty($data['designation'])) {
                $employeeData['role'] = $data['designation'];
            }

            if (array_key_exists('mobile_no', $data)) {
                $employeeData['mobile_no'] = $data['mobile_no'];
            }

            if (array_key_exists('work_type', $data)) {
                $workType = $data['work_type'] ?: DemoProviderEligibilityService::WORK_CALLING;
                $employeeData['work_type'] = $workType;

                if (in_array($workType, [
                    DemoProviderEligibilityService::WORK_DEMO_PROVIDER,
                    DemoProviderEligibilityService::WORK_BOTH,
                ], true)) {
                    $employeeData['demo_meeting_link'] = $data['demo_meeting_link'] ?? null;
                    $employeeData['demo_min_team_size'] = isset($data['demo_min_team_size']) && $data['demo_min_team_size'] !== ''
                        ? (int) $data['demo_min_team_size']
                        : null;
                    $employeeData['demo_max_team_size'] = isset($data['demo_max_team_size']) && $data['demo_max_team_size'] !== ''
                        ? (int) $data['demo_max_team_size']
                        : null;
                    $employeeData['active_for_demo'] = (bool) ($data['active_for_demo'] ?? false);
                } else {
                    $employeeData['demo_meeting_link'] = null;
                    $employeeData['demo_min_team_size'] = null;
                    $employeeData['demo_max_team_size'] = null;
                    $employeeData['active_for_demo'] = false;
                }
            }

            $employee->update($employeeData);
        });

        $user = $user->fresh();

        $this->activityLogService->log(
            'USER_PROFILE',
            'Profile updated',
            (string) $user->id,
            $user->name,
        );

        return $this->rbacService->userPayload($user);
    }

    /**
     * Prefer existing linked employee. For Super Admin / Admin without a row,
     * create one so calling / demo-provider settings can be stored and used.
     */
    private function resolveOrCreateEmployee(User $user, array $data): ?Employee
    {
        $employee = Employee::query()
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhere('email_id', $user->email);
            })
            ->first();

        if ($employee) {
            if (! $employee->user_id) {
                $employee->user_id = $user->id;
                $employee->save();
            }

            return $employee;
        }

        $roleKey = $this->rbacService->roleKey($user);
        $needsEmployee = array_key_exists('work_type', $data)
            || in_array($roleKey, ['super_admin', 'admin'], true);

        if (! $needsEmployee) {
            return null;
        }

        $designation = ! empty($data['designation'])
            ? $data['designation']
            : ($roleKey === 'super_admin' ? 'Super Admin' : ($roleKey === 'admin' ? 'Admin' : 'Sales Executive'));

        return Employee::query()->create([
            'user_id' => $user->id,
            'name' => $data['name'] ?: $user->name,
            'email_id' => $user->email,
            'mobile_no' => $data['mobile_no'] ?? null,
            'role' => $designation,
            'status' => 'Active',
            'work_type' => $data['work_type'] ?? DemoProviderEligibilityService::WORK_CALLING,
            'demo_meeting_link' => null,
            'demo_min_team_size' => null,
            'demo_max_team_size' => null,
            'active_for_demo' => false,
        ]);
    }
}
