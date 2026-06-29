<?php

namespace App\Http\Requests\Bulk;

use Illuminate\Foundation\Http\FormRequest;

class BulkImportValidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session_id' => 'required|uuid',
            'mapping' => 'required|array',
            'mapping.ca_name' => 'nullable|string|max:255',
            'mapping.firm_name' => 'nullable|string|max:255',
            'mapping.mobile_no' => 'nullable|string|max:255',
        ];
    }
}
