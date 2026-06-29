<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Queue\QueueStatusService;
use App\Services\Reports\ReportExportQueueService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class QueueStatusController extends Controller
{
    public function __construct(
        private readonly QueueStatusService $queueStatusService,
        private readonly ReportExportQueueService $reportExportQueueService,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success(
            $this->queueStatusService->summary(),
            'Queue status loaded',
        );
    }

    public function reportExportStatus(string $exportId): JsonResponse
    {
        try {
            return ApiResponse::success(
                $this->reportExportQueueService->status($exportId),
                'Report export status loaded',
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 404);
        }
    }

    public function reportExportDownload(string $exportId): BinaryFileResponse|JsonResponse
    {
        try {
            $file = $this->reportExportQueueService->downloadPath($exportId);

            return response()->download($file['path'], $file['file_name'], [
                'Content-Type' => $file['mime'],
            ]);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }
}
