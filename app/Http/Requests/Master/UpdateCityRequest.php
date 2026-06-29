<?php

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $cityId = $this->route('city');
        $stateId = $this->input('state_id');

        return [
            'city_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('cities', 'city_name')
                    ->where(fn ($query) => $query->where('state_id', $stateId))
                    ->ignore($cityId, 'city_id'),
            ],
            'state_id' => 'required|exists:states,state_id',
        ];
    }
}
