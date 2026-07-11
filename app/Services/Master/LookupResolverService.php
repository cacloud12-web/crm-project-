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

        $name = trim((string) $value);
        $exact = State::where('state_name', $name)->value('state_id');
        if ($exact) {
            return $exact;
        }

        return State::whereRaw('LOWER(state_name) = ?', [mb_strtolower($name)])->value('state_id');
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

        $name = trim((string) $value);
        $aliases = [
            'bangalore' => 'Bengaluru',
            'bengaluru' => 'Bengaluru',
            'bombay' => 'Mumbai',
            'madras' => 'Chennai',
            'calcutta' => 'Kolkata',
            'gurgaon' => 'Gurugram',
            'mysore' => 'Mysuru',
            'mangalore' => 'Mangaluru',
            'hubli' => 'Hubballi',
            'belgaum' => 'Belagavi',
            'trichy' => 'Tiruchirappalli',
            'trivandrum' => 'Thiruvananthapuram',
            'pondicherry' => 'Puducherry',
        ];
        $normalized = $aliases[mb_strtolower($name)] ?? $name;

        $query = City::query()->where(function ($inner) use ($name, $normalized) {
            $inner->where('city_name', $name)
                ->orWhere('city_name', $normalized)
                ->orWhereRaw('LOWER(city_name) = ?', [mb_strtolower($name)])
                ->orWhereRaw('LOWER(city_name) = ?', [mb_strtolower($normalized)]);
        });

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
