<?php

namespace App\Http\Requests\Sms;

use App\Models\SmsSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSmsSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider_name' => ['required', 'string', 'max:120'],
            'api_url' => ['required', 'string', 'max:255', 'url'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'sender_id' => ['required', 'string', 'max:20'],
            'dlt_template_id' => ['nullable', 'string', 'max:30'],
            'mode' => ['required', 'string', Rule::in([SmsSetting::MODE_SIMULATION, SmsSetting::MODE_LIVE])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
