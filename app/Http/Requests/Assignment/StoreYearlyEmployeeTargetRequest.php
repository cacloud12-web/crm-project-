<?php

namespace App\Http\Requests\Assignment;

use Illuminate\Foundation\Http\FormRequest;

class StoreYearlyEmployeeTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,employee_id'],
            'target_year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'lead_target' => ['nullable', 'integer', 'min:0'],
            'call_target' => ['nullable', 'integer', 'min:0'],
            'demo_target' => ['nullable', 'integer', 'min:0'],
            'followup_target' => ['nullable', 'integer', 'min:0'],
            'email_target' => ['nullable', 'integer', 'min:0'],
            'sms_target' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
