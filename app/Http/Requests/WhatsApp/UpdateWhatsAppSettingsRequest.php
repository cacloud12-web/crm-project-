<?php

namespace App\Http\Requests\WhatsApp;

use App\Models\WhatsAppSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWhatsAppSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider_name' => ['required', 'string', 'max:120'],
            'phone_number_id' => ['nullable', 'string', 'max:64'],
            'business_account_id' => ['nullable', 'string', 'max:64'],
            'api_version' => ['required', 'string', 'max:20'],
            'mode' => ['required', 'string', Rule::in([WhatsAppSetting::MODE_SIMULATION, WhatsAppSetting::MODE_LIVE])],
            'is_active' => ['sometimes', 'boolean'],
            'access_token' => ['nullable', 'string', 'max:2048'],
            'webhook_verify_token' => ['nullable', 'string', 'max:255'],
            'test_mobile_number' => ['nullable', 'string', 'max:20'],
        ];
    }
}
