<?php

namespace App\Http\Requests\Employee\Concerns;

use App\Services\Demo\DemoProviderEligibilityService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesEmployeeDemoWorkType
{
    /**
     * @return array<string, mixed>
     */
    protected function employeeDemoWorkTypeRules(bool $updating = false): array
    {
        $prefix = $updating ? 'sometimes|' : '';

        return [
            'work_type' => [
                $updating ? 'sometimes' : 'nullable',
                'string',
                Rule::in([
                    DemoProviderEligibilityService::WORK_CALLING,
                    DemoProviderEligibilityService::WORK_DEMO_PROVIDER,
                    DemoProviderEligibilityService::WORK_BOTH,
                ]),
            ],
            'demo_meeting_link' => $prefix.'nullable|string|max:500',
            'demo_min_team_size' => $prefix.'nullable|integer|min:1',
            'demo_max_team_size' => $prefix.'nullable|integer|min:1',
            'active_for_demo' => $prefix.'nullable|boolean',
        ];
    }

    protected function prepareEmployeeDemoWorkType(): void
    {
        if (! $this->filled('work_type')) {
            $this->merge(['work_type' => DemoProviderEligibilityService::WORK_CALLING]);
        }

        $workType = (string) $this->input('work_type');
        if (! in_array($workType, [
            DemoProviderEligibilityService::WORK_DEMO_PROVIDER,
            DemoProviderEligibilityService::WORK_BOTH,
        ], true)) {
            $this->merge([
                'demo_meeting_link' => null,
                'demo_min_team_size' => null,
                'demo_max_team_size' => null,
                'active_for_demo' => false,
            ]);

            return;
        }

        if ($this->has('active_for_demo')) {
            $this->merge([
                'active_for_demo' => filter_var($this->input('active_for_demo'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }

    protected function appendEmployeeDemoWorkTypeValidation(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $workType = (string) $this->input('work_type', DemoProviderEligibilityService::WORK_CALLING);
            if (! in_array($workType, [
                DemoProviderEligibilityService::WORK_DEMO_PROVIDER,
                DemoProviderEligibilityService::WORK_BOTH,
            ], true)) {
                return;
            }

            $link = trim((string) $this->input('demo_meeting_link', ''));
            if ($link === '') {
                $validator->errors()->add('demo_meeting_link', 'Demo meeting link is required for Demo Provider work types.');
            }

            $min = $this->input('demo_min_team_size');
            $max = $this->input('demo_max_team_size');
            if ($min === null || $min === '') {
                $validator->errors()->add('demo_min_team_size', 'Minimum team size is required for Demo Provider work types.');
            }
            if ($max === null || $max === '') {
                $validator->errors()->add('demo_max_team_size', 'Maximum team size is required for Demo Provider work types.');
            }
            if ($min !== null && $min !== '' && $max !== null && $max !== '' && (int) $min > (int) $max) {
                $validator->errors()->add('demo_min_team_size', 'Minimum team size cannot be greater than maximum team size.');
            }
        });
    }
}
