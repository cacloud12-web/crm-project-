<?php

namespace App\Http\Requests\LeadAssignment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ca_ids' => 'required_without:bulk_action_id|array|min:1',
            'ca_ids.*' => 'integer|exists:ca_masters,ca_id',
            'bulk_action_id' => 'required_without:ca_ids|integer|exists:bulk_actions,bulk_action_id',
            'state_id' => 'nullable|integer',
            'city_id' => 'nullable|integer',
            'source_id' => 'nullable|integer',
            'assignment' => 'nullable|string|in:unassigned,assigned',
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'integer|exists:employees,employee_id',
            'assignment_mode' => [
                'required',
                'string',
                Rule::in([
                    'manual',
                    'round_robin',
                    'workload_balance',
                    'city_match',
                    'state_match',
                ]),
            ],
            'reason' => 'nullable|string|max:255',
            'assigned_by' => 'nullable|integer|exists:employees,employee_id',
            'preview' => 'nullable|boolean',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('assignment_mode') === 'manual' && count($this->input('employee_ids', [])) !== 1) {
                $validator->errors()->add('employee_ids', 'Manual assignment requires exactly one employee.');
            }
        });
    }
}
