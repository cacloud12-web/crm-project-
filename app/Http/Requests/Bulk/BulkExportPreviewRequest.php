<?php

namespace App\Http\Requests\Bulk;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkExportPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::in(['selected', 'filtered', 'all'])],
            'format' => ['nullable', Rule::in(['csv', 'xlsx'])],
            'ca_ids' => ['required_if:scope,selected', 'array', 'min:1'],
            'ca_ids.*' => ['integer', 'exists:ca_masters,ca_id'],
            'filters' => ['nullable', 'array'],
            'filters.status' => ['nullable', 'string', 'max:50'],
            'filters.state_id' => ['nullable', 'integer'],
            'filters.city_id' => ['nullable', 'integer'],
            'filters.source_id' => ['nullable', 'integer'],
            'filters.is_newly_established' => ['nullable'],
            'filters.search' => ['nullable', 'string', 'max:120'],
            'columns' => ['nullable', 'array'],
            'columns.*' => ['string', 'max:50'],
        ];
    }
}
