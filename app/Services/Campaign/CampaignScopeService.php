<?php

namespace App\Services\Campaign;

use App\Models\EmailCampaign;
use App\Models\Employee;
use App\Models\SmsCampaign;
use App\Models\User;
use App\Models\WhatsAppCampaign;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Services\Rbac\RbacService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class CampaignScopeService
{
    public function __construct(
        private readonly RbacService $rbacService,
        private readonly EmployeeDataScopeService $employeeDataScope,
    ) {}

    /**
     * @return list<int>|null null = unrestricted
     */
    public function allowedCreatorUserIds(?User $user): ?array
    {
        if (! $user) {
            return [];
        }

        $role = $this->rbacService->roleKey($user);

        if (in_array($role, ['super_admin', 'admin'], true)) {
            return null;
        }

        if ($role === 'employee') {
            return [(int) $user->id];
        }

        if ($role === 'manager') {
            $employeeUserIds = Employee::query()
                ->whereNotNull('user_id')
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            return array_values(array_unique(array_merge([(int) $user->id], $employeeUserIds)));
        }

        return [(int) $user->id];
    }

    public function applyCreatorScope(Builder $query, ?User $user, string $table): Builder
    {
        $allowed = $this->allowedCreatorUserIds($user);

        if ($allowed === null) {
            return $query;
        }

        if ($allowed === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($table.'.created_by_user_id', $allowed);
    }

    public function ensureCanAccessCampaign(string $channel, int|string $id): Model
    {
        $campaign = $this->resolveCampaign($channel, $id);
        $user = auth()->user();

        if ($user === null) {
            abort(401);
        }

        $allowed = $this->allowedCreatorUserIds($user);

        if ($allowed !== null) {
            $creatorId = (int) ($campaign->created_by_user_id ?? 0);
            if ($creatorId <= 0 || ! in_array($creatorId, $allowed, true)) {
                abort(403, 'You do not have access to this campaign.');
            }
        }

        if ($this->employeeDataScope->shouldScopeToEmployee($user)) {
            if ($campaign->audience_mode === 'all_leads') {
                abort(403, 'Employees cannot access campaigns sent to all leads.');
            }
        }

        return $campaign;
    }

    public function ensureCanMutateCampaign(string $channel, int|string $id, string $action): Model
    {
        $campaign = $this->ensureCanAccessCampaign($channel, $id);
        $role = $this->rbacService->roleKey(auth()->user());

        if (in_array($action, ['delete', 'export'], true) && ! in_array($role, ['super_admin', 'admin', 'manager'], true)) {
            if ($action === 'delete' && ! in_array($role, ['super_admin', 'admin'], true)) {
                abort(403, 'You do not have permission to delete campaigns.');
            }
        }

        if (in_array($campaign->status, ['Cancelled'], true) && ! in_array($action, ['view', 'export', 'delete'], true)) {
            throw new InvalidArgumentException('Cancelled campaigns cannot be modified.');
        }

        return $campaign;
    }

    public function resolveCampaign(string $channel, int|string $id): Model
    {
        return match (strtolower($channel)) {
            'email' => EmailCampaign::query()->findOrFail($id),
            'sms' => SmsCampaign::query()->findOrFail($id),
            'whatsapp' => WhatsAppCampaign::query()->findOrFail($id),
            default => throw new InvalidArgumentException('Unsupported campaign channel.'),
        };
    }
}
