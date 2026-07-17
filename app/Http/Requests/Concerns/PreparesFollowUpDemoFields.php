<?php

namespace App\Http\Requests\Concerns;

use App\Models\CaMaster;
use App\Models\DemoSchedule;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Services\Demo\DemoProviderEligibilityService;
use App\Services\DemoConfirmation\DemoConfirmationService;

trait PreparesFollowUpDemoFields
{
    protected function followUpRouteId(): int|string|null
    {
        return $this->route('follow_up')
            ?? $this->route('followup')
            ?? $this->route('id');
    }

    protected function prepareFollowUpDemoFields(): void
    {
        $type = $this->input('followup_type');
        $followUpId = $this->followUpRouteId();
        $existing = null;

        if ($type === null && $followUpId) {
            $existing = FollowUp::query()->where('followup_id', $followUpId)->first();
            $type = $existing?->followup_type;
        } elseif ($followUpId) {
            $existing = FollowUp::query()->where('followup_id', $followUpId)->first();
        }

        if ($type !== DemoConfirmationService::DEMO_FOLLOWUP_TYPE) {
            return;
        }

        $merge = [];

        $teamSize = $this->input('team_size');
        if (($teamSize === null || $teamSize === '') && $this->input('ca_id')) {
            $teamSize = CaMaster::query()
                ->where('ca_id', $this->input('ca_id'))
                ->value('team_size');
            if ($teamSize !== null && $teamSize !== '') {
                $merge['team_size'] = (int) $teamSize;
            }
        }

        if (($teamSize === null || $teamSize === '') && $existing?->team_size) {
            $teamSize = $existing->team_size;
        }

        $providerId = $this->input('demo_provider_employee_id');
        if (($providerId === null || $providerId === '') && $existing?->demo_provider_employee_id) {
            $providerId = $existing->demo_provider_employee_id;
        }

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

        if (! trim((string) ($merge['meeting_link'] ?? $this->input('meeting_link', '')))) {
            $resolvedLink = $this->resolveExistingMeetingLink($existing, $followUpId);
            if ($resolvedLink !== null) {
                $merge['meeting_link'] = $resolvedLink;
            }
        }

        // Auto-pick sole eligible provider when team size is known and none selected.
        if (($providerId === null || $providerId === '') && $teamSize !== null && $teamSize !== '') {
            $options = app(DemoProviderEligibilityService::class)->optionsForTeamSize((int) $teamSize);
            if (count($options) === 1) {
                $only = $options[0];
                $merge['demo_provider_employee_id'] = $only['employee_id'];
                $merge['demo_provider_name'] = $only['name'];
                if (! trim((string) ($merge['meeting_link'] ?? $this->input('meeting_link', '')))) {
                    $merge['meeting_link'] = $only['demo_meeting_link'];
                }
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    protected function resolveExistingMeetingLink(?FollowUp $existing, int|string|null $followUpId): ?string
    {
        $link = trim((string) ($existing?->meeting_link ?? ''));
        if ($link !== '') {
            return $link;
        }

        if (! $followUpId) {
            return null;
        }

        $fromSchedule = DemoSchedule::query()
            ->where('followup_id', $followUpId)
            ->whereNotNull('meeting_link')
            ->where('meeting_link', '!=', '')
            ->orderByDesc('id')
            ->value('meeting_link');

        if ($fromSchedule) {
            return (string) $fromSchedule;
        }

        if ($existing?->ca_id) {
            $fromLeadSchedule = DemoSchedule::query()
                ->where('ca_id', $existing->ca_id)
                ->whereDoesntHave('result')
                ->whereNotNull('meeting_link')
                ->where('meeting_link', '!=', '')
                ->orderByDesc('id')
                ->value('meeting_link');

            if ($fromLeadSchedule) {
                return (string) $fromLeadSchedule;
            }
        }

        return null;
    }
}
