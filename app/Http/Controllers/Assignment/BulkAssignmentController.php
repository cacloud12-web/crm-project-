<?php

namespace App\Http\Controllers\Assignment;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeadAssignment\BulkAssignmentRequest;
use App\Services\Assignment\BulkAssignmentCatalogService;
use App\Services\Assignment\BulkAssignmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class BulkAssignmentController extends Controller
{
    public function __construct(
        private readonly BulkAssignmentService $bulkAssignmentService,
        private readonly BulkAssignmentCatalogService $catalogService,
    ) {}

    public function leads(Request $request): JsonResponse
    {
        $data = $this->catalogService->listLeads($request->query());

        return ApiResponse::success($data, 'Bulk assignment leads loaded');
    }

    public function leadIds(Request $request): JsonResponse
    {
        $data = $this->catalogService->listLeadIds($request->query());

        return ApiResponse::success($data, 'Bulk assignment lead IDs loaded');
    }

    public function employees(Request $request): JsonResponse
    {
        $data = $this->catalogService->listEmployees($request->query());

        return ApiResponse::success($data, 'Bulk assignment employees loaded');
    }

    public function batches(Request $request): JsonResponse
    {
        $data = $this->catalogService->listBatches($request->query());

        return ApiResponse::success($data, 'Bulk assignment batches loaded');
    }

    public function store(BulkAssignmentRequest $request): JsonResponse
    {
        try {
            $preview = (bool) $request->boolean('preview');
            $data = $request->validated();

            if (! empty($data['bulk_action_id'])) {
                $filterParams = array_intersect_key($data, array_flip([
                    'state_id',
                    'city_id',
                    'source_id',
                    'assignment',
                ]));
                $data['ca_ids'] = $this->catalogService->resolveBatchLeadIds(
                    (int) $data['bulk_action_id'],
                    $filterParams,
                );
                unset($data['bulk_action_id'], $data['state_id'], $data['city_id'], $data['source_id'], $data['assignment']);
            }

            if (empty($data['ca_ids'])) {
                return ApiResponse::error('No leads match the selected batch and filters.', 422);
            }

            $summary = $this->bulkAssignmentService->execute($data, $preview);

            if ($preview) {
                $message = sprintf(
                    'Preview: %d to assign, %d duplicates, %d unassigned/failed out of %d leads.',
                    $summary['assigned_rows'],
                    $summary['duplicate_rows'],
                    $summary['failed_rows'],
                    $summary['total_leads'],
                );
            } else {
                $message = sprintf(
                    '%d leads assigned successfully (%d new, %d reassigned).',
                    $summary['assigned_rows'] + $summary['reassigned_rows'],
                    $summary['assigned_rows'],
                    $summary['reassigned_rows'],
                );
            }

            return ApiResponse::success($summary, $message);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Bulk assignment failed. Please try again.', 500);
        }
    }
}
