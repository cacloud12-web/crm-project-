<?php

namespace App\Services\Master;

use App\Models\City;
use App\Models\SourceLead;
use App\Models\State;

class LookupResolverService
{
    public function resolveStateId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return State::where('state_id', (int) $value)->value('state_id');
        }

        return State::where('state_name', trim((string) $value))->value('state_id');
    }

    public function resolveCityId(mixed $value, ?int $stateId = null): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $query = City::query()->where('city_id', (int) $value);
            if ($stateId) {
                $query->where('state_id', $stateId);
            }

            return $query->value('city_id');
        }

        $query = City::where('city_name', trim((string) $value));

        if ($stateId) {
            $query->where('state_id', $stateId);
        }

        return $query->value('city_id');
    }

    public function cityBelongsToState(?int $cityId, ?int $stateId): bool
    {
        if (! $cityId || ! $stateId) {
            return false;
        }

        return City::query()
            ->where('city_id', $cityId)
            ->where('state_id', $stateId)
            ->exists();
    }

    public function resolveSourceId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return SourceLead::where('source_id', (int) $value)->value('source_id');
        }

        return SourceLead::where('source_name', trim((string) $value))->value('source_id');
    }
}
