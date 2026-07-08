<?php

namespace App\Http\Requests\LeadAction;

use App\Http\Requests\Concerns\SanitizesUserText;
use Illuminate\Foundation\Http\FormRequest;

class StoreLeadActionRequest extends FormRequest
{
    use SanitizesUserText;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->sanitizeTextFields(['remarks']);
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
