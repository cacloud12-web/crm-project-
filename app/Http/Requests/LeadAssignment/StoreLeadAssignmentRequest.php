<?php

namespace App\Http\Requests\LeadAssignment;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeadAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('executive_id') && ! $this->filled('employee_id')) {
            $this->merge(['employee_id' => $this->input('executive_id')]);
        }
    }

    public function rules(): array
    {
        return [
            'ca_id' => 'required|exists:ca_masters,ca_id',
            'employee_id' => 'required|exists:employees,employee_id',
            'executive_id' => 'nullable|exists:employees,employee_id',
            'assignment_type' => 'nullable|string|max:255',
            'reason' => 'nullable|string|max:255',
        ];
    }
}
