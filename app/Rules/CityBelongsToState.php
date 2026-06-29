<?php

namespace App\Rules;

use App\Models\City;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class CityBelongsToState implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    protected array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $stateId = $this->data['state_id'] ?? null;

        if (! $stateId) {
            $fail('Select a state before choosing a city.');

            return;
        }

        $exists = City::query()
            ->where('city_id', (int) $value)
            ->where('state_id', (int) $stateId)
            ->exists();

        if (! $exists) {
            $fail('Selected city does not belong to selected state.');
        }
    }
}
