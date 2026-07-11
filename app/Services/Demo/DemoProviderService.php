<?php

namespace App\Services\Demo;

use App\Models\DemoProvider;
use App\Models\DemoProviderLeave;
use Illuminate\Support\Collection;

class DemoProviderService
{
    /**
     * @return Collection<int, DemoProvider>
     */
    public function list(bool $includeInactive = false): Collection
    {
        return DemoProvider::query()
            ->when(! $includeInactive, fn ($q) => $q->where('is_active', true))
            ->with('leaves')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function find(int $id): DemoProvider
    {
        return DemoProvider::query()->with('leaves')->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): DemoProvider
    {
        return DemoProvider::query()->create($this->normalize($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(DemoProvider $provider, array $data): DemoProvider
    {
        $provider->update($this->normalize($data));

        return $provider->fresh('leaves');
    }

    /**
     * @param  list<array{leave_date: string, reason?: string|null}>  $leaves
     */
    public function syncLeaves(DemoProvider $provider, array $leaves): DemoProvider
    {
        DemoProviderLeave::query()->where('demo_provider_id', $provider->id)->delete();
        foreach ($leaves as $leave) {
            if (empty($leave['leave_date'])) {
                continue;
            }
            DemoProviderLeave::query()->create([
                'demo_provider_id' => $provider->id,
                'leave_date' => $leave['leave_date'],
                'reason' => $leave['reason'] ?? null,
            ]);
        }

        return $provider->fresh('leaves');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $payload = collect($data)->only([
            'name',
            'default_meeting_link',
            'min_team_size',
            'max_team_size',
            'slot_duration_minutes',
            'buffer_minutes',
            'max_demos_per_day',
            'work_start_time',
            'work_end_time',
            'break_start_time',
            'break_end_time',
            'working_days',
            'is_active',
            'sort_order',
        ])->toArray();

        if (isset($payload['working_days']) && is_string($payload['working_days'])) {
            $payload['working_days'] = json_decode($payload['working_days'], true);
        }

        return $payload;
    }
}
