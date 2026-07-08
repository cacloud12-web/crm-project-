<?php

namespace App\Http\Requests\Email;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmailAccountRequest extends FormRequest
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
            'from_email' => ['sometimes', 'email', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'reply_to_email' => ['nullable', 'email', 'max:255'],
            'provider_name' => ['nullable', 'string', 'max:255'],
            'smtp_host' => ['sometimes', 'string', 'max:255'],
            'smtp_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'smtp_encryption' => ['sometimes', Rule::in(['ssl', 'tls', 'starttls'])],
            'smtp_username' => ['sometimes', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:500'],
            'imap_enabled' => ['sometimes', 'boolean'],
            'imap_host' => ['nullable', 'string', 'max:255'],
            'imap_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'imap_encryption' => ['nullable', Rule::in(['ssl', 'tls', 'starttls'])],
            'imap_username' => ['nullable', 'string', 'max:255'],
            'imap_password' => ['nullable', 'string', 'max:500'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', Rule::in(['live', 'simulation'])],
            'smtp_verification_token' => ['required', 'string'],
            'imap_verification_token' => ['nullable', 'string'],
        ];
    }
}
