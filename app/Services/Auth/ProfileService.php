<?php

namespace App\Services\Auth;

use App\Models\Employee;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
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
        $employee = Employee::query()
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhere('email_id', $user->email);
            })
            ->first();

        DB::transaction(function () use ($user, $data, $employee) {
            $userUpdate = ['name' => $data['name']];

            $user->update($userUpdate);

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
}
