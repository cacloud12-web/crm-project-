<?php

namespace App\Http\Requests\FollowUp;

use App\Http\Requests\Concerns\SanitizesUserText;
use App\Models\CaMaster;
use App\Models\Employee;
use App\Services\Demo\DemoProviderEligibilityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RecordCallOutcomeRequest extends FormRequest
{
    use SanitizesUserText;

    public const OUTCOMES = [
        'Demo Scheduled',
        'Follow-up Required',
        'Interested',
        'Not Interested',
        'No Answer',
        'Busy',
        'Wrong Number',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->sanitizeTextFields(['remarks', 'meeting_link', 'demo_provider_name']);

        if ($this->filled('demo_date') && $this->filled('demo_time') && ! $this->filled('demo_at')) {
            $this->merge([
                'demo_at' => trim($this->input('demo_date').' '.$this->input('demo_time')),
            ]);
        }

        if ((string) $this->input('outcome') !== 'Demo Scheduled') {
            return;
        }

        $merge = [];
        $caId = (int) ($this->input('ca_id') ?? 0);
        $teamSize = $this->input('team_size');

        if (($teamSize === null || $teamSize === '') && $caId > 0) {
            $fromLead = CaMaster::query()->where('ca_id', $caId)->value('team_size');
            if ($fromLead !== null && (int) $fromLead > 0) {
                $teamSize = (int) $fromLead;
                $merge['team_size'] = $teamSize;
            }
        }

        $providerId = $this->input('demo_provider_employee_id');
        if ($providerId !== null && $providerId !== '') {
            $employee = Employee::query()->where('employee_id', (int) $providerId)->first();
            if ($employee) {
                $merge['demo_provider_employee_id'] = (int) $employee->employee_id;
                if (! trim((string) $this->input('demo_provider_name', ''))) {
                    $merge['demo_provider_name'] = $employee->name;
                }
                if (! trim((string) $this->input('meeting_link', ''))) {
                    $link = trim((string) ($employee->demo_meeting_link ?? ''));
                    if ($link !== '') {
                        $merge['meeting_link'] = $link;
                    }
                }
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        $outcome = (string) $this->input('outcome', '');
        $isDemo = $outcome === 'Demo Scheduled';
        $isFollowUp = $outcome === 'Follow-up Required';

        return [
            'followup_id' => 'nullable|integer|exists:follow_ups,followup_id',
            'ca_id' => 'required_without:followup_id|integer|exists:ca_masters,ca_id',
            'employee_id' => 'nullable|integer|exists:employees,employee_id',
            'outcome' => ['required', 'string', Rule::in(self::OUTCOMES)],
            'remarks' => 'nullable|string|max:2000',
            'next_followup_date' => ($isFollowUp ? 'required' : 'nullable').'|date',
            'next_followup_time' => 'nullable|date_format:H:i',
            'demo_date' => ($isDemo ? 'required' : 'nullable').'|date',
            'demo_time' => ($isDemo ? 'required' : 'nullable').'|date_format:H:i',
            'demo_at' => ($isDemo ? 'required' : 'nullable').'|date',
            'team_size' => ($isDemo ? 'required' : 'nullable').'|integer|min:1',
            'demo_provider_employee_id' => ($isDemo ? 'required' : 'nullable').'|integer|exists:employees,employee_id',
            'demo_provider_name' => 'nullable|string|max:255',
            'meeting_link' => ($isDemo ? 'required' : 'nullable').'|string|max:500',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ((string) $this->input('outcome') !== 'Demo Scheduled') {
                return;
            }

            $teamSize = (int) ($this->input('team_size') ?? 0);
            if ($teamSize < 1) {
                return;
            }

            $eligibility = app(DemoProviderEligibilityService::class);
            $options = $eligibility->optionsForTeamSize($teamSize);
            if ($options === []) {
                $validator->errors()->add(
                    'demo_provider_employee_id',
                    'No active demo provider matches this team size. Assign a Demo Provider employee with a covering team-size range.',
                );

                return;
            }

            $providerId = (int) ($this->input('demo_provider_employee_id') ?? 0);
            if ($providerId <= 0) {
                return;
            }

            if (! $eligibility->findEligible($providerId, $teamSize)) {
                $validator->errors()->add(
                    'demo_provider_employee_id',
                    'Selected demo provider is not eligible for this team size (inactive, Calling-only, or out of range).',
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'outcome.required' => 'Please select a call status.',
            'next_followup_date.required' => 'Follow-up date is required.',
            'demo_date.required' => 'Demo date is required.',
            'demo_time.required' => 'Demo time is required.',
            'demo_at.required' => 'Demo date and time are required.',
            'team_size.required' => 'Team size is required for demo scheduled calls.',
            'demo_provider_employee_id.required' => 'Please select a demo provider.',
            'meeting_link.required' => 'Meeting link is required for demo scheduled calls.',
        ];
    }
}
