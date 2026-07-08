<?php

namespace App\Services\Leads;

use App\Exceptions\DuplicateLeadException;
use App\Models\CaMaster;
use App\Models\DuplicateAttempt;
use App\Models\DuplicateAttemptLog;
use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Models\User;
use App\Repositories\Leads\LeadPhoneNumberRepository;
use App\Services\Leads\DuplicateDetection\AttributeDuplicateStrategy;
use App\Services\Leads\DuplicateDetection\PhoneDuplicateStrategy;
use App\Services\Rbac\EmployeeDataScopeService;
use Illuminate\Support\Facades\Request;

class DuplicateLeadDetectionService
{
    /** @var list<AttributeDuplicateStrategy> */
    private array $attributeStrategies = [];

    public function __construct(
        private readonly PhoneNormalizationService $phoneNormalization,
        private readonly PhoneDuplicateStrategy $phoneStrategy,
        private readonly LeadFieldNormalizationService $fieldNormalization,
        private readonly LeadPhoneNumberRepository $phoneNumbers,
        private readonly EmployeeDataScopeService $employeeDataScope,
    ) {
        foreach (config('crm_duplicates.attribute_strategies', []) as $key => $cfg) {
            $this->attributeStrategies[] = new AttributeDuplicateStrategy(
                $key,
                $cfg['field'],
                $cfg['column'],
                fn (mixed $value) => match ($key) {
                    'email' => $this->fieldNormalization->normalizeEmail($value),
                    'gst' => $this->fieldNormalization->normalizeGst($value),
                    'pan' => $this->fieldNormalization->normalizePan($value),
                    'website' => $this->fieldNormalization->normalizeWebsite($value),
                    'google_place_id' => $this->fieldNormalization->normalizePlaceId($value),
                    default => filled($value) ? trim((string) $value) : null,
                },
            );
        }
    }

    public function normalize(mixed $value): ?string
    {
        return $this->phoneNormalization->normalize($value);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function assertNoDuplicatesForSave(array $data, ?CaMaster $existing = null, ?User $actor = null): void
    {
        $excludeCaId = $existing?->ca_id ? (int) $existing->ca_id : null;

        $this->assertNoPhoneDuplicates($data, $excludeCaId, $actor);
        $this->assertNoAttributeDuplicates($data, $excludeCaId, $actor);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function checkMobile(mixed $mobile, ?int $excludeCaId = null): ?array
    {
        $inspection = $this->phoneStrategy->inspect($mobile, $excludeCaId);

        if (! $inspection['normalized'] || ! $inspection['duplicate']) {
            return null;
        }

        return $this->buildDuplicateInfo(
            $inspection['duplicate'],
            'phone',
            $inspection['normalized'],
        );
    }

    public function syncLeadPhones(CaMaster $lead): void
    {
        $this->phoneNumbers->syncForLead((int) $lead->ca_id, [
            'primary' => $lead->normalized_mobile,
            'alternate' => $lead->normalized_alternate_mobile,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function applyNormalizedFields(array $data, ?CaMaster $existing = null): array
    {
        $data = $this->applyNormalizedPhones($data, $existing);

        return $this->fieldNormalization->applyToLeadData($data, $existing);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function applyNormalizedPhones(array $data, ?CaMaster $existing = null): array
    {
        if (array_key_exists('mobile_no', $data)) {
            $data['normalized_mobile'] = $this->normalize($data['mobile_no'])
                ?? app(PhoneClassificationService::class)->digitsOnly($data['mobile_no']);
        } elseif ($existing) {
            $data['normalized_mobile'] = $existing->normalized_mobile;
        }

        if (array_key_exists('alternate_mobile_no', $data)) {
            $data['normalized_alternate_mobile'] = $this->normalize($data['alternate_mobile_no'])
                ?? app(PhoneClassificationService::class)->digitsOnly($data['alternate_mobile_no']);
        } elseif ($existing) {
            $data['normalized_alternate_mobile'] = $existing->normalized_alternate_mobile;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDuplicateInfo(CaMaster $existingLead, string $matchType, ?string $attemptedValue = null): array
    {
        $existingLead->loadMissing(['state', 'city', 'createdByEmployee']);

        $creator = $existingLead->createdByEmployee
            ?: ($existingLead->created_by_employee_id
                ? Employee::query()->find($existingLead->created_by_employee_id)
                : null);

        $assignment = LeadAssignmentEngine::query()
            ->with('employee')
            ->where('ca_id', $existingLead->ca_id)
            ->where('status', 'Active')
            ->first();

        $message = config("crm_duplicates.messages.{$matchType}")
            ?? config('crm_duplicates.messages.default');

        return [
            'match_type' => $matchType,
            'attempted_value' => $attemptedValue,
            'attempted_mobile' => $attemptedValue,
            'message' => $message,
            'existing_lead' => [
                'ca_id' => $existingLead->ca_id,
                'firm_name' => $existingLead->firm_name,
                'ca_name' => $existingLead->ca_name,
                'mobile_no' => $existingLead->mobile_no,
                'status' => $existingLead->status,
                'added_by' => $creator?->name ?? 'Unknown',
                'added_by_employee_id' => $creator?->employee_id,
                'added_at' => $existingLead->created_at?->toIso8601String(),
                'assigned_executive' => $assignment?->employee?->name,
                'assigned_executive_id' => $assignment?->employee_id,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertNoPhoneDuplicates(array $data, ?int $excludeCaId, ?User $actor): void
    {
        $numbers = $this->extractCandidateNumbers($data);

        foreach ($numbers as $field => $raw) {
            $inspection = $this->phoneStrategy->inspect($raw, $excludeCaId);

            if (! $inspection['normalized'] || ! $inspection['duplicate']) {
                continue;
            }

            $this->logAttempt(
                $inspection['duplicate'],
                $actor,
                'duplicate_'.$field,
                attemptedMobile: $inspection['normalized'],
            );

            throw new DuplicateLeadException(
                config('crm_duplicates.messages.phone'),
                $this->buildDuplicateInfo($inspection['duplicate'], 'phone', $inspection['normalized']),
            );
        }

        $unique = array_values(array_unique(array_filter(array_values(
            array_map(fn ($raw) => $this->normalize($raw), $numbers),
        ))));

        if (count($unique) < count(array_filter($numbers))) {
            throw new DuplicateLeadException(
                'Primary and alternate mobile cannot be the same number.',
                ['attempted_mobile' => $unique[0] ?? null],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertNoAttributeDuplicates(array $data, ?int $excludeCaId, ?User $actor): void
    {
        foreach ($this->attributeStrategies as $strategy) {
            if (! array_key_exists($strategy->inputField(), $data)) {
                continue;
            }

            $inspection = $strategy->inspect($data[$strategy->inputField()], $excludeCaId);

            if (! $inspection['normalized'] || ! $inspection['duplicate']) {
                continue;
            }

            $this->logAttempt(
                $inspection['duplicate'],
                $actor,
                config('crm_duplicates.attribute_strategies.'.$strategy->key().'.reason', 'duplicate_'.$strategy->key()),
                attemptedValue: $inspection['normalized'],
                matchKey: $strategy->key(),
            );

            throw new DuplicateLeadException(
                config("crm_duplicates.messages.{$strategy->key()}") ?? config('crm_duplicates.messages.default'),
                $this->buildDuplicateInfo($inspection['duplicate'], $strategy->key(), $inspection['normalized']),
            );
        }
    }

    private function logAttempt(
        CaMaster $existingLead,
        ?User $actor,
        string $reason,
        ?string $attemptedMobile = null,
        ?string $attemptedValue = null,
        ?string $matchKey = null,
    ): void {
        $payload = [
            'employee_id' => $this->employeeDataScope->resolveEmployeeId($actor ?? auth()->user()),
            'lead_id' => $existingLead->ca_id,
            'attempted_mobile' => $attemptedMobile ?? 'n/a',
            'attempted_at' => now(),
            'reason' => $reason,
            'ip_address' => Request::ip(),
        ];

        $value = $attemptedValue ?? $attemptedMobile;

        match ($matchKey) {
            'email' => $payload['attempted_email'] = $value,
            'gst' => $payload['attempted_gst'] = $value,
            'pan' => $payload['attempted_pan'] = $value,
            'website' => $payload['attempted_website'] = $value,
            'google_place_id' => $payload['attempted_place_id'] = $value,
            default => null,
        };

        DuplicateAttemptLog::query()->create($payload);

        if ($attemptedMobile && $attemptedMobile !== 'n/a') {
            DuplicateAttempt::query()->create([
                'employee_id' => $payload['employee_id'],
                'lead_id' => $existingLead->ca_id,
                'duplicate_number' => $attemptedMobile,
                'matched_lead_id' => $existingLead->ca_id,
                'attempt_type' => DuplicateAttempt::TYPE_DUPLICATE,
                'status' => DuplicateAttempt::STATUS_OPEN,
                'field_name' => str_contains($reason, 'alternate') ? 'alternate_mobile_no' : 'mobile_no',
                'ip' => $payload['ip_address'],
                'browser' => Request::userAgent() ? substr((string) Request::userAgent(), 0, 255) : null,
            ]);
        }

        $employeeId = $payload['employee_id'];
        if ($employeeId) {
            app(EmployeeProductivityService::class)->refreshDailySnapshot((int) $employeeId);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function extractCandidateNumbers(array $data): array
    {
        $numbers = [];

        if (array_key_exists('mobile_no', $data)) {
            $numbers['mobile_no'] = $data['mobile_no'];
        }

        if (array_key_exists('alternate_mobile_no', $data)) {
            $numbers['alternate_mobile_no'] = $data['alternate_mobile_no'];
        }

        return array_filter($numbers, fn ($value) => $value !== null && trim((string) $value) !== '');
    }
}
