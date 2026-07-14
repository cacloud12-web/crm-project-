<?php

namespace App\Services\Presence;

use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

class EmployeePresenceService
{
    private ?bool $hasLastSeenColumn = null;

    public function onlineWindowMinutes(): int
    {
        return max(1, (int) config('crm_presence.online_window_minutes', 5));
    }

    public function onlineThreshold(): CarbonInterface
    {
        return now()->subMinutes($this->onlineWindowMinutes());
    }

    public function hasLastSeenColumn(): bool
    {
        if ($this->hasLastSeenColumn !== null) {
            return $this->hasLastSeenColumn;
        }

        try {
            $this->hasLastSeenColumn = Schema::hasTable('users')
                && Schema::hasColumn('users', 'last_seen_at');
        } catch (Throwable) {
            $this->hasLastSeenColumn = false;
        }

        return $this->hasLastSeenColumn;
    }

    /**
     * Eager-load relation columns that are safe for the current schema.
     *
     * @return array<int, string>
     */
    public function employeeUserWith(): array
    {
        return $this->hasLastSeenColumn()
            ? ['user:id,last_seen_at']
            : ['user:id'];
    }

    public function isOnline(mixed $lastSeenAt): bool
    {
        if (! $this->hasLastSeenColumn() || ! $lastSeenAt) {
            return false;
        }

        try {
            $seen = $lastSeenAt instanceof CarbonInterface
                ? $lastSeenAt
                : Carbon::parse($lastSeenAt);
        } catch (Throwable) {
            return false;
        }

        return $seen->greaterThanOrEqualTo($this->onlineThreshold());
    }

    /**
     * @return array{is_online: bool, last_seen_at: string|null, last_seen_human: string|null}
     */
    public function payloadFromLastSeen(mixed $lastSeenAt): array
    {
        $online = $this->isOnline($lastSeenAt);
        $iso = null;

        if ($lastSeenAt && $this->hasLastSeenColumn()) {
            try {
                $seen = $lastSeenAt instanceof CarbonInterface
                    ? $lastSeenAt
                    : Carbon::parse($lastSeenAt);
                $iso = $seen->toIso8601String();
            } catch (Throwable) {
                $iso = null;
            }
        }

        return [
            'is_online' => $online,
            'last_seen_at' => $iso,
            'last_seen_human' => $online ? 'Present' : 'Absent',
        ];
    }

    /**
     * @return array{is_online: bool, last_seen_at: string|null, last_seen_human: string|null}
     */
    public function payloadForUser(?User $user): array
    {
        if (! $user || ! $this->hasLastSeenColumn()) {
            return $this->payloadFromLastSeen(null);
        }

        return $this->payloadFromLastSeen($user->last_seen_at);
    }

    /**
     * @return array{is_online: bool, last_seen_at: string|null, last_seen_human: string|null}
     */
    public function payloadForEmployee(?Employee $employee): array
    {
        return $this->payloadForUser($employee?->user);
    }

    public function touch(User $user): void
    {
        if (! $this->hasLastSeenColumn()) {
            return;
        }

        $user->forceFill(['last_seen_at' => now()])->save();
    }

    /**
     * Mark the user offline for presence (does not affect auth session).
     */
    public function markOffline(User $user): void
    {
        if (! $this->hasLastSeenColumn()) {
            return;
        }

        $user->forceFill(['last_seen_at' => null])->save();
    }

    public function touchSafely(?User $user): void
    {
        if (! $user) {
            return;
        }

        try {
            $this->touch($user);
        } catch (Throwable) {
            // Presence must never break auth/navigation flows.
        }
    }

    public function markOfflineSafely(?User $user): void
    {
        if (! $user) {
            return;
        }

        try {
            $this->markOffline($user);
        } catch (Throwable) {
            // Presence must never break logout.
        }
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return array<int, array{is_online: bool, last_seen_at: string|null, last_seen_human: string|null}>
     */
    public function payloadsByEmployeeId(Collection $employees): array
    {
        $map = [];

        foreach ($employees as $employee) {
            $map[(int) $employee->employee_id] = $this->payloadForEmployee($employee);
        }

        return $map;
    }
}
