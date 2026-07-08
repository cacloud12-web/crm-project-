<?php

namespace App\Http\Requests\Sms;

use App\Http\Requests\Concerns\SanitizesUserText;
use App\Models\SmsTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSmsTemplateRequest extends FormRequest
{
    use SanitizesUserText;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->sanitizeTextFields(['template_name', 'body_template', 'sender_id', 'dlt_template_id']);
    }

    public function rules(): array
    {
        return [
            'template_name' => 'required|string|max:120|unique:sms_templates,template_name',
            'sender_id' => 'required|string|max:20',
            'dlt_template_id' => 'nullable|string|max:30',
            'body_template' => 'required|string|max:2000',
            'variable_map' => 'nullable|array',
            'variable_map.*' => 'string|max:50',
            'status' => ['nullable', 'string', Rule::in([
                SmsTemplate::STATUS_APPROVED,
                SmsTemplate::STATUS_PENDING,
                SmsTemplate::STATUS_INACTIVE,
            ])],
            'is_active' => 'sometimes|boolean',
        ];
    }
}
