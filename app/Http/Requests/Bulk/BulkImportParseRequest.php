<?php

namespace App\Http\Requests\Bulk;

use App\Rules\ValidBulkImportFile;
use Illuminate\Foundation\Http\FormRequest;

class BulkImportParseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', new ValidBulkImportFile],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Please upload a CSV or Excel file.',
            'file.mimes' => 'Only CSV and Excel (.xlsx) files are supported.',
            'file.max' => 'The file may not be larger than 10 MB.',
        ];
    }
}
