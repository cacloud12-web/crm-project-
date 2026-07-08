<?php

namespace App\Http\Requests\WhatsApp;

use Illuminate\Foundation\Http\FormRequest;

class SendWhatsAppTestTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message_template_id' => ['required', 'integer', 'exists:message_templates,id'],
            'mobile_no' => ['nullable', 'string', 'max:20'],
        ];
    }
}
