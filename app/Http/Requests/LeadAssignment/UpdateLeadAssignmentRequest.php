<?php

namespace App\Http\Requests\LeadAssignment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ca_id' => 'sometimes|integer|exists:ca_masters,ca_id',
            'employee_id' => 'sometimes|integer|exists:employees,employee_id',
            'assignment_type' => 'nullable|string|max:255',
            'reason' => 'nullable|string|max:255',
            'status' => ['nullable', 'string', Rule::in(['Active', 'Paused', 'active', 'paused'])],
        ];
    }
}
