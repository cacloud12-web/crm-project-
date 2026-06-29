<?php

namespace App\Http\Requests\Email;

use App\Models\EmailSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmailSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider_name' => ['sometimes', 'string', 'max:120'],
            'smtp_host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'smtp_port' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'smtp_password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'smtp_encryption' => ['sometimes', 'nullable', 'string', Rule::in(['ssl', 'tls', 'starttls', null])],
            'from_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'from_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mode' => ['sometimes', 'string', Rule::in([EmailSetting::MODE_SIMULATION, EmailSetting::MODE_LIVE])],
        ];
    }
}
