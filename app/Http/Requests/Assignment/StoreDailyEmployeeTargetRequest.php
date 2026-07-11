<?php

namespace App\Http\Requests\Assignment;

use Illuminate\Foundation\Http\FormRequest;

class StoreDailyEmployeeTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,employee_id'],
            'target_date' => ['required', 'date'],
            'lead_target' => ['nullable', 'integer', 'min:0'],
            'call_target' => ['nullable', 'integer', 'min:0'],
            'demo_target' => ['nullable', 'integer', 'min:0'],
            'followup_target' => ['nullable', 'integer', 'min:0'],
            'email_target' => ['nullable', 'integer', 'min:0'],
            'sms_target' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
