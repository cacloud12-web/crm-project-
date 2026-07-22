<?php

namespace App\Http\Controllers\Mapping;

use App\Http\Controllers\Controller;
use App\Models\MasterImportBatch;
use App\Models\SalesImportRow;
use App\Services\Mapping\SalesImportReviewService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

class SalesImportController extends Controller
{
    public function __construct(
        private readonly SalesImportReviewService $reviewService,
    ) {}

    public function files(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'employee' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:32'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $employee = trim((string) ($validated['employee'] ?? ''));
        $status = trim((string) ($validated['status'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 25);

        $stats = SalesImportRow::query()
            ->select([
                'source_file_name',
                DB::raw('MAX(import_batch_id) as import_batch_id'),
                DB::raw('MAX(employee_name) as employee_name'),
                DB::raw('COUNT(*) as total_rows'),
                DB::raw("SUM(CASE WHEN mapping_status = 'matched' THEN 1 ELSE 0 END) as matched_count"),
                DB::raw("SUM(CASE WHEN mapping_status = 'needs_review' THEN 1 ELSE 0 END) as needs_review_count"),
                DB::raw("SUM(CASE WHEN mapping_status = 'unmatched' THEN 1 ELSE 0 END) as unmatched_count"),
                DB::raw("SUM(CASE WHEN mapping_status = 'ignored' THEN 1 ELSE 0 END) as ignored_count"),
                DB::raw("SUM(CASE WHEN mapping_status = 'pending' THEN 1 ELSE 0 END) as pending_count"),
                DB::raw('MAX(created_at) as imported_at'),
            ])
            ->when($employee !== '', fn ($q) => $q->where('employee_name', $employee))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('source_file_name', 'like', "%{$search}%")
                        ->orWhere('employee_name', 'like', "%{$search}%");
                });
            })
            ->groupBy('source_file_name', 'import_batch_id')
            ->orderByDesc(DB::raw('MAX(created_at)'))
            ->get();

        if ($status !== '' && Schema::hasTable('master_import_batches')) {
            $batchStatuses = MasterImportBatch::query()
                ->whereIn('id', $stats->pluck('import_batch_id')->filter()->unique())
                ->pluck('status', 'id');
            $stats = $stats->filter(function ($row) use ($status, $batchStatuses) {
                if (! $row->import_batch_id) {
                    return $status === 'completed';
                }

                return ($batchStatuses[(int) $row->import_batch_id] ?? null) === $status;
            })->values();
        } elseif ($status !== '' && $status !== 'completed') {
            $stats = collect();
        }

        $pageNumber = max(1, (int) ($validated['page'] ?? 1));
        $total = $stats->count();
        $slice = $stats->slice(($pageNumber - 1) * $perPage, $perPage)->values();
        $lastPage = max(1, (int) ceil($total / $perPage));

        $batchIds = $slice->pluck('import_batch_id')->filter()->unique()->values();
        $batches = $batchIds->isEmpty() || ! Schema::hasTable('master_import_batches')
            ? collect()
            : MasterImportBatch::query()->whereIn('id', $batchIds)->get()->keyBy('id');

        $items = $slice->map(function ($row) use ($batches) {
            $batch = $row->import_batch_id ? $batches->get((int) $row->import_batch_id) : null;
            $remarks = [];
            if ($batch && is_string($batch->remarks)) {
                $decoded = json_decode($batch->remarks, true);
                if (is_array($decoded)) {
                    $remarks = $decoded;
                }
            }

            return [
                'import_batch_id' => $row->import_batch_id ? (int) $row->import_batch_id : null,
                'source_file_name' => $row->source_file_name,
                'employee_name' => $row->employee_name ?: ($remarks['employee_name'] ?? null),
                'total_rows' => (int) $row->total_rows,
                'matched_count' => (int) $row->matched_count,
                'needs_review_count' => (int) $row->needs_review_count,
                'unmatched_count' => (int) $row->unmatched_count,
                'ignored_count' => (int) $row->ignored_count,
                'pending_count' => (int) $row->pending_count,
                'failed_count' => $batch ? (int) $batch->failed_count : 0,
                'imported_at' => $row->imported_at,
                'batch_status' => $batch?->status ?? 'completed',
            ];
        })->values();

        $summaryBase = SalesImportRow::query()
            ->when($employee !== '', fn ($q) => $q->where('employee_name', $employee));
        $statusCounts = (clone $summaryBase)
            ->selectRaw('mapping_status, COUNT(*) as total')
            ->groupBy('mapping_status')
            ->pluck('total', 'mapping_status');

        return ApiResponse::success([
            'data' => $items,
            'pagination' => [
                'current_page' => $pageNumber,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
            'summary' => [
                'total_files' => $total,
                'total_rows' => (clone $summaryBase)->count(),
                'matched' => (int) ($statusCounts['matched'] ?? 0),
                'needs_review' => (int) ($statusCounts['needs_review'] ?? 0),
                'unmatched' => (int) ($statusCounts['unmatched'] ?? 0),
                'ignored' => (int) ($statusCounts['ignored'] ?? 0),
            ],
            'employees' => SalesImportRow::query()
                ->whereNotNull('employee_name')
                ->where('employee_name', '!=', '')
                ->distinct()
                ->orderBy('employee_name')
                ->pluck('employee_name')
                ->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:matched,needs_review,unmatched,pending,ignored'],
            'employee' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
            'import_batch_id' => ['nullable', 'integer', 'min:1'],
            'source_file_name' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $status = $validated['status'] ?? null;
        $employee = trim((string) ($validated['employee'] ?? ''));
        $search = trim((string) ($validated['search'] ?? ''));
        $sourceFileName = trim((string) ($validated['source_file_name'] ?? ''));
        $importBatchId = isset($validated['import_batch_id']) ? (int) $validated['import_batch_id'] : null;
        $perPage = (int) ($validated['per_page'] ?? 25);

        $query = SalesImportRow::query()
            ->with([
                'ca:ca_id,ca_name,firm_name,mobile_no,city_id',
                'ca.city:city_id,city_name',
            ])
            ->when($status !== null, fn ($builder) => $builder->where('mapping_status', $status))
            ->when($employee !== '', fn ($builder) => $builder->where('employee_name', $employee))
            ->when($importBatchId !== null, fn ($builder) => $builder->where('import_batch_id', $importBatchId))
            ->when($sourceFileName !== '', fn ($builder) => $builder->where('source_file_name', $sourceFileName))
            ->when($search !== '', function ($builder) use ($search) {
                $builder->where(function ($inner) use ($search) {
                    $inner
                        ->where('ca_name', 'like', "%{$search}%")
                        ->orWhere('firm_name', 'like', "%{$search}%")
                        ->orWhere('city_name', 'like', "%{$search}%")
                        ->orWhere('mobile_no', 'like', "%{$search}%")
                        ->orWhere('alternate_mobile_no', 'like', "%{$search}%")
                        ->orWhere('remarks_1', 'like', "%{$search}%")
                        ->orWhere('remarks_2', 'like', "%{$search}%")
                        ->orWhere('employee_name', 'like', "%{$search}%")
                        ->orWhere('review_reason', 'like', "%{$search}%");
                });
            })
            ->latest('call_date')
            ->latest('id');

        $rows = $query->paginate($perPage);
        $items = collect($rows->items())->map(fn (SalesImportRow $row) => $this->serializeRow($row))->values();

        return ApiResponse::success([
            'data' => $items,
            'pagination' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee' => ['nullable', 'string', 'max:255'],
            'import_batch_id' => ['nullable', 'integer', 'min:1'],
            'source_file_name' => ['nullable', 'string', 'max:255'],
        ]);
        $employee = trim((string) ($validated['employee'] ?? ''));
        $sourceFileName = trim((string) ($validated['source_file_name'] ?? ''));
        $importBatchId = isset($validated['import_batch_id']) ? (int) $validated['import_batch_id'] : null;

        $base = SalesImportRow::query()
            ->when($employee !== '', fn ($builder) => $builder->where('employee_name', $employee))
            ->when($importBatchId !== null, fn ($builder) => $builder->where('import_batch_id', $importBatchId))
            ->when($sourceFileName !== '', fn ($builder) => $builder->where('source_file_name', $sourceFileName));

        $counts = (clone $base)
            ->selectRaw('mapping_status, COUNT(*) as total')
            ->groupBy('mapping_status')
            ->pluck('total', 'mapping_status');

        $fileMeta = null;
        if ($importBatchId !== null || $sourceFileName !== '') {
            $sample = (clone $base)->orderByDesc('id')->first(['source_file_name', 'employee_name', 'import_batch_id']);
            $fileMeta = [
                'import_batch_id' => $sample?->import_batch_id,
                'source_file_name' => $sample?->source_file_name ?? ($sourceFileName !== '' ? $sourceFileName : null),
                'employee_name' => $sample?->employee_name,
            ];
        }

        return ApiResponse::success([
            'total' => (clone $base)->count(),
            'matched' => (int) ($counts['matched'] ?? 0),
            'needs_review' => (int) ($counts['needs_review'] ?? 0),
            'unmatched' => (int) ($counts['unmatched'] ?? 0),
            'pending' => (int) ($counts['pending'] ?? 0),
            'ignored' => (int) ($counts['ignored'] ?? 0),
            'selected_employee' => $employee !== '' ? $employee : null,
            'selected_file' => $fileMeta,
            'employees' => SalesImportRow::query()
                ->whereNotNull('employee_name')
                ->where('employee_name', '!=', '')
                ->distinct()
                ->orderBy('employee_name')
                ->pluck('employee_name')
                ->values(),
        ]);
    }

    public function show(SalesImportRow $salesImportRow): JsonResponse
    {
        $salesImportRow->load([
            'ca:ca_id,ca_name,firm_name,mobile_no,alternate_mobile_no,email_id,city_id',
            'ca.city:city_id,city_name',
        ]);

        return ApiResponse::success($this->serializeRow($salesImportRow, true));
    }

    public function candidates(SalesImportRow $salesImportRow): JsonResponse
    {
        $candidates = $this->reviewService->candidatesForRow($salesImportRow, 15);

        return ApiResponse::success([
            'sales_import_row_id' => $salesImportRow->id,
            'row' => $this->serializeRow($salesImportRow),
            'candidates' => $candidates,
            'candidate_count' => count($candidates),
        ], 'Candidates loaded');
    }

    public function searchReference(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'firm' => ['nullable', 'string', 'max:255'],
            'ca' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $result = $this->reviewService->searchReference(
            $validated['firm'] ?? null,
            $validated['ca'] ?? null,
            $validated['city'] ?? null,
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? 20),
        );

        return ApiResponse::success($result, 'CA Reference search loaded');
    }

    public function confirmMatch(Request $request, SalesImportRow $salesImportRow): JsonResponse
    {
        $validated = $request->validate([
            'matched_ca_id' => ['nullable', 'integer', 'min:1'],
            'matched_reference_firm_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $row = $this->reviewService->confirmMatch($salesImportRow, $request->user(), $validated);
        } catch (AccessDeniedHttpException $e) {
            return ApiResponse::error($e->getMessage(), 403);
        } catch (ValidationException $e) {
            return ApiResponse::error($e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Unable to confirm match.', 500);
        }

        return ApiResponse::success($this->serializeRow($row, true), 'Match confirmed');
    }

    public function acceptBestCandidate(Request $request, SalesImportRow $salesImportRow): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $row = $this->reviewService->acceptBestCandidate(
                $salesImportRow,
                $request->user(),
                $validated['reason'] ?? null
            );
        } catch (AccessDeniedHttpException $e) {
            return ApiResponse::error($e->getMessage(), 403);
        } catch (ValidationException $e) {
            return ApiResponse::error($e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Unable to accept candidate.', 500);
        }

        return ApiResponse::success($this->serializeRow($row, true), 'Top candidate accepted');
    }

    public function acceptAllMatched(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee' => ['nullable', 'string', 'max:255'],
            'import_batch_id' => ['nullable', 'integer', 'min:1'],
            'source_file_name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->reviewService->acceptAllMatched(
                $request->user(),
                $validated['employee'] ?? null,
                isset($validated['import_batch_id']) ? (int) $validated['import_batch_id'] : null,
                $validated['source_file_name'] ?? null,
            );
        } catch (AccessDeniedHttpException $e) {
            return ApiResponse::error($e->getMessage(), 403);
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Unable to accept matched rows.', 500);
        }

        return ApiResponse::success($result, 'Accepted '.$result['accepted'].' matched row(s)');
    }

    public function markUnmatched(Request $request, SalesImportRow $salesImportRow): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $row = $this->reviewService->markUnmatched($salesImportRow, $request->user(), $validated);
        } catch (AccessDeniedHttpException $e) {
            return ApiResponse::error($e->getMessage(), 403);
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Unable to mark unmatched.', 500);
        }

        return ApiResponse::success($this->serializeRow($row, true), 'Marked unmatched');
    }

    public function ignore(Request $request, SalesImportRow $salesImportRow): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $row = $this->reviewService->ignore($salesImportRow, $request->user(), $validated);
        } catch (AccessDeniedHttpException $e) {
            return ApiResponse::error($e->getMessage(), 403);
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Unable to ignore row.', 500);
        }

        return ApiResponse::success($this->serializeRow($row, true), 'Row ignored');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRow(SalesImportRow $row, bool $detailed = false): array
    {
        $candidates = $row->match_candidates ?? [];
        $payload = [
            'id' => $row->id,
            'import_batch_id' => $row->import_batch_id,
            'source_row_number' => $row->source_row_number,
            'source_file_name' => $row->source_file_name,
            'source_sheet_name' => $row->source_sheet_name,
            'employee_name' => $row->employee_name,
            'call_date' => $row->call_date?->format('Y-m-d'),
            'ca_name' => $row->ca_name,
            'firm_name' => $row->firm_name,
            'city_name' => $row->city_name,
            'mobile_no' => $row->mobile_no,
            'alternate_mobile_no' => $row->alternate_mobile_no,
            'remarks_1' => $row->remarks_1,
            'remarks_2' => $row->remarks_2,
            'normalized_firm_name' => $row->normalized_firm_name,
            'normalized_city' => $row->normalized_city,
            'normalized_ca_name' => $row->normalized_ca_name,
            'mapping_status' => $row->mapping_status,
            'matched_ca_id' => $row->matched_ca_id,
            'matched_reference_firm_id' => $row->matched_reference_firm_id ?? null,
            'matched_on' => $row->matched_on,
            'match_score' => $row->match_score,
            'review_reason' => $row->review_reason,
            'candidate_count' => is_array($candidates) ? count($candidates) : 0,
            'match_candidates' => $candidates,
            'mapped_at' => $row->mapped_at?->toIso8601String(),
            'matched_ca' => $row->ca ? [
                'ca_id' => $row->ca->ca_id,
                'ca_name' => $row->ca->ca_name,
                'firm_name' => $row->ca->firm_name,
                'mobile_no' => $row->ca->mobile_no,
                'city_name' => $row->ca->city?->city_name,
            ] : null,
            'mapped_to' => $row->matched_ca_id || ($row->matched_reference_firm_id ?? null) ? [
                'ca_id' => $row->matched_ca_id,
                'reference_firm_id' => $row->matched_reference_firm_id ?? null,
                'ca_name' => $row->ca?->ca_name,
                'firm_name' => $row->ca?->firm_name,
                'city_name' => $row->ca?->city?->city_name,
            ] : null,
        ];

        if ($detailed) {
            $payload['raw_payload'] = $row->raw_payload;
            $payload['matched_ca'] = $row->ca ? [
                'ca_id' => $row->ca->ca_id,
                'ca_name' => $row->ca->ca_name,
                'firm_name' => $row->ca->firm_name,
                'mobile_no' => $row->ca->mobile_no,
                'alternate_mobile_no' => $row->ca->alternate_mobile_no ?? null,
                'email_id' => $row->ca->email_id ?? null,
                'city_name' => $row->ca->city?->city_name,
            ] : null;
        }

        return $payload;
    }
}
