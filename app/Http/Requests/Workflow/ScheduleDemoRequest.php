<?php

namespace App\Http\Requests\Workflow;

use App\Http\Requests\Concerns\SanitizesUserText;
use Illuminate\Foundation\Http\FormRequest;

class ScheduleDemoRequest extends FormRequest
{
    use SanitizesUserText;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->sanitizeTextFields(['notes', 'meeting_link']);
    }

    public function rules(): array
    {
        return [
            'ca_id' => 'required|integer|exists:ca_masters,ca_id',
            'employee_id' => 'nullable|integer|exists:employees,employee_id',
            'call_log_id' => 'nullable|integer|exists:call_logs,id',
            'demo_at' => 'required|date|after:now',
            'meeting_link' => 'required|string|max:500',
            'notes' => 'nullable|string|max:2000',
        ];
    }
}
