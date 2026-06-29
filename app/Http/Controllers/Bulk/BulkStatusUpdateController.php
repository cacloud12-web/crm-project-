<?php

namespace App\Http\Controllers\Bulk;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bulk\BulkStatusUpdateRequest;
use App\Services\Bulk\BulkStatusUpdateService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class BulkStatusUpdateController extends Controller
{
    public function __construct(
        private readonly BulkStatusUpdateService $bulkStatusUpdateService,
    ) {}

    public function statuses(): JsonResponse
    {
        return ApiResponse::success([
            'statuses' => $this->bulkStatusUpdateService->allowedStatuses(),
        ], 'Allowed statuses loaded');
    }

    public function store(BulkStatusUpdateRequest $request): JsonResponse
    {
        try {
            $preview = (bool) $request->boolean('preview');
            $summary = $this->bulkStatusUpdateService->execute($request->validated(), $preview);

            if ($preview) {
                $message = sprintf(
                    'Preview: %d will update, %d already at %s out of %d records.',
                    $summary['updated_rows'],
                    $summary['skipped_rows'],
                    $summary['target_status'],
                    $summary['total_rows'],
                );
            } else {
                $message = sprintf(
                    'Bulk status update completed: %d updated, %d skipped out of %d records.',
                    $summary['updated_rows'],
                    $summary['skipped_rows'],
                    $summary['total_rows'],
                );
            }

            return ApiResponse::success($summary, $message);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Bulk status update failed and was rolled back.', 500);
        }
    }
}
