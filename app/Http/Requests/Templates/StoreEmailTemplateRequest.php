<?php

namespace App\Http\Requests\Templates;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmailTemplateRequest extends FormRequest
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
            'header' => ['nullable', 'string', 'max:120'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'footer' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:500'],
            'publish_status' => ['nullable', 'string', Rule::in(config('template_variables.publish_statuses', []))],
        ];
    }
}
