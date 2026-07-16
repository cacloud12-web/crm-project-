<?php

namespace App\Services\Sales;

use App\Http\Resources\SalesListEditHistoryResource;
use App\Models\AssignmentHistory;
use App\Models\Employee;
use App\Models\PurchasedCustomer;
use App\Models\SalesListEditHistory;
use App\Models\SalesListEntry;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use App\Services\Cache\CrmCacheService;
use App\Services\Concerns\SearchesListings;
use App\Services\Rbac\RbacService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class SalesListService
{
    use SearchesListings;

    /** @var array<string, string> */
    public const FIELD_LABELS = [
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
        'employee_id' => 'Sales Executive',
        'manager_id' => 'Assigned Manager',
        'sale_month' => 'Month',
        'notes' => 'Remarks/Notes',
    ];

    /** @var list<string> */
    private const AUDITABLE_FIELDS = [
        'points',
        'customer_name',
        'firm_name',
        'reference_name',
        'mobile_no',
        'city_name',
        'plan_purchased',
        'purchase_date',
        'cooling_period_days',
        'expiry_date',
        'total_amount',
        'amount_received',
        'balance_amount',
        'invoice_number',
        'payment_status',
        'employee_id',
        'manager_id',
        'sale_month',
        'notes',
    ];

    public function __construct(
        private readonly RbacService $rbacService,
        private readonly ActivityLogService $activityLogService,
        private readonly CrmCacheService $cacheService,
    ) {}

    public static function fieldLabel(string $fieldName): string
    {
        return self::FIELD_LABELS[$fieldName] ?? ucwords(str_replace('_', ' ', $fieldName));
    }

    public function assertCanAccess(?User $user = null): void
    {
        $user ??= auth()->user();
        if (! in_array($this->rbacService->roleKey($user), ['super_admin', 'manager'], true)) {
            throw new AuthorizationException('You do not have permission to access the Sales List.');
        }
    }

    public function assertCanEdit(?User $user = null): void
    {
        $this->assertCanAccess($user);
    }

    public function assertCanViewHistory(?User $user = null): void
    {
        $user ??= auth()->user();
        if ($this->rbacService->roleKey($user) !== 'super_admin') {
            throw new AuthorizationException('Only Super Admin can view sales edit history.');
        }
    }

    public function search(array $params = []): array
    {
        $this->assertCanAccess();

        return $this->searchListing(
            SalesListEntry::query()->with(['employee', 'manager', 'lead.city']),
            $params,
            'sales_list',
        );
    }

    public function find(int|string $id): SalesListEntry
    {
        $this->assertCanAccess();

        return SalesListEntry::query()
            ->with(['employee', 'manager', 'lead.city'])
            ->findOrFail($id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function editHistory(int|string $entryId): array
    {
        $this->assertCanViewHistory();

        $histories = SalesListEditHistory::query()
            ->with('user')
            ->where('sales_list_entry_id', $entryId)
            ->orderByDesc('edited_at')
            ->orderByDesc('id')
            ->get();

        return SalesListEditHistoryResource::collection($histories)->resolve();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SalesListEntry $entry, array $data): SalesListEntry
    {
        $this->assertCanEdit();

        $before = $this->snapshotForAudit($entry);
        $planName = (string) ($data['plan_purchased'] ?? $entry->plan_purchased);
        $plan = $this->planConfig($planName);

        if (array_key_exists('plan_purchased', $data) && $data['plan_purchased']) {
            if (! array_key_exists('cooling_period_days', $data)) {
                $data['cooling_period_days'] = $plan['cooling_period_days'];
            }
            if (! array_key_exists('points', $data)) {
                $data['points'] = $plan['points'];
            }
        }

        $purchaseDate = isset($data['purchase_date'])
            ? Carbon::parse($data['purchase_date'])
            : $entry->purchase_date;

        if (
            (array_key_exists('plan_purchased', $data) && $data['plan_purchased'])
            || (array_key_exists('purchase_date', $data) && $data['purchase_date'])
        ) {
            $data['expiry_date'] = $purchaseDate->copy()->addMonths($plan['duration_months'])->toDateString();
        }

        if (array_key_exists('purchase_date', $data) && $data['purchase_date']) {
            $data['sale_month'] = $this->formatSaleMonth($purchaseDate);
        }

        $total = (float) ($data['total_amount'] ?? $entry->total_amount);
        $received = (float) ($data['amount_received'] ?? $entry->amount_received);
        $expiryDate = $data['expiry_date'] ?? $entry->expiry_date?->toDateString();

        $data['balance_amount'] = max(0, round($total - $received, 2));
        $data['payment_status'] = $this->resolvePaymentStatus($total, $received, $expiryDate);

        $updates = collect($data)->only([
            'points',
            'customer_name',
            'firm_name',
            'reference_name',
            'mobile_no',
            'city_name',
            'plan_purchased',
            'purchase_date',
            'sale_month',
            'cooling_period_days',
            'expiry_date',
            'total_amount',
            'amount_received',
            'balance_amount',
            'invoice_number',
            'payment_status',
            'employee_id',
            'manager_id',
            'notes',
        ])->all();

        if ($updates === []) {
            return $entry->fresh(['employee', 'manager', 'lead.city']);
        }

        DB::transaction(function () use ($entry, $updates, $before) {
            $entry->update($updates);
            $entry->refresh();
            $this->recordEditHistory($entry, $before, $this->snapshotForAudit($entry));
        });

        $this->activityLogService->log(
            'SALES_LIST',
            'Sales Record Updated',
            (string) $entry->id,
            $entry->invoice_number.' · '.$entry->firm_name,
            beforeValue: $before,
            afterValue: $this->snapshotForAudit($entry),
        );

        $this->cacheService->bumpDashboardCacheVersion();

        return $entry->fresh(['employee', 'manager', 'lead.city']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function recordFromPurchase(PurchasedCustomer $purchase, array $overrides = []): SalesListEntry
    {
        if ($purchase->id && SalesListEntry::query()->where('purchased_customer_id', $purchase->id)->exists()) {
            return SalesListEntry::query()->where('purchased_customer_id', $purchase->id)->firstOrFail();
        }

        if ($purchase->demo_result_id && SalesListEntry::query()->where('ca_id', $purchase->ca_id)->where('demo_result_id', $purchase->demo_result_id)->exists()) {
            return SalesListEntry::query()
                ->where('ca_id', $purchase->ca_id)
                ->where('demo_result_id', $purchase->demo_result_id)
                ->firstOrFail();
        }

        $lead = $purchase->lead()->with('city')->first();
        $planName = (string) ($overrides['plan_purchased'] ?? $purchase->software_name ?: config('sales_plans.default_plan', 'CRM Annual'));
        $plan = $this->planConfig($planName);
        $purchaseDate = Carbon::parse($overrides['purchase_date'] ?? $purchase->purchase_date);
        $total = (float) ($overrides['total_amount'] ?? $plan['default_amount']);
        $received = (float) ($overrides['amount_received'] ?? 0);
        $coolingDays = (int) ($overrides['cooling_period_days'] ?? $plan['cooling_period_days']);
        $points = (int) ($overrides['points'] ?? $plan['points']);
        $expiryDate = $purchaseDate->copy()->addMonths($plan['duration_months'])->toDateString();
        $balance = max(0, round($total - $received, 2));
        $paymentStatus = $this->resolvePaymentStatus($total, $received, $expiryDate);
        $managerId = isset($overrides['manager_id']) && $overrides['manager_id'] !== ''
            ? (int) $overrides['manager_id']
            : $this->resolveManagerId($purchase);
        $employeeId = isset($overrides['employee_id']) && $overrides['employee_id'] !== ''
            ? (int) $overrides['employee_id']
            : $purchase->employee_id;

        return DB::transaction(function () use (
            $purchase,
            $lead,
            $planName,
            $purchaseDate,
            $total,
            $received,
            $coolingDays,
            $points,
            $expiryDate,
            $balance,
            $paymentStatus,
            $managerId,
            $employeeId,
            $overrides,
        ) {
            $serial = $this->nextSerialNumber();
            $invoice = trim((string) ($overrides['invoice_number'] ?? ''));
            if ($invoice === '') {
                $invoice = $this->nextInvoiceNumber();
            }

            $entry = SalesListEntry::query()->create([
                'serial_number' => $serial,
                'ca_id' => $purchase->ca_id,
                'purchased_customer_id' => $purchase->id,
                'demo_result_id' => $purchase->demo_result_id,
                'sale_month' => $this->formatSaleMonth($purchaseDate),
                'points' => $points,
                'customer_name' => $overrides['customer_name'] ?? $purchase->customer_name ?: $lead?->ca_name,
                'firm_name' => $overrides['firm_name'] ?? $purchase->firm_name ?: $lead?->firm_name,
                'reference_name' => $overrides['reference_name'] ?? $purchase->reference_employee_name,
                'mobile_no' => $overrides['mobile_no'] ?? $purchase->mobile_no ?: $lead?->mobile_no,
                'city_name' => $overrides['city_name'] ?? $lead?->city?->city_name,
                'plan_purchased' => $planName,
                'purchase_date' => $purchaseDate->toDateString(),
                'cooling_period_days' => $coolingDays,
                'expiry_date' => $expiryDate,
                'total_amount' => $total,
                'amount_received' => $received,
                'balance_amount' => $balance,
                'invoice_number' => $invoice,
                'payment_status' => $paymentStatus,
                'employee_id' => $employeeId,
                'manager_id' => $managerId,
                'notes' => $overrides['notes'] ?? $purchase->notes,
            ]);

            $this->activityLogService->log(
                'SALES_LIST',
                'Sale Recorded',
                (string) $entry->id,
                $entry->firm_name.' · '.$entry->invoice_number,
            );

            $this->cacheService->forgetDashboardMetrics();
            $this->cacheService->forgetLeadSegmentCounts();
            $this->cacheService->forgetPipelineStageCounts();
            $this->cacheService->forgetEmployeeRankings();
            if ($employeeId) {
                $this->cacheService->forgetEmployeeDashboard((int) $employeeId);
            }

            return $entry;
        });
    }

    /**
     * @return array{duration_months: int, cooling_period_days: int, points: int, default_amount: float}
     */
    public function planConfig(string $planName): array
    {
        $plans = config('sales_plans.plans', []);
        $plan = $plans[$planName] ?? $plans[config('sales_plans.default_plan')] ?? [
            'duration_months' => 12,
            'cooling_period_days' => 15,
            'points' => 1,
            'default_amount' => 0,
        ];

        return [
            'duration_months' => (int) ($plan['duration_months'] ?? 12),
            'cooling_period_days' => (int) ($plan['cooling_period_days'] ?? 0),
            'points' => (int) ($plan['points'] ?? 1),
            'default_amount' => (float) ($plan['default_amount'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filterOptions(): array
    {
        $this->assertCanAccess();

        return $this->cacheService->rememberSalesFilterOptions(fn () => $this->buildFilterOptions());
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFilterOptions(): array
    {
        $plans = $this->planOptions();
        $planConfigs = [];
        foreach ($plans as $planName) {
            $planConfigs[$planName] = $this->planConfig($planName);
        }

        return [
            'plans' => $plans,
            'plan_configs' => $planConfigs,
            'payment_statuses' => config('sales_plans.payment_statuses', []),
            'sale_months' => SalesListEntry::query()
                ->whereNotNull('sale_month')
                ->distinct()
                ->orderBy('sale_month')
                ->pluck('sale_month')
                ->all(),
            'executives' => Employee::query()
                ->whereIn('employee_id', SalesListEntry::query()->whereNotNull('employee_id')->select('employee_id'))
                ->orderBy('name')
                ->pluck('name')
                ->all(),
            'managers' => Employee::query()
                ->whereIn('employee_id', SalesListEntry::query()->whereNotNull('manager_id')->select('manager_id'))
                ->orderBy('name')
                ->pluck('name')
                ->all(),
            'executive_choices' => $this->employeeChoices(
                SalesListEntry::query()->whereNotNull('employee_id')->select('employee_id'),
            ),
            'manager_choices' => $this->employeeChoices(
                SalesListEntry::query()->whereNotNull('manager_id')->select('manager_id'),
            ),
            'cooling_periods' => SalesListEntry::query()
                ->whereNotNull('cooling_period_days')
                ->distinct()
                ->orderBy('cooling_period_days')
                ->pluck('cooling_period_days')
                ->all(),
        ];
    }

    /**
     * @return list<string>
     */
    public function planOptions(): array
    {
        return array_keys(config('sales_plans.plans', []));
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotForAudit(SalesListEntry $entry): array
    {
        return $entry->only(self::AUDITABLE_FIELDS);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function recordEditHistory(SalesListEntry $entry, array $before, array $after): void
    {
        $userId = auth()->id();
        $editedAt = now();
        $rows = [];

        foreach (self::AUDITABLE_FIELDS as $field) {
            $oldValue = $this->normalizeAuditValue($field, $before[$field] ?? null);
            $newValue = $this->normalizeAuditValue($field, $after[$field] ?? null);

            if ($oldValue === $newValue) {
                continue;
            }

            $rows[] = [
                'sales_list_entry_id' => $entry->id,
                'user_id' => $userId,
                'field_name' => $field,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'edited_at' => $editedAt,
                'created_at' => $editedAt,
                'updated_at' => $editedAt,
            ];
        }

        if ($rows !== []) {
            SalesListEditHistory::query()->insert($rows);
        }
    }

    private function normalizeAuditValue(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (in_array($field, ['employee_id', 'manager_id'], true)) {
            $employeeId = (int) $value;
            if ($employeeId <= 0) {
                return null;
            }

            return Employee::query()->where('employee_id', $employeeId)->value('name') ?: (string) $employeeId;
        }

        if ($field === 'purchase_date' || $field === 'expiry_date') {
            return Carbon::parse((string) $value)->toDateString();
        }

        if (in_array($field, ['total_amount', 'amount_received', 'balance_amount'], true)) {
            return number_format((float) $value, 2, '.', '');
        }

        if ($field === 'cooling_period_days' || $field === 'points') {
            return (string) (int) $value;
        }

        return (string) $value;
    }

    /**
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $employeeIdQuery
     * @return list<array{id: int, name: string}>
     */
    private function employeeChoices($employeeIdQuery): array
    {
        return Employee::query()
            ->whereIn('employee_id', $employeeIdQuery)
            ->orderBy('name')
            ->get(['employee_id', 'name'])
            ->map(fn (Employee $employee) => [
                'id' => (int) $employee->employee_id,
                'name' => (string) $employee->name,
            ])
            ->values()
            ->all();
    }

    private function nextSerialNumber(): int
    {
        $max = (int) SalesListEntry::query()->max('serial_number');

        return $max + 1;
    }

    private function nextInvoiceNumber(): string
    {
        $prefix = 'INV-'.now()->format('Ym').'-';
        $last = SalesListEntry::query()
            ->where('invoice_number', 'like', $prefix.'%')
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $seq = $last ? ((int) substr($last, -5)) + 1 : 1;

        do {
            $candidate = $prefix.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
            $seq++;
        } while (SalesListEntry::query()->where('invoice_number', $candidate)->exists());

        return $candidate;
    }

    private function formatSaleMonth(Carbon $date): string
    {
        return $date->format('M Y');
    }

    private function resolvePaymentStatus(float $total, float $received, ?string $expiryDate): string
    {
        $balance = max(0, round($total - $received, 2));

        if ($balance <= 0 && $total > 0) {
            return 'Paid';
        }

        if ($received > 0 && $balance > 0) {
            if ($expiryDate && $this->isExpiryDatePast($expiryDate)) {
                return 'Overdue';
            }

            return 'Partial';
        }

        if ($expiryDate && $this->isExpiryDatePast($expiryDate) && $total > 0) {
            return 'Overdue';
        }

        return 'Pending';
    }

    private function isExpiryDatePast(string $expiryDate): bool
    {
        // Overdue only after the expiry calendar day ends (expiry day itself remains Partial/Pending).
        return Carbon::parse($expiryDate)->startOfDay()->lt(now()->startOfDay());
    }

    private function resolveManagerId(PurchasedCustomer $purchase): ?int
    {
        if ($purchase->assigned_by_employee_id) {
            return (int) $purchase->assigned_by_employee_id;
        }

        if ($purchase->ca_id) {
            $assignedBy = AssignmentHistory::query()
                ->where('ca_id', $purchase->ca_id)
                ->whereNotNull('assigned_by')
                ->orderByDesc('assigned_at')
                ->value('assigned_by');

            if ($assignedBy) {
                return (int) $assignedBy;
            }
        }

        return null;
    }
}
