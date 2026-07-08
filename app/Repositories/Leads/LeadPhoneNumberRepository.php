<?php

namespace App\Repositories\Leads;

use App\Models\CaMaster;
use App\Models\LeadPhoneNumber;
use Illuminate\Support\Facades\DB;

class LeadPhoneNumberRepository
{
    public function findLeadByNormalizedNumber(string $normalizedNumber, ?int $excludeCaId = null): ?CaMaster
    {
        $query = LeadPhoneNumber::query()
            ->where('normalized_number', $normalizedNumber)
            ->with(['caMaster.state', 'caMaster.city', 'caMaster.sourceLead']);

        if ($excludeCaId) {
            $query->where('ca_id', '!=', $excludeCaId);
        }

        return $query->first()?->caMaster;
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
                LeadPhoneNumber::query()->create([
                    'ca_id' => $caId,
                    'normalized_number' => $number,
                    'phone_type' => $type,
                ]);
            }
        });
    }
}
