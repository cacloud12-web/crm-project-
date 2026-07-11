<?php

namespace App\Services\Assignment;

use App\Models\CompanyHoliday;
use App\Models\User;
use App\Services\Rbac\RbacService;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class CompanyHolidayService
{
    public function __construct(
        private readonly RbacService $rbacService,
        private readonly EmployeeCalendarService $calendarService,
    ) {}

    public function canEdit(?User $user = null): bool
    {
        $user ??= auth()->user();

        return in_array($this->rbacService->roleKey($user), ['super_admin', 'manager'], true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return CompanyHoliday::query()
            ->orderBy('sort_order')
            ->orderBy('month')
            ->orderBy('day')
            ->get()
            ->map(fn (CompanyHoliday $holiday) => $this->serialize($holiday))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function store(array $payload, ?User $user = null): array
    {
        $this->assertCanEdit($user);

        $holiday = CompanyHoliday::query()->create([
            'name' => $payload['name'],
            'month' => (int) $payload['month'],
            'day' => (int) $payload['day'],
            'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
            'sort_order' => (int) ($payload['sort_order'] ?? ((int) CompanyHoliday::query()->max('sort_order') + 1)),
        ]);

        $this->calendarService->regenerateAllEmployeesForYear((int) now()->year);

        return $this->serialize($holiday);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(CompanyHoliday $holiday, array $payload, ?User $user = null): array
    {
        $this->assertCanEdit($user);

        $holiday->update([
            'name' => $payload['name'] ?? $holiday->name,
            'month' => array_key_exists('month', $payload) ? (int) $payload['month'] : $holiday->month,
            'day' => array_key_exists('day', $payload) ? (int) $payload['day'] : $holiday->day,
            'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : $holiday->is_active,
            'sort_order' => array_key_exists('sort_order', $payload) ? (int) $payload['sort_order'] : $holiday->sort_order,
        ]);

        $this->calendarService->regenerateAllEmployeesForYear((int) now()->year);

        return $this->serialize($holiday->fresh());
    }

    public function destroy(CompanyHoliday $holiday, ?User $user = null): void
    {
        $this->assertCanEdit($user);
        $holiday->delete();
        $this->calendarService->regenerateAllEmployeesForYear((int) now()->year);
    }

    /**
     * @param  list<array<string, mixed>>  $holidays
     * @return list<array<string, mixed>>
     */
    public function syncAll(array $holidays, ?User $user = null): array
    {
        $this->assertCanEdit($user);

        $ids = [];
        foreach ($holidays as $index => $row) {
            if (empty($row['name']) || empty($row['month']) || empty($row['day'])) {
                continue;
            }

            $payload = [
                'name' => (string) $row['name'],
                'month' => (int) $row['month'],
                'day' => (int) $row['day'],
                'is_active' => ! array_key_exists('is_active', $row) || (bool) $row['is_active'],
                'sort_order' => $index + 1,
            ];

            if (! empty($row['id'])) {
                $holiday = CompanyHoliday::query()->findOrFail((int) $row['id']);
                $holiday->update($payload);
                $ids[] = $holiday->id;
            } else {
                $holiday = CompanyHoliday::query()->create($payload);
                $ids[] = $holiday->id;
            }
        }

        CompanyHoliday::query()
            ->when($ids !== [], fn ($q) => $q->whereNotIn('id', $ids))
            ->delete();

        $this->calendarService->regenerateAllEmployeesForYear((int) now()->year);

        return $this->list();
    }

    private function serialize(CompanyHoliday $holiday): array
    {
        return [
            'id' => $holiday->id,
            'name' => $holiday->name,
            'month' => (int) $holiday->month,
            'day' => (int) $holiday->day,
            'is_active' => (bool) $holiday->is_active,
            'sort_order' => (int) $holiday->sort_order,
            'display_date' => sprintf('%02d/%02d', $holiday->month, $holiday->day),
        ];
    }

    private function assertCanEdit(?User $user): void
    {
        if (! $this->canEdit($user)) {
            throw new InvalidArgumentException('Only managers and super admins can manage company holidays.');
        }
    }
}
