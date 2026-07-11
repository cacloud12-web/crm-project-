<?php

namespace App\Repositories\Leads;

use App\Models\CaMaster;
use App\Models\LeadPhoneNumber;
use Illuminate\Support\Facades\DB;

class LeadPhoneNumberRepository
{
    public function findLeadByNormalizedNumber(string $normalizedNumber, ?int $excludeCaId = null): ?CaMaster
    {
        $lead = CaMaster::query()
            ->where(function ($query) use ($normalizedNumber) {
                $query->where('normalized_mobile', $normalizedNumber)
                    ->orWhere('normalized_alternate_mobile', $normalizedNumber);
            })
            ->when($excludeCaId, fn ($query) => $query->where('ca_id', '!=', $excludeCaId))
            ->first();

        if ($lead) {
            return $lead;
        }

        $registryRows = LeadPhoneNumber::query()
            ->where('normalized_number', $normalizedNumber)
            ->when($excludeCaId, fn ($query) => $query->where('ca_id', '!=', $excludeCaId))
            ->get();

        foreach ($registryRows as $row) {
            $owner = CaMaster::withTrashed()->find($row->ca_id);

            if ($owner && ! $owner->trashed()) {
                return $owner;
            }

            $row->delete();
        }

        return null;
    }

    public function numberExists(string $normalizedNumber, ?int $excludeCaId = null): bool
    {
        return $this->findLeadByNormalizedNumber($normalizedNumber, $excludeCaId) !== null;
    }

    /**
     * @param  array<string, string|null>  $numbers  keyed by phone_type => normalized number
     */
    public function syncForLead(int $caId, array $numbers): void
    {
        DB::transaction(function () use ($caId, $numbers) {
            LeadPhoneNumber::query()->where('ca_id', $caId)->delete();

            $unique = [];
            foreach ($numbers as $type => $number) {
                if (! $number || isset($unique[$number])) {
                    continue;
                }

                $unique[$number] = $type;
            }

            foreach ($unique as $number => $type) {
                $this->purgeStaleRegistryRows($number, $caId);

                LeadPhoneNumber::query()->create([
                    'ca_id' => $caId,
                    'normalized_number' => $number,
                    'phone_type' => $type,
                ]);
            }
        });
    }

    private function purgeStaleRegistryRows(string $normalizedNumber, int $caId): void
    {
        LeadPhoneNumber::query()
            ->where('normalized_number', $normalizedNumber)
            ->where('ca_id', '!=', $caId)
            ->whereNotIn('ca_id', CaMaster::query()->select('ca_id'))
            ->delete();
    }
}
