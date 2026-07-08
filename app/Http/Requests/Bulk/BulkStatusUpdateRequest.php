<?php

namespace App\Http\Requests\Bulk;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkStatusUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ca_ids' => ['required', 'array', 'min:1'],
            'ca_ids.*' => ['integer', 'exists:ca_masters,ca_id'],
            'status' => [
                'required',
                'string',
                'max:50',
                Rule::in(BulkStatusUpdateRequest::allowedStatuses()),
            ],
            'preview' => ['nullable', 'boolean'],
            'confirm' => ['nullable', 'boolean'],
            'performed_by' => ['nullable', 'string', 'max:120'],
        ];
    }

    public static function allowedStatuses(): array
    {
        return config('crm_statuses.allowed', []);
    }
}
