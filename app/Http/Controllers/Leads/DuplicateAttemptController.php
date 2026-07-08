<?php

namespace App\Http\Controllers\Leads;

use App\Http\Controllers\Controller;
use App\Services\Leads\DuplicateAttemptService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DuplicateAttemptController extends Controller
{
    public function __construct(
        private readonly DuplicateAttemptService $duplicateAttemptService,
    ) {}

    public function metrics(): JsonResponse
    {
        return ApiResponse::success(
            $this->duplicateAttemptService->dashboardMetrics(),
            'Duplicate attempt metrics loaded',
        );
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->duplicateAttemptService->search($request->query()),
            'Duplicate attempts loaded',
        );
    }

    public function markChanged(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'final_number' => ['nullable', 'string', 'max:20'],
        ]);

        $this->duplicateAttemptService->markNumberChanged($id, $validated['final_number'] ?? null);

        return ApiResponse::success(null, 'Duplicate attempt updated');
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->duplicateAttemptService->exportRows($request->query());

        $filename = 'duplicate-attempts-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'ID', 'Employee', 'Duplicate Number', 'Saved Number', 'Existing Lead',
                'Attempt Type', 'Status', 'Number Changed', 'Field', 'IP', 'Attempted At',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['id'],
                    $row['employee_name'],
                    $row['duplicate_number'],
                    $row['saved_number'],
                    $row['existing_lead_name'],
                    $row['attempt_type'],
                    $row['status'],
                    $row['number_changed'] ? 'Yes' : 'No',
                    $row['field_name'],
                    $row['ip'],
                    $row['attempted_at'],
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
