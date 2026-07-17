<?php

namespace App\Services\Demo;

use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Finds active employee demo providers whose team-size range covers a lead.
 */
class DemoProviderEligibilityService
{
    public const WORK_CALLING = 'calling';

    public const WORK_DEMO_PROVIDER = 'demo_provider';

    public const WORK_BOTH = 'both';

    /**
     * @return Collection<int, Employee>
     */
    public function eligibleForTeamSize(int $teamSize): Collection
    {
        if ($teamSize < 1) {
            return collect();
        }

        return Employee::query()
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->where('active_for_demo', true)
            ->whereIn('work_type', [self::WORK_DEMO_PROVIDER, self::WORK_BOTH])
            ->whereNotNull('demo_min_team_size')
            ->whereNotNull('demo_max_team_size')
            ->where('demo_min_team_size', '<=', $teamSize)
            ->where('demo_max_team_size', '>=', $teamSize)
            ->orderBy('name')
            ->get([
                'employee_id',
                'name',
                'email_id',
                'work_type',
                'demo_meeting_link',
                'demo_min_team_size',
                'demo_max_team_size',
                'active_for_demo',
                'status',
            ]);
    }

    public function findEligible(int $employeeId, int $teamSize): ?Employee
    {
        return $this->eligibleForTeamSize($teamSize)
            ->first(fn (Employee $e) => (int) $e->employee_id === $employeeId);
    }

    public function isDemoCapableWorkType(?string $workType): bool
    {
        return in_array($workType, [self::WORK_DEMO_PROVIDER, self::WORK_BOTH], true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function optionsForTeamSize(int $teamSize): array
    {
        return $this->eligibleForTeamSize($teamSize)
            ->map(fn (Employee $e) => [
                'employee_id' => (int) $e->employee_id,
                'name' => $e->name,
                'label' => sprintf(
                    '%s — %d to %d',
                    $e->name,
                    (int) $e->demo_min_team_size,
                    (int) $e->demo_max_team_size,
                ),
                'demo_meeting_link' => $e->demo_meeting_link,
                'demo_min_team_size' => (int) $e->demo_min_team_size,
                'demo_max_team_size' => (int) $e->demo_max_team_size,
                'work_type' => $e->work_type,
            ])
            ->values()
            ->all();
    }
}
