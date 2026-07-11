<?php

namespace App\Services\Master;

use App\Exceptions\Master\MasterRecordInUseException;
use App\Services\Master\Concerns\LogsMasterActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MasterRecordLifecycleService
{
    use LogsMasterActivity;

    public function __construct(
        private readonly MasterDependencyService $dependencyService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dependencies(string $entity, int|string $id): array
    {
        $record = $this->dependencyService->resolveModel($entity, $id);

        return $this->dependencyService->analyze($entity, $record);
    }

    public function delete(string $entity, Model $record): void
    {
        if (method_exists($record, 'isSystemProtected') && $record->isSystemProtected()) {
            $this->logMasterActivity(
                'Delete Blocked (System Protected)',
                $this->entityTag($entity),
                (string) $record->getKey(),
                $this->dependencyService->recordLabel($record, $entity),
            );

            throw new InvalidArgumentException('This is a system-protected record and cannot be deleted.');
        }

        $analysis = $this->dependencyService->analyze($entity, $record);

        if (! $analysis['can_delete']) {
            $this->logMasterActivity(
                'Delete Blocked (Dependencies)',
                $this->entityTag($entity),
                (string) $record->getKey(),
                $this->dependencyService->recordLabel($record, $entity).' · '.$analysis['total_dependencies'].' dependencies',
            );

            throw new MasterRecordInUseException(
                'Cannot delete "'.$analysis['record_name'].'".',
                $analysis['dependencies'],
                $analysis['record_name'],
                'deactivate',
            );
        }

        $name = $analysis['record_name'];
        $recordId = (string) $record->getKey();

        try {
            DB::transaction(fn () => $record->delete());
        } catch (QueryException $exception) {
            if ($this->isForeignKeyViolation($exception)) {
                $dependencies = $this->dependencyService->countDependencies($entity, $record);

                throw new MasterRecordInUseException(
                    'Cannot delete "'.$name.'" because related records still reference it.',
                    $dependencies,
                    $name,
                    'deactivate',
                );
            }

            throw $exception;
        }

        $this->logMasterActivity(
            'Delete '.$this->actionEntity($entity),
            $this->entityTag($entity),
            $recordId,
            $name,
        );
    }

    public function deactivate(string $entity, Model $record, ?int $userId = null): Model
    {
        if (method_exists($record, 'isActive') && ! $record->isActive()) {
            throw new InvalidArgumentException('Record is already inactive.');
        }

        if (method_exists($record, 'isSystemProtected') && $record->isSystemProtected()) {
            throw new InvalidArgumentException('This is a system-protected record and cannot be deactivated.');
        }

        $name = $this->dependencyService->recordLabel($record, $entity);

        DB::transaction(function () use ($record, $userId) {
            $record->update([
                'is_active' => false,
                'deactivated_at' => now(),
                'deactivated_by' => $userId,
            ]);
        });

        $this->logMasterActivity(
            'Deactivate '.$this->actionEntity($entity),
            $this->entityTag($entity),
            (string) $record->getKey(),
            $name,
        );

        return $record->fresh();
    }

    public function reactivate(string $entity, Model $record): Model
    {
        if (method_exists($record, 'isActive') && $record->isActive()) {
            throw new InvalidArgumentException('Record is already active.');
        }

        $name = $this->dependencyService->recordLabel($record, $entity);

        DB::transaction(function () use ($record) {
            $record->update([
                'is_active' => true,
                'deactivated_at' => null,
                'deactivated_by' => null,
            ]);
        });

        $this->logMasterActivity(
            'Reactivate '.$this->actionEntity($entity),
            $this->entityTag($entity),
            (string) $record->getKey(),
            $name,
        );

        return $record->fresh();
    }

    private function isForeignKeyViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? '';

        return in_array($sqlState, ['23000', '23503'], true)
            || str_contains(strtolower($exception->getMessage()), 'foreign key');
    }

    private function entityTag(string $entity): string
    {
        return match ($entity) {
            MasterDependencyService::ENTITY_STATE => 'STATE_MASTER',
            MasterDependencyService::ENTITY_CITY => 'CITY_MASTER',
            MasterDependencyService::ENTITY_SOURCE => 'SOURCE_OF_LEAD',
            MasterDependencyService::ENTITY_TEAM => 'TEAM_SIZE_MASTER',
            MasterDependencyService::ENTITY_ROLE => 'ROLE_MASTER',
            default => 'MASTER_RECORD',
        };
    }

    private function actionEntity(string $entity): string
    {
        return match ($entity) {
            MasterDependencyService::ENTITY_STATE => 'State',
            MasterDependencyService::ENTITY_CITY => 'City',
            MasterDependencyService::ENTITY_SOURCE => 'Source',
            MasterDependencyService::ENTITY_TEAM => 'Team Size',
            MasterDependencyService::ENTITY_ROLE => 'Role',
            default => 'Record',
        };
    }
}
