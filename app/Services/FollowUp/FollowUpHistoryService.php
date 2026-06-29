<?php

namespace App\Services\FollowUp;

use App\Models\FollowUpHistory;
use Illuminate\Support\Collection;

class FollowUpHistoryService
{
    public function record(
        int $caId,
        string $eventType,
        ?int $followupId = null,
        ?int $employeeId = null,
        ?string $outcome = null,
        ?string $remarks = null,
        ?array $metadata = null,
        ?string $performedBy = null,
    ): FollowUpHistory {
        return FollowUpHistory::query()->create([
            'followup_id' => $followupId,
            'ca_id' => $caId,
            'employee_id' => $employeeId,
            'event_type' => $eventType,
            'outcome' => $outcome,
            'remarks' => $remarks,
            'metadata' => $metadata,
            'performed_by' => $performedBy ?? auth()->user()?->name ?? 'System',
            'created_at' => now(),
        ]);
    }

    public function timelineForLead(int $caId, int $limit = 100): Collection
    {
        return FollowUpHistory::query()
            ->where('ca_id', $caId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function timelineForFollowUp(int $followupId, int $limit = 50): Collection
    {
        return FollowUpHistory::query()
            ->where('followup_id', $followupId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
