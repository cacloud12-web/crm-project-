<?php

namespace App\Services\Leads;

use App\Models\CaMaster;
use App\Models\DuplicateAttempt;
use App\Models\Employee;
use App\Models\User;
use App\Repositories\Leads\LeadPhoneNumberRepository;
use App\Services\Notifications\NotificationService;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Support\Database\SqlDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

class DuplicateAttemptService
{
    public function __construct(
        private readonly PhoneNormalizationService $phoneNormalization,
        private readonly LeadPhoneNumberRepository $phoneNumbers,
        private readonly EmployeeDataScopeService $employeeDataScope,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * @param  array<string, mixed>  $duplicateInfo
     */
    public function logDuplicate(
        array $duplicateInfo,
        string $rawNumber,
        ?User $actor = null,
        ?int $leadId = null,
        ?string $fieldName = null,
        ?string $browser = null,
    ): DuplicateAttempt {
        $normalized = $this->phoneNormalization->normalize($rawNumber) ?? preg_replace('/\D/', '', $rawNumber);
        $existingLead = CaMaster::query()->find($duplicateInfo['existing_lead']['ca_id'] ?? null);

        $attempt = DuplicateAttempt::query()->create([
            'employee_id' => $this->employeeDataScope->resolveEmployeeId($actor ?? auth()->user()),
            'lead_id' => $leadId,
            'duplicate_number' => $normalized ?: $rawNumber,
            'matched_lead_id' => $existingLead?->ca_id,
            'attempt_type' => DuplicateAttempt::TYPE_DUPLICATE,
            'status' => DuplicateAttempt::STATUS_OPEN,
            'field_name' => $fieldName,
            'browser' => $browser ?? $this->browserLabel(),
            'ip' => Request::ip(),
        ]);

        Log::info('Duplicate attempt logged', [
            'attempt_id' => $attempt->id,
            'employee_id' => $attempt->employee_id,
            'duplicate_number' => $attempt->duplicate_number,
            'matched_lead_id' => $attempt->matched_lead_id,
        ]);

        $this->refreshProductivity($attempt->employee_id);
        $this->notifyThresholdIfNeeded($attempt->employee_id);

        return $attempt;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function checkSimilar(mixed $mobile, ?int $excludeCaId = null): ?array
    {
        $normalized = $this->phoneNormalization->normalize($mobile);
        $prefixLength = (int) config('crm_duplicates.similar_prefix_length', 7);

        if (! $normalized || strlen($normalized) < $prefixLength) {
            return null;
        }

        $prefix = substr($normalized, 0, $prefixLength);

        $match = DB::table('lead_phone_numbers')
            ->join('ca_masters', 'ca_masters.ca_id', '=', 'lead_phone_numbers.ca_id')
            ->where('lead_phone_numbers.normalized_number', 'like', $prefix.'%')
            ->where('lead_phone_numbers.normalized_number', '!=', $normalized)
            ->when($excludeCaId, fn ($q) => $q->where('lead_phone_numbers.ca_id', '!=', $excludeCaId))
            ->select([
                'ca_masters.ca_id',
                'ca_masters.firm_name',
                'ca_masters.ca_name',
                'ca_masters.mobile_no',
                'lead_phone_numbers.normalized_number',
            ])
            ->orderByDesc('ca_masters.ca_id')
            ->first();

        if (! $match) {
            return null;
        }

        return [
            'match_type' => 'potential_duplicate',
            'message' => 'Potential duplicate — similar number exists in CRM.',
            'prefix' => $prefix,
            'existing_lead' => [
                'ca_id' => (int) $match->ca_id,
                'firm_name' => $match->firm_name,
                'ca_name' => $match->ca_name,
                'mobile_no' => $match->mobile_no,
            ],
            'existing_number' => $match->normalized_number,
        ];
    }

    /**
     * @param  array<string, mixed>  $similarInfo
     */
    public function logPotentialDuplicate(
        array $similarInfo,
        string $rawNumber,
        ?User $actor = null,
        ?int $leadId = null,
        ?string $fieldName = null,
        ?string $browser = null,
    ): DuplicateAttempt {
        $normalized = $this->phoneNormalization->normalize($rawNumber) ?? preg_replace('/\D/', '', $rawNumber);

        $attempt = DuplicateAttempt::query()->create([
            'employee_id' => $this->employeeDataScope->resolveEmployeeId($actor ?? auth()->user()),
            'lead_id' => $leadId,
            'duplicate_number' => $normalized ?: $rawNumber,
            'matched_lead_id' => $similarInfo['existing_lead']['ca_id'] ?? null,
            'attempt_type' => DuplicateAttempt::TYPE_POTENTIAL_DUPLICATE,
            'status' => DuplicateAttempt::STATUS_OPEN,
            'field_name' => $fieldName,
            'browser' => $browser ?? $this->browserLabel(),
            'ip' => Request::ip(),
        ]);

        Log::info('Potential duplicate attempt logged', [
            'attempt_id' => $attempt->id,
            'employee_id' => $attempt->employee_id,
            'duplicate_number' => $attempt->duplicate_number,
        ]);

        $this->refreshProductivity($attempt->employee_id);

        return $attempt;
    }

    public function markNumberChanged(int $attemptId, ?string $finalNumber = null): void
    {
        $attempt = DuplicateAttempt::query()->find($attemptId);
        if (! $attempt) {
            return;
        }

        $attempt->update([
            'status' => DuplicateAttempt::STATUS_CHANGED,
            'number_changed' => true,
            'saved_number' => $finalNumber
                ? ($this->phoneNormalization->normalize($finalNumber) ?? $finalNumber)
                : $attempt->saved_number,
            'resolved_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $savedData
     */
    public function resolveOnLeadSave(
        ?int $employeeId,
        int $leadId,
        array $savedData,
    ): void {
        if (! $employeeId) {
            return;
        }

        $savedNumbers = array_values(array_filter([
            isset($savedData['mobile_no']) ? $this->phoneNormalization->normalize($savedData['mobile_no']) : null,
            isset($savedData['alternate_mobile_no']) ? $this->phoneNormalization->normalize($savedData['alternate_mobile_no']) : null,
        ]));

        $query = DuplicateAttempt::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', [DuplicateAttempt::STATUS_OPEN, DuplicateAttempt::STATUS_CHANGED])
            ->where(function (Builder $q) use ($leadId) {
                $q->where('lead_id', $leadId)->orWhereNull('lead_id');
            })
            ->where('created_at', '>=', now()->subHours(6));

        $attempts = $query->get();

        foreach ($attempts as $attempt) {
            $changed = ! in_array($attempt->duplicate_number, $savedNumbers, true);
            $attempt->update([
                'lead_id' => $leadId,
                'saved_number' => $savedNumbers[0] ?? $attempt->saved_number,
                'status' => DuplicateAttempt::STATUS_RESOLVED,
                'number_changed' => $changed,
                'resolved_at' => now(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboardMetrics(): array
    {
        $today = now()->startOfDay();
        $weekStart = now()->startOfWeek();
        $monthStart = now()->startOfMonth();

        $base = DuplicateAttempt::query();

        $recent = (clone $base)
            ->with(['employee:employee_id,name', 'matchedLead:ca_id,firm_name,mobile_no'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (DuplicateAttempt $row) => $this->toListArray($row))
            ->all();

        $topEmployees = DuplicateAttempt::query()
            ->select('employee_id', DB::raw('COUNT(*) as attempt_count'))
            ->where('created_at', '>=', $monthStart)
            ->whereNotNull('employee_id')
            ->groupBy('employee_id')
            ->orderByDesc('attempt_count')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                $employee = Employee::query()->find($row->employee_id);

                return [
                    'employee_id' => (int) $row->employee_id,
                    'employee_name' => $employee?->name ?? 'Unknown',
                    'attempt_count' => (int) $row->attempt_count,
                ];
            })
            ->all();

        return [
            'today' => (clone $base)->where('created_at', '>=', $today)->count(),
            'this_week' => (clone $base)->where('created_at', '>=', $weekStart)->count(),
            'this_month' => (clone $base)->where('created_at', '>=', $monthStart)->count(),
            'total' => (clone $base)->count(),
            'duplicate_count' => (clone $base)->where('attempt_type', DuplicateAttempt::TYPE_DUPLICATE)->count(),
            'potential_duplicate_count' => (clone $base)->where('attempt_type', DuplicateAttempt::TYPE_POTENTIAL_DUPLICATE)->count(),
            'top_employees' => $topEmployees,
            'recent' => $recent,
            'trend' => $this->monthlyTrend(),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function search(array $params = []): array
    {
        $query = DuplicateAttempt::query()
            ->with(['employee:employee_id,name', 'matchedLead:ca_id,firm_name,mobile_no', 'lead:ca_id,firm_name'])
            ->orderByDesc('created_at');

        if (! empty($params['employee_id'])) {
            $query->where('employee_id', (int) $params['employee_id']);
        }

        if (! empty($params['attempt_type'])) {
            $query->where('attempt_type', $params['attempt_type']);
        }

        if (! empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        if (! empty($params['from'])) {
            $query->whereDate('created_at', '>=', $params['from']);
        }

        if (! empty($params['to'])) {
            $query->whereDate('created_at', '<=', $params['to']);
        }

        if (! empty($params['search'])) {
            $term = '%'.addcslashes(trim((string) $params['search']), '%_\\').'%';
            $query->where(function (Builder $q) use ($term) {
                $q->where('duplicate_number', 'like', $term)
                    ->orWhere('saved_number', 'like', $term)
                    ->orWhereHas('employee', fn ($eq) => $eq->where('name', 'ilike', $term))
                    ->orWhereHas('matchedLead', fn ($lq) => $lq->where('firm_name', 'ilike', $term));
            });
        }

        $perPage = min(max((int) ($params['per_page'] ?? 25), 1), 100);
        $paginator = $query->paginate($perPage);

        return [
            'items' => collect($paginator->items())->map(fn (DuplicateAttempt $row) => $this->toListArray($row))->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function exportRows(array $params = []): Collection
    {
        $result = $this->search(array_merge($params, ['per_page' => 5000]));

        return collect($result['items']);
    }

    /**
     * @return array<string, mixed>
     */
    private function toListArray(DuplicateAttempt $row): array
    {
        return [
            'id' => $row->id,
            'employee_id' => $row->employee_id,
            'employee_name' => $row->employee?->name,
            'lead_id' => $row->lead_id,
            'lead_name' => $row->lead?->firm_name,
            'duplicate_number' => $row->duplicate_number,
            'saved_number' => $row->saved_number,
            'matched_lead_id' => $row->matched_lead_id,
            'existing_lead_name' => $row->matchedLead?->firm_name,
            'existing_lead_mobile' => $row->matchedLead?->mobile_no,
            'attempt_type' => $row->attempt_type,
            'status' => $row->status,
            'field_name' => $row->field_name,
            'number_changed' => (bool) $row->number_changed,
            'browser' => $row->browser,
            'ip' => $row->ip,
            'attempted_at' => $row->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{month: string, count: int}>
     */
    private function monthlyTrend(): array
    {
        return DuplicateAttempt::query()
            ->selectRaw(SqlDate::yearMonthLabel('created_at'))
            ->selectRaw('COUNT(*) as count')
            ->where('created_at', '>=', now()->subMonths(6)->startOfMonth())
            ->groupBy(DB::raw(SqlDate::yearMonthBucket('created_at')))
            ->orderBy('month')
            ->get()
            ->map(fn ($row) => ['month' => $row->month, 'count' => (int) $row->count])
            ->all();
    }

    private function browserLabel(): ?string
    {
        $agent = Request::userAgent();

        return $agent ? substr($agent, 0, 255) : null;
    }

    private function refreshProductivity(?int $employeeId): void
    {
        if ($employeeId) {
            app(EmployeeProductivityService::class)->refreshDailySnapshot($employeeId);
        }
    }

    private function notifyThresholdIfNeeded(?int $employeeId): void
    {
        if (! $employeeId) {
            return;
        }

        $threshold = (int) config('crm_duplicates.manager_notification_threshold', 5);
        $todayCount = DuplicateAttempt::query()
            ->where('employee_id', $employeeId)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        if ($todayCount < $threshold || $todayCount % $threshold !== 0) {
            return;
        }

        $employee = Employee::query()->find($employeeId);
        $name = $employee?->name ?? 'Employee #'.$employeeId;

        $this->notificationService->notifyManagement(
            'duplicate_attempt_threshold',
            'Duplicate attempt alert',
            "{$name} attempted duplicate numbers {$todayCount} times today.",
            [
                'employee_id' => $employeeId,
                'count_today' => $todayCount,
                'link' => '/duplicate-attempts',
            ],
        );
    }
}
