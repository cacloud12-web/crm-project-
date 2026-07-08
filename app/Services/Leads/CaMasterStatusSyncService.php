<?php

namespace App\Services\Leads;

use App\Models\CaMaster;
use App\Models\DemoResult;
use App\Models\DemoSchedule;
use App\Models\FollowUp;
use App\Services\Cache\CrmCacheService;

class CaMasterStatusSyncService
{
    public function __construct(
        private readonly CrmCacheService $cacheService,
    ) {}

    /**
     * @param  array<string, mixed>  $extra
     */
    public function apply(CaMaster $lead, string $status, array $extra = []): void
    {
        if (! $this->isAllowedStatus($status)) {
            return;
        }

        $lead->update(array_merge(['status' => $status], $extra));
        $this->forgetCaches();
    }

    public function statusForCallOutcome(string $outcome): ?string
    {
        return match ($outcome) {
            'Demo Scheduled' => 'Demo Scheduled',
            'Interested' => 'Interested',
            'Not Interested' => 'Not Interested',
            'Follow-up Required' => 'Follow Up Scheduled',
            'Demo Completed' => 'Demo Completed',
            default => in_array($outcome, config('crm_statuses.allowed', []), true) ? $outcome : null,
        };
    }

    public function statusForFollowUp(FollowUp $followUp): ?string
    {
        $outcome = trim((string) ($followUp->outcome ?? ''));
        if ($outcome !== '' && $this->isAllowedStatus($outcome)) {
            return $outcome;
        }

        return match ($followUp->followup_type) {
            'Demo Scheduled' => 'Demo Scheduled',
            'Demo Completed' => 'Demo Completed',
            'Follow Up Reminder' => 'Follow Up Reminder',
            default => null,
        };
    }

    public function workflowExtrasForStatus(string $status): array
    {
        return match ($status) {
            'Demo Scheduled' => [
                'workflow_stage' => 'demo_scheduled',
                'demo_status' => 'scheduled',
            ],
            'Demo Completed' => [
                'workflow_stage' => 'demo_completed',
            ],
            'Interested' => [
                'workflow_stage' => 'interested',
            ],
            'Thinking' => [
                'workflow_stage' => 'thinking',
            ],
            'Not Interested' => [
                'workflow_stage' => 'not_interested',
            ],
            'Purchased', 'Purchasing' => [
                'workflow_stage' => 'purchased',
            ],
            'Hold', 'Next Week', 'Next Month' => [
                'workflow_stage' => 'hold',
            ],
            'Follow Up Scheduled', 'Follow Up Reminder' => [
                'workflow_stage' => 'follow_up',
            ],
            default => [],
        };
    }

    public function syncFromLatestActivity(CaMaster $lead): bool
    {
        $latestResult = DemoResult::query()
            ->where('ca_id', $lead->ca_id)
            ->orderByDesc('created_at')
            ->first();

        if ($latestResult && $this->isAllowedStatus($latestResult->result)) {
            $extra = array_merge(
                ['demo_status' => $latestResult->result],
                $this->workflowExtrasForStatus($latestResult->result),
            );

            if (in_array($latestResult->result, ['Purchased', 'Purchasing'], true)) {
                $extra['software_purchased'] = true;
            }

            $this->apply($lead, $latestResult->result, $extra);

            return true;
        }

        $hasOpenDemo = DemoSchedule::query()
            ->where('ca_id', $lead->ca_id)
            ->where('status', DemoSchedule::STATUS_SCHEDULED)
            ->whereDoesntHave('result')
            ->exists();

        if ($hasOpenDemo) {
            $this->apply($lead, 'Demo Scheduled', $this->workflowExtrasForStatus('Demo Scheduled'));

            return true;
        }

        $openDemoFollowUp = FollowUp::query()
            ->where('ca_id', $lead->ca_id)
            ->where('followup_type', 'Demo Scheduled')
            ->whereIn('status', config('followup_automation.open_statuses', ['Pending', 'Scheduled', 'Open', 'Overdue']))
            ->orderByDesc('followup_id')
            ->first();

        if ($openDemoFollowUp) {
            $this->apply($lead, 'Demo Scheduled', $this->workflowExtrasForStatus('Demo Scheduled'));

            return true;
        }

        return false;
    }

    public function isAllowedStatus(string $status): bool
    {
        return in_array($status, config('crm_statuses.allowed', []), true);
    }

    private function forgetCaches(): void
    {
        $this->cacheService->forgetLeadSegmentCounts();
        $this->cacheService->forgetPipelineStageCounts();
        $this->cacheService->forgetDashboardMetrics();
    }
}
