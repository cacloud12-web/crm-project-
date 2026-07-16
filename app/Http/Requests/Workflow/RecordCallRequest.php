<?php

namespace App\Http\Requests\Workflow;

use App\Http\Requests\Concerns\SanitizesUserText;
use App\Http\Requests\FollowUp\RecordCallOutcomeRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordCallRequest extends FormRequest
{
    use SanitizesUserText;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->sanitizeTextFields(['call_note', 'remarks']);
    }

    public function rules(): array
    {
        $statuses = array_values(array_unique(array_merge(
            config('lead_workflow.call_statuses', []),
            RecordCallOutcomeRequest::OUTCOMES,
            ['Call Later'],
        )));

        return [
            'followup_id' => 'nullable|integer|exists:follow_ups,followup_id',
            'ca_id' => 'required_without:followup_id|integer|exists:ca_masters,ca_id',
            'employee_id' => 'nullable|integer|exists:employees,employee_id',
            'call_status' => ['required', 'string', 'max:40'],
            'call_note' => 'nullable|string|max:2000',
            'remarks' => 'nullable|string|max:2000',
            'called_at' => 'nullable|date',
            'next_followup_date' => 'nullable|date',
            'next_followup_time' => 'nullable|date_format:H:i',
        ];
    }
}
