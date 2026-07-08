<?php

namespace App\Http\Controllers\Bulk;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bulk\BulkImportCommitRequest;
use App\Http\Requests\Bulk\BulkImportParseRequest;
use App\Http\Requests\Bulk\BulkImportValidateRequest;
use App\Http\Resources\BulkActionResource;
use App\Services\Bulk\BulkCaMasterImportService;
use App\Services\Bulk\BulkImportErrorReportService;
use App\Services\Bulk\BulkImportHistoryService;
use App\Services\Bulk\BulkImportTemplateService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class BulkCaMasterImportController extends Controller
{
    public function __construct(
        private readonly BulkCaMasterImportService $importService,
        private readonly BulkImportTemplateService $templateService,
        private readonly BulkImportErrorReportService $errorReportService,
        private readonly BulkImportHistoryService $historyService,
    ) {}

    public function parse(BulkImportParseRequest $request): JsonResponse
    {
        try {
            $result = $this->importService->parseUpload($request->file('file'));
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Unable to parse the uploaded file.', 500);
        }

        return ApiResponse::success($result, 'File parsed successfully');
    }

    public function validateMapping(BulkImportValidateRequest $request): JsonResponse
    {
        try {
            $result = $this->importService->validateSession(
                $request->string('session_id')->toString(),
                $request->input('mapping', []),
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Validation failed. Please try again.', 500);
        }

        return ApiResponse::success($result, 'Validation preview ready');
    }

    public function store(BulkImportCommitRequest $request): JsonResponse
    {
        try {
            $templateName = $request->boolean('save_template')
                ? ($request->input('template_name') ?: 'Default Import Mapping')
                : null;

            $summary = $this->importService->importSession(
                $request->string('session_id')->toString(),
                $request->input('mapping', []),
                $templateName,
                $request->input('row_actions', []),
            );

            if (! empty($summary['uses_background'])) {
                return ApiResponse::success($summary, 'Import queued for background processing');
            }

            $message = sprintf(
                'Import completed: %d inserted, %d duplicates skipped, %d failed out of %d rows.',
                $summary['inserted_rows'],
                $summary['duplicate_rows'],
                $summary['failed_rows'],
                $summary['total_rows'],
            );

            if (($summary['skipped_rows'] ?? 0) > 0) {
                $message .= sprintf(' (%d skipped)', $summary['skipped_rows']);
            }

            return ApiResponse::success($summary, $message);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Bulk import failed. Please try again.', 500);
        }
    }

    public function applyRowActions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|uuid',
            'row_actions' => 'required|array',
            'row_actions.*' => 'required|string|in:skip,import_anyway,merge,replace',
        ]);

        try {
            $result = $this->importService->applyRowActions(
                $validated['session_id'],
                $validated['row_actions'],
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Unable to update duplicate actions.', 500);
        }

        return ApiResponse::success($result, 'Duplicate actions updated');
    }

    public function status(string $id): JsonResponse
    {
        try {
            $result = $this->importService->importStatus((int) $id);
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Unable to load import status.', 500);
        }

        return ApiResponse::success($result, 'Import status loaded');
    }

    public function downloadSampleCsv(): StreamedResponse
    {
        return response()->streamDownload(function () {
            echo $this->templateService->sampleCsv();
        }, 'ca_master_import_template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function downloadSampleXlsx(): StreamedResponse
    {
        $binary = $this->templateService->sampleXlsx();

        return response()->streamDownload(function () use ($binary) {
            echo $binary;
        }, 'ca_master_import_template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function history(): JsonResponse
    {
        return ApiResponse::success(
            BulkActionResource::collection($this->historyService->list()),
            'Bulk import history loaded',
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(
            $this->historyService->detail($id),
            'Bulk import detail loaded',
        );
    }

    public function sessionErrorReport(string $sessionId): StreamedResponse
    {
        $rows = $this->importService->sessionErrorRows($sessionId);
        $csv = $this->errorReportService->errorReportCsv($rows);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'bulk_import_validation_errors.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function sessionReimportTemplate(string $sessionId): StreamedResponse
    {
        $rows = $this->importService->sessionFailedRowsForReimport($sessionId);
        $csv = $this->errorReportService->reimportTemplateCsv($rows);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'bulk_import_failed_rows.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function importErrorReport(string $id): StreamedResponse
    {
        $rows = $this->historyService->errorRows($id);
        $csv = $this->errorReportService->errorReportCsv($rows);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'bulk_import_'.$id.'_errors.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function importReimportTemplate(string $id): StreamedResponse
    {
        $rows = $this->historyService->failedRowsForReimport($id);
        $csv = $this->errorReportService->reimportTemplateCsv($rows);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'bulk_import_'.$id.'_failed_rows.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function mappingTemplates(): JsonResponse
    {
        return ApiResponse::success(
            $this->importService->listMappingTemplates(),
            'Mapping templates loaded',
        );
    }

    public function saveMappingTemplate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template_name' => 'required|string|max:120',
            'mapping' => 'required|array',
        ]);

        $template = $this->importService->saveMappingTemplate(
            $validated['template_name'],
            $validated['mapping'],
        );

        return ApiResponse::created([
            'id' => $template->id,
            'template_name' => $template->template_name,
            'field_mapping' => $template->field_mapping,
        ], 'Mapping template saved');
    }
}
