<?php

namespace App\Http\Requests\Email;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmailAccountRequest extends FormRequest
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
            'from_email' => ['required', 'email', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'reply_to_email' => ['nullable', 'email', 'max:255'],
            'provider_name' => ['nullable', 'string', 'max:255'],
            'smtp_host' => ['required', 'string', 'max:255'],
            'smtp_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'smtp_encryption' => ['required', Rule::in(['ssl', 'tls', 'starttls'])],
            'smtp_username' => ['required', 'string', 'max:255'],
            'smtp_password' => ['required', 'string', 'max:500'],
            'imap_enabled' => ['sometimes', 'boolean'],
            'imap_host' => ['required_if:imap_enabled,true', 'nullable', 'string', 'max:255'],
            'imap_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'imap_encryption' => ['nullable', Rule::in(['ssl', 'tls', 'starttls'])],
            'imap_username' => ['required_if:imap_enabled,true', 'nullable', 'string', 'max:255'],
            'imap_password' => ['required_if:imap_enabled,true', 'nullable', 'string', 'max:500'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', Rule::in(['live', 'simulation'])],
            'smtp_verification_token' => ['required', 'string'],
            'imap_verification_token' => ['required_if:imap_enabled,1,true', 'nullable', 'string'],
        ];
    }
}
