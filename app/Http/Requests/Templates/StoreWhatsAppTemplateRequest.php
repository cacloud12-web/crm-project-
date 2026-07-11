<?php

namespace App\Http\Requests\Templates;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWhatsAppTemplateRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', 'string', Rule::in(config('template_variables.categories', []))],
            'header' => ['nullable', 'string', 'max:60'],
            'body' => ['required', 'string', 'max:1024'],
            'footer' => ['nullable', 'string', 'max:255'],
            'language_code' => ['nullable', 'string', 'max:12'],
            'publish_status' => ['nullable', 'string', Rule::in(config('template_variables.publish_statuses', []))],
            'meta_api_name' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9_]*$/'],
            'mark_meta_approved' => ['nullable', 'boolean'],
            'meta_template_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
