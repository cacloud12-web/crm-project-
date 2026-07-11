<?php

namespace App\Services\Master;

use App\Models\CaMaster;
use App\Models\City;
use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Models\RoleMaster;
use App\Models\SourceLead;
use App\Models\State;
use App\Models\TeamSizeMaster;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class MasterDependencyService
{
    public const ENTITY_STATE = 'state';

    public const ENTITY_CITY = 'city';

    public const ENTITY_SOURCE = 'source';

    public const ENTITY_TEAM = 'team';

    public const ENTITY_ROLE = 'role';

    /**
     * @return array<string, class-string<Model>>
     */
    public function entityModels(): array
    {
        return [
            self::ENTITY_STATE => State::class,
            self::ENTITY_CITY => City::class,
            self::ENTITY_SOURCE => SourceLead::class,
            self::ENTITY_TEAM => TeamSizeMaster::class,
            self::ENTITY_ROLE => RoleMaster::class,
        ];
    }

    public function resolveModel(string $entity, int|string $id): Model
    {
        $modelClass = $this->entityModels()[$entity] ?? null;
        if (! $modelClass) {
            throw new InvalidArgumentException('Unsupported master entity type.');
        }

        return $modelClass::query()->findOrFail($id);
    }

    public function recordLabel(Model $record, string $entity): string
    {
        return match ($entity) {
            self::ENTITY_STATE => (string) $record->state_name,
            self::ENTITY_CITY => (string) $record->city_name,
            self::ENTITY_SOURCE => (string) $record->source_name,
            self::ENTITY_TEAM => (string) $record->team_size_label,
            self::ENTITY_ROLE => (string) $record->role_name,
            default => 'Record',
        };
    }

    /**
     * @return array{
     *     can_delete: bool,
     *     total_dependencies: int,
     *     dependencies: list<array{module: string, count: int, filter_key?: string, filter_value?: int|string}>,
     *     recommended_action: string,
     *     record_name: string,
     *     is_active: bool,
     *     is_system: bool
     * }
     */
    public function analyze(string $entity, Model $record): array
    {
        $dependencies = $this->countDependencies($entity, $record);
        $total = array_sum(array_column($dependencies, 'count'));
        $isSystem = method_exists($record, 'isSystemProtected') && $record->isSystemProtected();
        $canDelete = $total === 0 && ! $isSystem;

        return [
            'can_delete' => $canDelete,
            'total_dependencies' => $total,
            'dependencies' => $dependencies,
            'recommended_action' => $isSystem ? 'none' : ($total > 0 ? 'deactivate' : 'delete'),
            'record_name' => $this->recordLabel($record, $entity),
            'is_active' => method_exists($record, 'isActive') ? $record->isActive() : true,
            'is_system' => $isSystem,
            'entity' => $entity,
            'record_id' => $this->recordId($record, $entity),
        ];
    }

    /**
     * @return list<array{module: string, count: int, filter_key?: string, filter_value?: int|string}>
     */
    public function countDependencies(string $entity, Model $record): array
    {
        $dependencies = match ($entity) {
            self::ENTITY_STATE => $this->stateDependencies($record),
            self::ENTITY_CITY => $this->cityDependencies($record),
            self::ENTITY_SOURCE => $this->sourceDependencies($record),
            self::ENTITY_TEAM => $this->teamSizeDependencies($record),
            self::ENTITY_ROLE => $this->roleDependencies($record),
            default => [],
        };

        return array_values(array_filter($dependencies, fn (array $row) => ($row['count'] ?? 0) > 0));
    }

    /**
     * @return list<array{module: string, count: int, filter_key?: string, filter_value?: int|string}>
     */
    private function stateDependencies(State $state): array
    {
        $stateId = (int) $state->state_id;

        return [
            $this->dependencyRow('Cities', City::query()->where('state_id', $stateId)->count(), 'state_id', $stateId),
            $this->dependencyRow('CA Masters', $this->caMasterCount(['state_id' => $stateId]), 'state_id', $stateId),
            $this->dependencyRow('Leads', $this->assignedLeadCount(['state_id' => $stateId]), 'state_id', $stateId),
            $this->dependencyRow(
                'Employees',
                Employee::query()
                    ->whereNull('deleted_at')
                    ->whereHas('city', fn ($q) => $q->where('state_id', $stateId))
                    ->count(),
            ),
        ];
    }

    /**
     * @return list<array{module: string, count: int, filter_key?: string, filter_value?: int|string}>
     */
    private function cityDependencies(City $city): array
    {
        $cityId = (int) $city->city_id;

        return [
            $this->dependencyRow('CA Masters', $this->caMasterCount(['city_id' => $cityId]), 'city_id', $cityId),
            $this->dependencyRow('Leads', $this->assignedLeadCount(['city_id' => $cityId]), 'city_id', $cityId),
            $this->dependencyRow('Employees', Employee::query()->whereNull('deleted_at')->where('city_id', $cityId)->count(), 'city_id', $cityId),
        ];
    }

    /**
     * @return list<array{module: string, count: int, filter_key?: string, filter_value?: int|string}>
     */
    private function sourceDependencies(SourceLead $source): array
    {
        $sourceId = (int) $source->source_id;

        return [
            $this->dependencyRow('CA Masters', $this->caMasterCount(['source_id' => $sourceId]), 'source_id', $sourceId),
            $this->dependencyRow('Leads', $this->assignedLeadCount(['source_id' => $sourceId]), 'source_id', $sourceId),
        ];
    }

    /**
     * @return list<array{module: string, count: int, filter_key?: string, filter_value?: int|string}>
     */
    private function teamSizeDependencies(TeamSizeMaster $teamSize): array
    {
        $teamSizeId = (int) $teamSize->id;

        return [
            $this->dependencyRow('CA Masters', $this->caMasterCount(['team_size_id' => $teamSizeId]), 'team_size_id', $teamSizeId),
            $this->dependencyRow('Leads', $this->assignedLeadCount(['team_size_id' => $teamSizeId]), 'team_size_id', $teamSizeId),
        ];
    }

    /**
     * @return list<array{module: string, count: int, filter_key?: string, filter_value?: int|string}>
     */
    private function roleDependencies(RoleMaster $role): array
    {
        return [
            $this->dependencyRow(
                'Employees',
                Employee::query()
                    ->whereNull('deleted_at')
                    ->where('role', $role->role_name)
                    ->count(),
            ),
        ];
    }

    /**
     * @param  array<string, int>  $filters
     */
    private function caMasterCount(array $filters): int
    {
        $query = CaMaster::query();

        foreach ($filters as $column => $value) {
            $query->where($column, $value);
        }

        return $query->count();
    }

    /**
     * @param  array<string, int>  $filters
     */
    private function assignedLeadCount(array $filters): int
    {
        $query = CaMaster::query()
            ->whereHas('leadAssignments', fn ($q) => $q->where('status', 'Active'));

        foreach ($filters as $column => $value) {
            $query->where($column, $value);
        }

        return $query->count();
    }

    /**
     * @return array{module: string, count: int, filter_key?: string, filter_value?: int|string}
     */
    private function dependencyRow(string $module, int $count, ?string $filterKey = null, int|string|null $filterValue = null): array
    {
        $row = [
            'module' => $module,
            'count' => $count,
        ];

        if ($filterKey !== null && $filterValue !== null) {
            $row['filter_key'] = $filterKey;
            $row['filter_value'] = $filterValue;
        }

        return $row;
    }

    private function recordId(Model $record, string $entity): int
    {
        return match ($entity) {
            self::ENTITY_STATE => (int) $record->state_id,
            self::ENTITY_CITY => (int) $record->city_id,
            self::ENTITY_SOURCE => (int) $record->source_id,
            self::ENTITY_TEAM => (int) $record->id,
            self::ENTITY_ROLE => (int) $record->id,
            default => (int) $record->getKey(),
        };
    }
}
