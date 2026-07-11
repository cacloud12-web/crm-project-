<?php

namespace App\Http\Requests\Assignment;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDailyEmployeeTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_date' => ['sometimes', 'date'],
            'lead_target' => ['sometimes', 'integer', 'min:0'],
            'call_target' => ['sometimes', 'integer', 'min:0'],
            'demo_target' => ['sometimes', 'integer', 'min:0'],
            'followup_target' => ['sometimes', 'integer', 'min:0'],
            'email_target' => ['sometimes', 'integer', 'min:0'],
            'sms_target' => ['sometimes', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
