<?php

namespace App\Http\Requests\FollowUp;

use App\Http\Requests\Concerns\SanitizesUserText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordCallOutcomeRequest extends FormRequest
{
    use SanitizesUserText;

    public const OUTCOMES = [
        'Demo Scheduled',
        'Follow-up Required',
        'Interested',
        'Not Interested',
        'No Answer',
        'Busy',
        'Wrong Number',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->sanitizeTextFields(['remarks', 'meeting_link']);

        if ($this->filled('demo_date') && $this->filled('demo_time') && ! $this->filled('demo_at')) {
            $this->merge([
                'demo_at' => trim($this->input('demo_date').' '.$this->input('demo_time')),
            ]);
        }
    }

    public function rules(): array
    {
        $outcome = (string) $this->input('outcome', '');
        $isDemo = $outcome === 'Demo Scheduled';
        $isFollowUp = $outcome === 'Follow-up Required';

        return [
            'followup_id' => 'nullable|integer|exists:follow_ups,followup_id',
            'ca_id' => 'required_without:followup_id|integer|exists:ca_masters,ca_id',
            'employee_id' => 'nullable|integer|exists:employees,employee_id',
            'outcome' => ['required', 'string', Rule::in(self::OUTCOMES)],
            'remarks' => 'required|string|max:2000',
            'next_followup_date' => ($isFollowUp ? 'required' : 'nullable').'|date',
            'next_followup_time' => 'nullable|date_format:H:i',
            'demo_date' => ($isDemo ? 'required' : 'nullable').'|date',
            'demo_time' => ($isDemo ? 'required' : 'nullable').'|date_format:H:i',
            'demo_at' => ($isDemo ? 'required' : 'nullable').'|date',
            'meeting_link' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'outcome.required' => 'Please select a call status.',
            'remarks.required' => 'Call note is required.',
            'next_followup_date.required' => 'Follow-up date is required.',
            'demo_date.required' => 'Demo date is required.',
            'demo_time.required' => 'Demo time is required.',
            'demo_at.required' => 'Demo date and time are required.',
        ];
    }
}
