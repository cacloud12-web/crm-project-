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
        $normalized = $this->normalizeCityName($name);

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

    /**
     * Resolve an existing city, or create it under the given / inferred state.
     * Used on OCR Accept so a valid OCR city is not blocked by an incomplete master list.
     */
    public function ensureCityId(mixed $value, ?int $stateId = null): ?int
    {
        $existing = $this->resolveCityId($value, $stateId);
        if ($existing !== null) {
            return $existing;
        }
        if ($value === null || $value === '' || is_numeric($value)) {
            return null;
        }

        $displayName = $this->normalizeCityName(trim((string) $value));
        if ($displayName === '') {
            return null;
        }

        $resolvedStateId = $stateId ?: $this->inferStateIdForCity($displayName);
        if ($resolvedStateId === null) {
            return null;
        }

        $city = City::query()->firstOrCreate(
            [
                'state_id' => $resolvedStateId,
                'city_name' => $displayName,
            ],
            [
                'state_id' => $resolvedStateId,
                'city_name' => $displayName,
                'is_active' => true,
            ],
        );

        $this->cityCache = [];

        return (int) $city->city_id;
    }

    private function normalizeCityName(string $name): string
    {
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
        $key = mb_strtolower(trim($name));
        if (isset($aliases[$key])) {
            return $aliases[$key];
        }

        return mb_convert_case(mb_strtolower(trim($name)), MB_CASE_TITLE, 'UTF-8');
    }

    private function inferStateIdForCity(string $cityName): ?int
    {
        $path = database_path('data/india_states_cities.php');
        if (! is_file($path)) {
            return null;
        }
        /** @var array<string, list<string>> $dataset */
        $dataset = require $path;
        $needle = mb_strtolower($cityName);
        foreach ($dataset as $stateName => $cities) {
            foreach ($cities as $city) {
                if (mb_strtolower((string) $city) === $needle) {
                    return $this->resolveStateId($stateName);
                }
            }
        }

        return null;
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
