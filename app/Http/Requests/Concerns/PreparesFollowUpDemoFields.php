<?php

namespace App\Http\Requests\Concerns;

use App\Models\CaMaster;
use App\Models\FollowUp;
use App\Services\DemoConfirmation\DemoConfirmationService;
use App\Support\Demo\DemoProviderResolver;

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
        if ($type === null) {
            $followUpId = $this->followUpRouteId();
            if ($followUpId) {
                $type = FollowUp::query()->where('followup_id', $followUpId)->value('followup_type');
            }
        }

        if ($type !== DemoConfirmationService::DEMO_FOLLOWUP_TYPE) {
            return;
        }

        $teamSize = $this->input('team_size');
        if (($teamSize === null || $teamSize === '') && $this->input('ca_id')) {
            $teamSize = CaMaster::query()
                ->where('ca_id', $this->input('ca_id'))
                ->value('team_size');
        }

        if ($teamSize === null || $teamSize === '') {
            return;
        }

        $resolved = DemoProviderResolver::resolve((int) $teamSize);
        if (! $resolved) {
            return;
        }

        $merge = [];
        if (! trim((string) $this->input('demo_provider_name', ''))) {
            $merge['demo_provider_name'] = $resolved['provider'];
        }
        if (! trim((string) $this->input('meeting_link', ''))) {
            $merge['meeting_link'] = $resolved['meeting_link'];
        }
        if ($merge !== []) {
            $this->merge($merge);
        }
    }
}
