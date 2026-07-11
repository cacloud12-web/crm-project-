<?php

namespace App\Http\Requests\Assignment;

use Illuminate\Foundation\Http\FormRequest;

class UpdateYearlyEmployeeTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
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
