<?php

namespace App\Http\Requests\FollowUp;

use App\Http\Requests\Concerns\PreparesFollowUpDemoFields;
use App\Http\Requests\Concerns\SanitizesUserText;
use App\Http\Requests\Concerns\ValidatesFollowUpSchedule;
use App\Models\FollowUp;
use App\Services\DemoConfirmation\DemoConfirmationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateFollowUpRequest extends FormRequest
{
    use PreparesFollowUpDemoFields;
    use SanitizesUserText;
    use ValidatesFollowUpSchedule;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->sanitizeTextFields(['remarks', 'reschedule_reason', 'outcome', 'demo_provider_name', 'meeting_link']);
        $this->prepareFollowUpDemoFields();
    }

    public function rules(): array
    {
        return [
            'ca_id' => 'sometimes|required|exists:ca_masters,ca_id',
            'employee_id' => 'nullable|exists:employees,employee_id',
            'followup_type' => ['sometimes', 'required', 'string', 'max:255', Rule::in(config('crm_followups.types', []))],
            'remarks' => 'nullable|string',
            'scheduled_date' => 'sometimes|required|date',
            'next_followup_date' => 'nullable|date',
            'status' => ['nullable', 'string', 'max:255', Rule::in(config('crm_followups.statuses', []))],
            'priority' => 'nullable|string|in:Low,Normal,High,Urgent',
            'outcome' => 'nullable|string|max:255',
            'reschedule_reason' => 'nullable|string|max:2000',
            'team_size' => 'nullable|integer|min:1',
            'demo_provider_name' => 'nullable|string|max:255',
            'meeting_link' => 'nullable|string|max:500',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->appendFollowUpScheduleValidation($validator);

        $validator->after(function (Validator $validator): void {
            $type = $this->input('followup_type');
            if ($type === null) {
                $followUpId = $this->followUpRouteId();
                if ($followUpId) {
                    $type = FollowUp::query()->where('followup_id', $followUpId)->value('followup_type');
                }
            }

            if ($type !== DemoConfirmationService::DEMO_FOLLOWUP_TYPE) {
                return;
            }

            $link = trim((string) $this->input('meeting_link', ''));
            if ($link === '') {
                $validator->errors()->add('meeting_link', 'Meeting link is required for demo scheduled follow-ups.');
            }
        });
    }
}
