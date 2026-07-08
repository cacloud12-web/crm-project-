<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\UpdateSalesListRequest;
use App\Http\Resources\SalesListResource;
use App\Models\SalesListEntry;
use App\Services\Listing\ListingExportService;
use App\Services\Sales\SalesListService;
use App\Support\ApiResponse;
use App\Support\Listing\ListingQueryApplier;
use App\Support\Listing\ListingResponse;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesListController extends Controller
{
    public function __construct(
        private readonly SalesListService $salesListService,
        private readonly ListingExportService $listingExportService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->salesListService->search($request->query());

        return ListingResponse::from($result, SalesListResource::class, 'Sales list loaded');
    }

    public function options(): JsonResponse
    {
        $this->salesListService->assertCanAccess();

        return ApiResponse::success($this->salesListService->filterOptions(), 'Sales list options loaded');
    }

    public function show(string $id): JsonResponse
    {
        $entry = $this->salesListService->find($id);

        return ApiResponse::success(new SalesListResource($entry), 'Sales record loaded');
    }

    public function update(UpdateSalesListRequest $request, string $id): JsonResponse
    {
        $entry = $this->salesListService->update(
            SalesListEntry::query()->findOrFail($id),
            $request->validated(),
        );

        return ApiResponse::success(new SalesListResource($entry), 'Sales record updated');
    }

    public function history(string $id): JsonResponse
    {
        $this->salesListService->find($id);

        return ApiResponse::success(
            $this->salesListService->editHistory($id),
            'Sales edit history loaded',
        );
    }

    public function export(Request $request): StreamedResponse|Response
    {
        $this->salesListService->assertCanAccess();

        if (strtolower((string) $request->query('format', 'csv')) === 'pdf') {
            $result = $this->salesListService->search(array_merge($request->query(), ['all' => true]));
            $rows = collect($result['items'] ?? []);

            $html = '<html><head><style>body{font-family:DejaVu Sans,sans-serif;font-size:9px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ccc;padding:4px;text-align:left}</style></head><body>';
            $html .= '<h2>Sales List</h2><table><thead><tr>';
            foreach (['S.No', 'Month', 'Customer', 'Firm', 'Mobile', 'City', 'Plan', 'Purchase Date', 'Total', 'Received', 'Balance', 'Invoice', 'Status', 'Executive'] as $heading) {
                $html .= '<th>'.htmlspecialchars($heading).'</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $resource = (new SalesListResource($row))->resolve();
                $html .= '<tr>';
                foreach (['serial_number', 'sale_month', 'customer_name', 'firm_name', 'mobile_no', 'city_name', 'plan_purchased', 'purchase_date', 'total_amount', 'amount_received', 'balance_amount', 'invoice_number', 'payment_status', 'employee_name'] as $key) {
                    $html .= '<td>'.htmlspecialchars((string) ($resource[$key] ?? '')).'</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table></body></html>';

            $options = new Options;
            $options->set('isHtml5ParserEnabled', true);
            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();

            return response($dompdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="sales-list-'.now()->format('Y-m-d').'.pdf"',
            ]);
        }

        return $this->listingExportService->exportCsv(
            SalesListEntry::query()->with(['employee', 'manager']),
            $request->query(),
            ListingQueryApplier::config('sales_list'),
            [
                'serial_number' => 'S.No',
                'sale_month' => 'Month',
                'points' => 'Point',
                'customer_name' => 'Customer Name',
                'firm_name' => 'Firm Name',
                'reference_name' => 'Reference',
                'mobile_no' => 'Mobile Number',
                'city_name' => 'City',
                'plan_purchased' => 'Plan Purchased',
                'purchase_date' => 'Purchase Date',
                'cooling_period_days' => 'Cooling Period',
                'expiry_date' => 'Expiry Date',
                'total_amount' => 'Total Amount',
                'amount_received' => 'Amount Received',
                'balance_amount' => 'Balance Amount',
                'invoice_number' => 'Invoice Number',
                'payment_status' => 'Payment Status',
                'employee.name' => 'Sales Executive',
                'manager.name' => 'Assigned Manager',
            ],
        );
    }
}
