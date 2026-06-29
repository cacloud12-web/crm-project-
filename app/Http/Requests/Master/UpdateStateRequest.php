<?php

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $stateId = $this->route('state');

        return [
            'state_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('states', 'state_name')->ignore($stateId, 'state_id'),
            ],
        ];
    }
}
