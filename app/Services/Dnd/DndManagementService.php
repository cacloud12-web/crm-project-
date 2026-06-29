<?php

namespace App\Services\Dnd;

use App\Models\CaMaster;
use App\Models\DndManagement;
use App\Services\Activity\ActivityLogService;
use App\Services\Concerns\SearchesListings;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DndManagementService
{
    use SearchesListings;

    public function __construct(
        private readonly ActivityLogService $activityLogService,
    ) {}

    public function search(array $params = []): array
    {
        $query = DndManagement::query()->with(['caMaster:ca_id,firm_name,mobile_no,email_id']);

        if (! empty($params['dnd_type']) && $params['dnd_type'] !== 'All') {
            $type = $params['dnd_type'];
            $query->where(function ($inner) use ($type) {
                $inner->where('dnd_type', $type)->orWhere('dnd_type', 'All');
            });
            unset($params['dnd_type']);
        }

        return $this->searchListing($query, $params, 'dnd_management');
    }

    public function list(?string $dndType = null): Collection
    {
        $params = $dndType ? ['dnd_type' => $dndType] : [];

        return collect($this->search($params)['items']);
    }

    public function metrics(): array
    {
        return [
            'dnd_contacts' => DndManagement::query()->distinct('ca_id')->count('ca_id'),
        ];
    }

    public function create(array $data): DndManagement
    {
        return DB::transaction(function () use ($data) {
            $lead = isset($data['ca_id'])
                ? CaMaster::query()->find($data['ca_id'])
                : null;

            $entry = DndManagement::create([
                'ca_id' => $data['ca_id'] ?? null,
                'mobile_no' => $data['mobile_no'] ?? $lead?->mobile_no,
                'email_id' => $data['email_id'] ?? $lead?->email_id,
                'dnd_type' => $data['dnd_type'],
                'reason' => $data['reason'] ?? null,
                'added_by' => $data['added_by'] ?? 'System',
                'added_at' => isset($data['added_at']) && $data['added_at']
                    ? Carbon::parse($data['added_at'])
                    : now(),
            ]);

            $label = $lead?->firm_name ?: ($data['mobile_no'] ?? $data['email_id'] ?? 'Contact');
            $this->activityLogService->log(
                'DND_MANAGEMENT',
                'DND Add',
                (string) $entry->id,
                $label.' · '.$data['dnd_type'].($data['reason'] ? ' · '.$data['reason'] : ''),
                $data['added_by'] ?? 'System',
            );

            return $entry->fresh(['caMaster:ca_id,firm_name,mobile_no,email_id']);
        });
    }

    public function remove(int|string $id): void
    {
        DB::transaction(function () use ($id) {
            $entry = DndManagement::query()->findOrFail($id);
            $label = $entry->caMaster?->firm_name
                ?: ($entry->mobile_no ?: $entry->email_id ?: 'Contact');

            $this->activityLogService->log(
                'DND_MANAGEMENT',
                'DND Remove',
                (string) $entry->id,
                $label.' · '.$entry->dnd_type,
                'System',
            );

            $entry->delete();
        });
    }
}
