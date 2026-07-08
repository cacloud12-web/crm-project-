<?php

namespace App\Http\Requests\Sms;

use App\Http\Requests\Concerns\SanitizesUserText;
use App\Models\SmsTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSmsTemplateRequest extends FormRequest
{
    use SanitizesUserText;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->sanitizeTextFields(['template_name', 'body_template', 'sender_id']);
    }

    public function rules(): array
    {
        $templateId = $this->route('id');

        return [
            'template_name' => [
                'sometimes',
                'string',
                'max:120',
                Rule::unique('sms_templates', 'template_name')->ignore($templateId),
            ],
            'sender_id' => 'sometimes|string|max:20',
            'dlt_template_id' => 'nullable|string|max:30',
            'body_template' => 'sometimes|string|max:2000',
            'variable_map' => 'nullable|array',
            'variable_map.*' => 'string|max:50',
            'status' => ['sometimes', 'string', Rule::in([
                SmsTemplate::STATUS_APPROVED,
                SmsTemplate::STATUS_PENDING,
                SmsTemplate::STATUS_INACTIVE,
            ])],
            'is_active' => 'sometimes|boolean',
        ];
    }
}
