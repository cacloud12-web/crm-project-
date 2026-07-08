<?php

namespace App\Http\Requests\WhatsApp;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWhatsAppMessageTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'meta_api_name' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'body_template' => ['sometimes', 'string', 'max:5000'],
            'display_name' => ['sometimes', 'string', 'max:120'],
            'variable_map' => ['sometimes', 'array'],
            'variable_map.*' => ['string', 'max:80'],
        ];
    }
}
