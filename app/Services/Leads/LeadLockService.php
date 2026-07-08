<?php

namespace App\Services\Leads;

use App\Exceptions\LeadLockedException;
use App\Models\CaMaster;
use App\Models\User;
use App\Services\Rbac\EmployeeDataScopeService;

class LeadLockService
{
    public function __construct(
        private readonly EmployeeDataScopeService $employeeDataScope,
    ) {}

    public function ttlMinutes(): int
    {
        return (int) config('crm_leads.lock_ttl_minutes', 10);
    }

    public function bypassesLock(?User $user): bool
    {
        return in_array($user?->crm_role, ['admin', 'super_admin', 'manager'], true);
    }

    public function expireIfStale(CaMaster $lead): CaMaster
    {
        if (! $lead->locked_at || ! $lead->locked_by) {
            return $lead;
        }

        if ($lead->locked_at->gte(now()->subMinutes($this->ttlMinutes()))) {
            return $lead;
        }

        $this->release($lead, null, true);

        return $lead->fresh(['lockedByEmployee']) ?? $lead;
    }

    /**
     * @return array<string, mixed>
     */
    public function lockInfo(CaMaster $lead, ?User $user = null): array
    {
        $lead = $this->expireIfStale($lead);
        $lead->loadMissing('lockedByEmployee');

        $currentEmployeeId = $this->employeeDataScope->resolveEmployeeId($user);
        $lockedBy = $lead->locked_by ? (int) $lead->locked_by : null;

        return [
            'locked_by' => $lockedBy,
            'locked_at' => $lead->locked_at,
            'locked_by_name' => $lead->lockedByEmployee?->name,
            'is_locked' => $lockedBy !== null,
            'is_locked_by_me' => $lockedBy !== null && $currentEmployeeId && $lockedBy === $currentEmployeeId,
            'is_locked_by_other' => $lockedBy !== null && (
                ! $currentEmployeeId || $lockedBy !== $currentEmployeeId
            ) && ! $this->bypassesLock($user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function acquire(CaMaster $lead, User $user): array
    {
        if ($this->bypassesLock($user)) {
            return ['acquired' => true, 'admin_bypass' => true];
        }

        if ($user->crm_role !== 'employee') {
            return ['acquired' => true];
        }

        $lead = $this->expireIfStale($lead);
        $employeeId = $this->employeeDataScope->resolveEmployeeId($user);

        if (! $employeeId) {
            throw new LeadLockedException('Employee profile is required to edit leads.');
        }

        if ($lead->locked_by && (int) $lead->locked_by !== $employeeId) {
            $lead->loadMissing('lockedByEmployee');
            $name = $lead->lockedByEmployee?->name ?? 'another employee';
            $info = $this->lockInfo($lead, $user);

            throw new LeadLockedException(
                "This lead is currently being edited by {$name}. Please try again later.",
                $info,
            );
        }

        $lead->update([
            'locked_by' => $employeeId,
            'locked_at' => now(),
        ]);

        return ['acquired' => true];
    }

    public function release(CaMaster $lead, ?User $user = null, bool $force = false): void
    {
        if (! $lead->locked_by) {
            return;
        }

        if ($force || $this->bypassesLock($user)) {
            $lead->update(['locked_by' => null, 'locked_at' => null]);

            return;
        }

        $employeeId = $this->employeeDataScope->resolveEmployeeId($user);
        if ($employeeId && (int) $lead->locked_by === $employeeId) {
            $lead->update(['locked_by' => null, 'locked_at' => null]);
        }
    }

    public function assertCanMutate(CaMaster $lead, ?User $user): void
    {
        if ($this->bypassesLock($user)) {
            return;
        }

        if ($user?->crm_role !== 'employee') {
            return;
        }

        $lead = $this->expireIfStale($lead);

        if (! $lead->locked_by) {
            return;
        }

        $employeeId = $this->employeeDataScope->resolveEmployeeId($user);

        if ($employeeId && (int) $lead->locked_by === $employeeId) {
            return;
        }

        $lead->loadMissing('lockedByEmployee');
        $name = $lead->lockedByEmployee?->name ?? 'another employee';

        throw new LeadLockedException(
            'This lead is currently being edited by '.$name.'.',
            $this->lockInfo($lead, $user),
        );
    }
}
