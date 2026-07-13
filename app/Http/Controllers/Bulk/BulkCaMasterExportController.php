<?php

namespace App\Http\Controllers\Bulk;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bulk\BulkExportPreviewRequest;
use App\Http\Requests\Bulk\BulkExportStoreRequest;
use App\Http\Resources\BulkActionResource;
use App\Jobs\Bulk\ProcessBulkCaMasterExportJob;
use App\Services\Bulk\BulkCaMasterExportService;
use App\Services\Bulk\BulkExportHistoryService;
use App\Services\Bulk\BulkExportPermissionService;
use App\Services\Bulk\BulkOperationsHistoryService;
use App\Support\ApiResponse;
use App\Support\Listing\ListingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class BulkCaMasterExportController extends Controller
{
    public function __construct(
        private readonly BulkCaMasterExportService $exportService,
        private readonly BulkExportHistoryService $historyService,
        private readonly BulkOperationsHistoryService $operationsHistoryService,
        private readonly BulkExportPermissionService $permissionService,
    ) {}

    public function columns(): JsonResponse
    {
        try {
            $this->permissionService->authorize();
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }

        return ApiResponse::success([
            'columns' => collect($this->exportService->availableColumns())
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values(),
        ], 'Export columns loaded');
    }

    public function preview(BulkExportPreviewRequest $request): JsonResponse
    {
        try {
            $result = $this->exportService->preview($request->validated());
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Unable to preview export.', 500);
        }

        return ApiResponse::success($result, 'Export preview ready');
    }

    public function store(BulkExportStoreRequest $request): JsonResponse
    {
        try {
            $payload = $request->validated();
            $summary = $this->exportService->startExport(
                $payload,
                $payload['performed_by'] ?? 'System',
            );

            if ($summary['uses_background'] ?? false) {
                ProcessBulkCaMasterExportJob::dispatch($summary['bulk_action_id']);
            } else {
                $this->exportService->processExport($summary['bulk_action_id']);
                $summary = $this->exportService->status($summary['bulk_action_id']);
                $summary['download_ready'] = true;
                $summary['uses_background'] = false;
            }

            return ApiResponse::success($summary, ($summary['uses_background'] ?? false)
                ? 'Export queued for background processing'
                : 'Export completed successfully');
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Bulk export failed. Please try again.', 500);
        }
    }

    public function history(): JsonResponse
    {
        return ApiResponse::success(
            BulkActionResource::collection($this->historyService->list()),
            'Bulk export history loaded',
        );
    }

    public function operationsHistory(Request $request): JsonResponse
    {
        $result = $this->operationsHistoryService->search($request->query());

        return ListingResponse::from($result, BulkActionResource::class, 'Bulk operations history loaded');
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(
            $this->historyService->detail($id),
            'Bulk export detail loaded',
        );
    }

    public function status(string $id): JsonResponse
    {
        try {
            return ApiResponse::success(
                $this->exportService->status($id),
                'Export status loaded',
            );
        } catch (Throwable $e) {
            return ApiResponse::error('Export not found.', 404);
        }
    }

    public function download(string $id): BinaryFileResponse|JsonResponse
    {
        try {
            $this->permissionService->authorize();
            $file = $this->exportService->downloadPath($id);

            return response()->download($file['path'], $file['file_name'], [
                'Content-Type' => $file['mime'],
            ]);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Unable to download export file.', 500);
        }
    }
}
