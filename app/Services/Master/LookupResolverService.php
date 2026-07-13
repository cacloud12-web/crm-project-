<?php

namespace App\Services\Master;

use App\Models\City;
use App\Models\SourceLead;
use App\Models\State;

class LookupResolverService
{
    /** @var array<string, int|null> */
    private array $stateCache = [];

    /** @var array<string, int|null> */
    private array $cityCache = [];

    /** @var array<string, bool> */
    private array $cityStateCache = [];

    /** @var array<string, int|null> */
    private array $sourceCache = [];

    public function resolveStateId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $cacheKey = is_numeric($value)
            ? 'id:'.(int) $value
            : 'name:'.mb_strtolower(trim((string) $value));
        if (array_key_exists($cacheKey, $this->stateCache)) {
            return $this->stateCache[$cacheKey];
        }

        if (is_numeric($value)) {
            return $this->stateCache[$cacheKey] = State::where('state_id', (int) $value)->value('state_id');
        }

        $name = trim((string) $value);
        $exact = State::where('state_name', $name)->value('state_id');
        if ($exact) {
            return $this->stateCache[$cacheKey] = (int) $exact;
        }

        return $this->stateCache[$cacheKey] = State::whereRaw('LOWER(state_name) = ?', [mb_strtolower($name)])->value('state_id');
    }

    public function resolveCityId(mixed $value, ?int $stateId = null): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $cacheKey = ($stateId ?: 0).'|'.(is_numeric($value)
            ? 'id:'.(int) $value
            : 'name:'.mb_strtolower(trim((string) $value)));
        if (array_key_exists($cacheKey, $this->cityCache)) {
            return $this->cityCache[$cacheKey];
        }

        if (is_numeric($value)) {
            $query = City::query()->where('city_id', (int) $value);
            if ($stateId) {
                $query->where('state_id', $stateId);
            }

            return $this->cityCache[$cacheKey] = $query->value('city_id');
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

        return $this->cityCache[$cacheKey] = $query->value('city_id');
    }

    public function cityBelongsToState(?int $cityId, ?int $stateId): bool
    {
        if (! $cityId || ! $stateId) {
            return false;
        }

        $cacheKey = $cityId.'|'.$stateId;
        if (array_key_exists($cacheKey, $this->cityStateCache)) {
            return $this->cityStateCache[$cacheKey];
        }

        return $this->cityStateCache[$cacheKey] = City::query()
            ->where('city_id', $cityId)
            ->where('state_id', $stateId)
            ->exists();
    }

    public function resolveSourceId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $cacheKey = is_numeric($value)
            ? 'id:'.(int) $value
            : 'name:'.mb_strtolower(trim((string) $value));
        if (array_key_exists($cacheKey, $this->sourceCache)) {
            return $this->sourceCache[$cacheKey];
        }

        if (is_numeric($value)) {
            return $this->sourceCache[$cacheKey] = SourceLead::where('source_id', (int) $value)->value('source_id');
        }

        return $this->sourceCache[$cacheKey] = SourceLead::where('source_name', trim((string) $value))->value('source_id');
    }
}
