<?php

namespace App\Http\Requests\Bulk;

use Illuminate\Foundation\Http\FormRequest;

class BulkImportCommitRequest extends FormRequest
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
            'save_template' => 'nullable|boolean',
            'template_name' => 'nullable|string|max:120',
            'row_actions' => 'nullable|array',
            'row_actions.*' => 'nullable|string|in:skip,import_anyway,merge,replace',
        ];
    }
}
