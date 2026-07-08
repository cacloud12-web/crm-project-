<?php

namespace App\Http\Requests\Email;

use Illuminate\Foundation\Http\FormRequest;

class PreviewEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email_template_id' => ['required', 'integer', 'exists:email_templates,id'],
            'lead_id' => ['required', 'integer', 'exists:ca_masters,ca_id'],
        ];
    }
}
