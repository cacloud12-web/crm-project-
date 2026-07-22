<?php

namespace App\Http\Controllers\Mapping;

use App\Http\Controllers\Controller;
use App\Models\SalesImportRow;
use App\Services\Mapping\SalesImportReviewService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

class SalesImportController extends Controller
{
    public function __construct(
        private readonly SalesImportReviewService $reviewService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:matched,needs_review,unmatched,pending,ignored'],
            'employee' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $status = $validated['status'] ?? null;
        $employee = trim((string) ($validated['employee'] ?? ''));
        $search = trim((string) ($validated['search'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 25);

        $query = SalesImportRow::query()
            ->with([
                'ca:ca_id,ca_name,firm_name,mobile_no,city_id',
                'ca.city:city_id,city_name',
            ])
            ->when(
                $status !== null,
                fn ($builder) => $builder->where('mapping_status', $status)
            )
            ->when(
                $employee !== '',
                fn ($builder) => $builder->where('employee_name', $employee)
            )
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
        ]);
        $employee = trim((string) ($validated['employee'] ?? ''));

        $base = SalesImportRow::query()
            ->when($employee !== '', fn ($builder) => $builder->where('employee_name', $employee));

        $counts = (clone $base)
            ->selectRaw('mapping_status, COUNT(*) as total')
            ->groupBy('mapping_status')
            ->pluck('total', 'mapping_status');

        return ApiResponse::success([
            'total' => (clone $base)->count(),
            'matched' => (int) ($counts['matched'] ?? 0),
            'needs_review' => (int) ($counts['needs_review'] ?? 0),
            'unmatched' => (int) ($counts['unmatched'] ?? 0),
            'pending' => (int) ($counts['pending'] ?? 0),
            'ignored' => (int) ($counts['ignored'] ?? 0),
            'selected_employee' => $employee !== '' ? $employee : null,
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
        ]);

        try {
            $result = $this->reviewService->acceptAllMatched(
                $request->user(),
                $validated['employee'] ?? null
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
