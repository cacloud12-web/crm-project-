<?php

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSourceLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $sourceId = $this->route('source_lead');

        return [
            'source_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('source_leads', 'source_name')->ignore($sourceId, 'source_id'),
            ],
        ];
    }
}
