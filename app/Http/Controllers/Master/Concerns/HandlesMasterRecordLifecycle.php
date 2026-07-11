<?php

namespace App\Http\Controllers\Master\Concerns;

use App\Exceptions\Master\MasterRecordInUseException;
use App\Services\Master\MasterDependencyService;
use App\Services\Master\MasterRecordLifecycleService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

trait HandlesMasterRecordLifecycle
{
    abstract protected function masterEntityKey(): string;

    abstract protected function masterLifecycleService(): MasterRecordLifecycleService;

    abstract protected function masterDependencyService(): MasterDependencyService;

    abstract protected function masterFind(int|string $id): Model;

    abstract protected function masterResource(Model $model): mixed;

    abstract protected function masterEntityLabel(): string;

    public function dependencies(string $id): JsonResponse
    {
        try {
            return ApiResponse::success(
                $this->masterLifecycleService()->dependencies($this->masterEntityKey(), $id),
                'Master record dependencies loaded',
            );
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }
    }

    public function deactivate(string $id): JsonResponse
    {
        try {
            $record = $this->masterLifecycleService()->deactivate(
                $this->masterEntityKey(),
                $this->masterFind($id),
                auth()->id(),
            );

            return ApiResponse::success(
                $this->masterResource($record),
                $this->masterEntityLabel().' deactivated successfully',
            );
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }
    }

    public function reactivate(string $id): JsonResponse
    {
        try {
            $record = $this->masterLifecycleService()->reactivate(
                $this->masterEntityKey(),
                $this->masterFind($id),
            );

            return ApiResponse::success(
                $this->masterResource($record),
                $this->masterEntityLabel().' reactivated successfully',
            );
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }
    }

    protected function destroyWithLifecycle(string $id): JsonResponse
    {
        try {
            $this->masterLifecycleService()->delete(
                $this->masterEntityKey(),
                $this->masterFind($id),
            );
        } catch (MasterRecordInUseException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, $exception->toApiPayload());
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(null, $this->masterEntityLabel().' deleted successfully');
    }
}
