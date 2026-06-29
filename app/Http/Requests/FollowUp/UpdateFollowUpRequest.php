<?php

namespace App\Http\Requests\FollowUp;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFollowUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ca_id' => 'sometimes|required|exists:ca_masters,ca_id',
            'employee_id' => 'nullable|exists:employees,employee_id',
            'followup_type' => 'sometimes|required|string|max:255',
            'remarks' => 'nullable|string',
            'scheduled_date' => 'sometimes|required|date',
            'next_followup_date' => 'nullable|date',
            'status' => 'nullable|string|max:255',
            'priority' => 'nullable|string|in:Low,Normal,High,Urgent',
            'outcome' => 'nullable|string|max:255',
            'reschedule_reason' => 'nullable|string|max:2000',
        ];
    }
}
