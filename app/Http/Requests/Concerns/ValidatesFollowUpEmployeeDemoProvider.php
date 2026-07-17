<?php

namespace App\Http\Requests\Concerns;

use App\Models\FollowUp;
use App\Services\Demo\DemoProviderEligibilityService;
use App\Services\DemoConfirmation\DemoConfirmationService;
use Illuminate\Validation\Validator;

trait ValidatesFollowUpEmployeeDemoProvider
{
    protected function appendFollowUpEmployeeDemoProviderValidation(Validator $validator, bool $isCreate): void
    {
        $validator->after(function (Validator $validator) use ($isCreate): void {
            $type = $this->input('followup_type');
            $existing = null;
            $followUpId = method_exists($this, 'followUpRouteId') ? $this->followUpRouteId() : null;

            if ($type === null && $followUpId) {
                $existing = FollowUp::query()->where('followup_id', $followUpId)->first();
                $type = $existing?->followup_type;
            } elseif ($followUpId) {
                $existing = FollowUp::query()->where('followup_id', $followUpId)->first();
            }

            if ($type !== DemoConfirmationService::DEMO_FOLLOWUP_TYPE) {
                return;
            }

            $onlyStatusTouch = ! $isCreate
                && ! $this->has('team_size')
                && ! $this->has('demo_provider_employee_id')
                && ! $this->has('followup_type')
                && ! $this->has('scheduled_date')
                && trim((string) ($existing?->meeting_link ?? $this->input('meeting_link', ''))) !== '';

            if ($onlyStatusTouch) {
                return;
            }

            $teamSize = $this->input('team_size');
            if (($teamSize === null || $teamSize === '') && $existing?->team_size) {
                $teamSize = $existing->team_size;
            }

            if ($teamSize === null || $teamSize === '' || (int) $teamSize < 1) {
                $validator->errors()->add('team_size', 'Team size is required for demo scheduled follow-ups.');

                return;
            }

            $teamSize = (int) $teamSize;
            $eligibility = app(DemoProviderEligibilityService::class);
            $options = $eligibility->optionsForTeamSize($teamSize);

            if ($options === []) {
                $validator->errors()->add(
                    'demo_provider_employee_id',
                    'No active demo provider matches this team size. Assign a Demo Provider employee with a covering team-size range.',
                );

                return;
            }

            $providerId = $this->input('demo_provider_employee_id');
            if (($providerId === null || $providerId === '') && $existing?->demo_provider_employee_id) {
                $providerId = $existing->demo_provider_employee_id;
            }

            if ($providerId === null || $providerId === '') {
                $validator->errors()->add('demo_provider_employee_id', 'Please select a demo provider for this team size.');

                return;
            }

            $providerId = (int) $providerId;
            $eligible = $eligibility->findEligible($providerId, $teamSize);
            if (! $eligible) {
                $validator->errors()->add(
                    'demo_provider_employee_id',
                    'Selected demo provider is not eligible for this team size (inactive, Calling-only, or out of range).',
                );

                return;
            }

            $link = trim((string) $this->input('meeting_link', ''));
            if ($link === '') {
                $link = trim((string) ($eligible->demo_meeting_link ?? ''));
            }
            if ($link === '') {
                $validator->errors()->add('meeting_link', 'Meeting link is required for demo scheduled follow-ups.');
            }
        });
    }
}
