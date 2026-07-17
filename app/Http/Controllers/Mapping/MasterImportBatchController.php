<?php

namespace App\Http\Controllers\Mapping;

use App\Http\Controllers\Controller;
use App\Models\MasterImportBatch;
use App\Services\Mapping\MasterImportRollbackService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class MasterImportBatchController extends Controller
{
    public function show(MasterImportBatch $batch): JsonResponse
    {
        return ApiResponse::success([
            'id' => $batch->id,
            'source_type' => $batch->source_type,
            'source_ref' => $batch->source_ref,
            'file_name' => $batch->file_name,
            'status' => $batch->status,
            'progress_stage' => $batch->progress_stage,
            'progress_pct' => $batch->progress_pct,
            'total_records' => $batch->total_records,
            'created_count' => $batch->created_count,
            'updated_count' => $batch->updated_count,
            'duplicate_count' => $batch->duplicate_count,
            'review_count' => $batch->review_count,
            'conflict_count' => $batch->conflict_count,
            'failed_count' => $batch->failed_count,
            'rollbackable' => $batch->isRollbackable(),
            'rolled_back_at' => $batch->rolled_back_at?->toIso8601String(),
            'created_at' => $batch->created_at?->toIso8601String(),
        ]);
    }

    public function rollback(
        MasterImportBatch $batch,
        MasterImportRollbackService $rollbackService,
    ): JsonResponse {
        $result = $rollbackService->rollback($batch, auth()->id() ? (int) auth()->id() : null);

        return ApiResponse::success($result, $result['message']);
    }
}
