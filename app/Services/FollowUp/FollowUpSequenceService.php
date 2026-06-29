<?php

namespace App\Services\FollowUp;

use App\Models\FollowUpSequenceConfig;
use Illuminate\Support\Carbon;

class FollowUpSequenceService
{
    public function activeConfig(): FollowUpSequenceConfig
    {
        $config = FollowUpSequenceConfig::query()
            ->where('is_active', true)
            ->orderByDesc('config_id')
            ->first();

        if ($config) {
            return $config;
        }

        return FollowUpSequenceConfig::query()->create([
            'name' => 'Default Sequence',
            'is_active' => true,
            'sequence_days' => config('followup_automation.default_sequence_days', [1, 3, 7, 15, 30]),
            'trigger_outcomes' => config('followup_automation.sequence_trigger_outcomes', ['No Answer', 'Busy']),
        ]);
    }

    public function sequenceDays(): array
    {
        $days = $this->activeConfig()->sequence_days;

        return array_values(array_map('intval', $days ?: config('followup_automation.default_sequence_days', [])));
    }

    public function triggerOutcomes(): array
    {
        $outcomes = $this->activeConfig()->trigger_outcomes;

        return array_values($outcomes ?: config('followup_automation.sequence_trigger_outcomes', []));
    }

    public function shouldAdvanceSequence(string $outcome): bool
    {
        return in_array($outcome, $this->triggerOutcomes(), true);
    }

    public function nextSequenceStep(?int $currentStep): ?int
    {
        $days = $this->sequenceDays();
        if ($days === []) {
            return null;
        }

        if ($currentStep === null) {
            return $days[0];
        }

        $index = array_search($currentStep, $days, true);
        if ($index === false) {
            return $days[0];
        }

        $nextIndex = (int) $index + 1;

        return $days[$nextIndex] ?? null;
    }

    public function scheduleDateForStep(int $dayOffset, ?Carbon $from = null): Carbon
    {
        return ($from ?? now())->copy()->addDays($dayOffset)->startOfDay()->setTime(10, 0);
    }

    public function updateConfig(array $data, ?int $userId = null): FollowUpSequenceConfig
    {
        $config = $this->activeConfig();
        $config->update([
            'name' => $data['name'] ?? $config->name,
            'sequence_days' => array_values(array_map('intval', $data['sequence_days'] ?? $config->sequence_days)),
            'trigger_outcomes' => array_values($data['trigger_outcomes'] ?? $config->trigger_outcomes),
            'is_active' => $data['is_active'] ?? true,
            'updated_by_user_id' => $userId,
        ]);

        return $config->fresh();
    }
}
