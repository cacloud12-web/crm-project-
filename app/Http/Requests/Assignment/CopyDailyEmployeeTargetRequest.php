<?php

namespace App\Http\Requests\Assignment;

use Illuminate\Foundation\Http\FormRequest;

class CopyDailyEmployeeTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_target_id' => ['nullable', 'integer', 'exists:daily_employee_targets,id'],
            'source_date' => ['nullable', 'date'],
            'target_date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'days' => ['nullable', 'integer', 'min:1', 'max:14'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer', 'exists:employees,employee_id'],
            'overwrite' => ['nullable', 'boolean'],
        ];
    }
}
