<?php

namespace App\Http\Requests\FollowUp;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordCallOutcomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $outcomes = array_keys(config('followup_automation.outcomes', []));

        return [
            'followup_id' => 'nullable|integer|exists:follow_ups,followup_id',
            'ca_id' => 'required_without:followup_id|integer|exists:ca_masters,ca_id',
            'employee_id' => 'nullable|integer|exists:employees,employee_id',
            'outcome' => ['required', 'string', Rule::in($outcomes)],
            'remarks' => 'nullable|string|max:2000',
            'next_followup_date' => 'nullable|date',
            'next_followup_time' => 'nullable|date_format:H:i',
        ];
    }
}
