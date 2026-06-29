<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Activity\ActivityLogService;
use App\Services\Reports\ReportExportQueueService;
use App\Services\Reports\ReportsExportService;
use App\Services\Reports\ReportsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ReportsController extends Controller
{
    public function __construct(
        private readonly ReportsService $reportsService,
        private readonly ReportsExportService $reportsExportService,
        private readonly ReportExportQueueService $reportExportQueueService,
        private readonly ActivityLogService $activityLogService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->reportsService->summary($request->query()),
            'Reports summary loaded',
        );
    }

    public function analytics(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->reportsService->analytics($request->query()),
            'Analytics loaded',
        );
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        try {
            return ApiResponse::success(
                $this->reportsService->report($slug, $request->query()),
                'Report loaded',
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 404);
        }
    }

    public function export(Request $request, string $slug): StreamedResponse|JsonResponse|Response
    {
        try {
            $data = $this->reportsService->exportData($slug, $request->query());
            $rowCount = count($data['rows']);

            $this->activityLogService->log(
                'REPORTS',
                'Report Export',
                $slug,
                'Exported report: '.$slug,
                $this->performerName(),
            );

            if ($this->isPdfFormat($request)) {
                return $this->reportsExportService->streamPdf(
                    $data['filename'],
                    $data['columns'],
                    $data['rows'],
                    $data['title'] ?? ucfirst(str_replace('-', ' ', $slug)),
                );
            }

            if ($this->reportExportQueueService->shouldQueue($rowCount)) {
                return ApiResponse::success(
                    $this->reportExportQueueService->queue(
                        $slug,
                        $request->query(),
                        $data,
                        $this->performerName(),
                    ),
                    'Report export queued for background processing',
                );
            }

            return $this->reportsExportService->streamCsv(
                $data['filename'],
                $data['columns'],
                $data['rows'],
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 404);
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Unable to export report.', 500);
        }
    }

    public function exportSummary(Request $request): StreamedResponse|JsonResponse|Response
    {
        try {
            $data = $this->reportsService->exportSummary($request->query());
            $rowCount = count($data['rows']);

            $this->activityLogService->log(
                'REPORTS',
                'Report Export',
                'summary',
                'Exported reports summary',
                $this->performerName(),
            );

            if ($this->isPdfFormat($request)) {
                return $this->reportsExportService->streamPdf(
                    $data['filename'],
                    $data['columns'],
                    $data['rows'],
                    'Reports Summary',
                );
            }

            if ($this->reportExportQueueService->shouldQueue($rowCount)) {
                return ApiResponse::success(
                    $this->reportExportQueueService->queue(
                        'summary',
                        $request->query(),
                        $data,
                        $this->performerName(),
                    ),
                    'Report export queued for background processing',
                );
            }

            return $this->reportsExportService->streamCsv(
                $data['filename'],
                $data['columns'],
                $data['rows'],
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Unable to export reports summary.', 500);
        }
    }

    private function isPdfFormat(Request $request): bool
    {
        return strtolower((string) $request->query('format', 'csv')) === 'pdf';
    }

    private function performerName(): string
    {
        $user = auth()->user();

        if ($user?->name) {
            return $user->name;
        }

        if ($user?->email) {
            return $user->email;
        }

        return 'System';
    }
}
