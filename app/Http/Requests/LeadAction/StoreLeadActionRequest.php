<?php

namespace App\Http\Requests\LeadAction;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeadActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ca_id' => ['required', 'integer', 'exists:ca_masters,ca_id'],
            'action_type' => ['required', 'string', 'max:120'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
